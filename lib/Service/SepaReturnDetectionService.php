<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\SepaBatchItem;
use OCA\Vereinsbuchhaltung\Db\SepaBatchItemMapper;
use OCA\Vereinsbuchhaltung\Db\SepaMandateMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Erkennt zurückgegebene SEPA-Lastschriften im Kontoauszugs-Import und
 * verknüpft sie mit dem passenden {@see SepaBatchItem}. Läuft für JEDE
 * importierte Bankbuchung mit (siehe ImportService::doCommit()), meldet aber
 * nur dann etwas, wenn sowohl die Erkennungsmerkmale als auch eine passende
 * offene Sammeleinzug-Zeile gefunden wurden – rein additiv, betrifft Vereine
 * ohne SEPA-Modul überhaupt nicht (findPendingByMandate()/findPendingByAmount()
 * sind dann immer leer).
 *
 * Bewusst kein Ersatz für die normale Kontenzuordnung: die zurückgebuchte
 * Bankbuchung selbst bleibt wie jede andere unzugeordnet und muss weiterhin
 * auf ein Konto gebucht werden (z. B. Forderungsausfälle/Bankgebühren) –
 * dieser Dienst öffnet nur den ursprünglichen offenen Posten wieder und
 * markiert das Mandat als zurückgebucht.
 *
 * Die Erkennung ist Best-Effort auf Basis von Verwendungszweck/Buchungstext
 * (CSV- und CAMT-Exporte unterscheiden sich hier stark zwischen Banken):
 * zuerst wird nach der eigenen Mandats- oder End-to-End-Referenz gesucht
 * (hohe Sicherheit, die Bank spiegelt sie bei einer Rückbuchung oft), sonst
 * nach Betrag + IBAN unter den noch offenen Sammeleinzug-Zeilen (schwächer,
 * aber besser als nichts).
 */
class SepaReturnDetectionService {

	/**
	 * ISO-20022-Rückgabegründe für SEPA-Lastschriften, wie sie Banken im
	 * Verwendungszweck/Buchungstext einer Rücklastschrift häufig mitgeben.
	 */
	private const REASON_CODES = [
		'AC01', 'AC04', 'AC06', 'AC13', 'AG01', 'AG02', 'AM04', 'AM05',
		'BE01', 'BE05', 'FF01', 'MD01', 'MD02', 'MD06', 'MD07', 'MS02',
		'MS03', 'RC01', 'RR01', 'RR02', 'RR03', 'RR04', 'SL01', 'SL02',
		'SL11', 'SL12', 'SL13', 'SL14', 'TM01',
	];

	/** Deutsche Klartext-Marker, falls die Bank keinen Reason-Code ausgibt. */
	private const TEXT_MARKERS = [
		'rücklastschrift', 'ruecklastschrift', 'retourenbelastung',
		'r-transaktion', 'storno lastschrift', 'return dd',
	];

	public function __construct(
		private SepaBatchItemMapper $itemMapper,
		private SepaMandateMapper $mandateMapper,
		private OpenItemMapper $openItemMapper,
		private AuditService $audit,
	) {
	}

	/**
	 * @return bool ob die Buchung als Rücklastschrift erkannt UND einer
	 *         Sammeleinzug-Zeile zugeordnet werden konnte
	 */
	public function detect(BankTransaction $tx): bool {
		// Eine Rücklastschrift belastet das Vereinskonto: negativer Betrag.
		if ($tx->getAmountCents() >= 0) {
			return false;
		}
		$haystack = trim(($tx->getBookingText() ?? '') . ' ' . ($tx->getPurpose() ?? ''));
		if ($haystack === '' || !$this->looksLikeReturn($haystack)) {
			return false;
		}

		$item = $this->resolveItem($tx, $haystack);
		if ($item === null) {
			return false;
		}

		$item->setStatus('returned');
		$item->setReturnReason($this->extractReasonCode($haystack));
		$item->setReturnDate($tx->getBookingDate());
		$this->itemMapper->update($item);

		try {
			$openItem = $this->openItemMapper->find($item->getOpenItemId());
			$openItem->setStatus('open');
			$this->openItemMapper->update($openItem);
		} catch (DoesNotExistException) {
			// Offener Posten wurde inzwischen gelöscht - das Mandat/die Einzugs-
			// zeile trotzdem als zurückgebucht markieren, das ist unabhängig.
		}

		$this->audit->log('SEPA-Rücklastschrift erkannt', 'sepa_batch_item', $item->getId(), [
			'endToEndId' => $item->getEndToEndId(),
			'grund' => $item->getReturnReason(),
			'betrag' => $item->getAmountCents() / 100,
		]);
		return true;
	}

	private function looksLikeReturn(string $haystack): bool {
		return $this->extractReasonCode($haystack) !== null || $this->containsTextMarker($haystack);
	}

	private function containsTextMarker(string $haystack): bool {
		$lower = mb_strtolower($haystack);
		foreach (self::TEXT_MARKERS as $marker) {
			if (str_contains($lower, $marker)) {
				return true;
			}
		}
		return false;
	}

	private function extractReasonCode(string $haystack): ?string {
		foreach (self::REASON_CODES as $code) {
			if (preg_match('/\b' . $code . '\b/', $haystack)) {
				return $code;
			}
		}
		return null;
	}

	private function resolveItem(BankTransaction $tx, string $haystack): ?SepaBatchItem {
		$amountCents = abs($tx->getAmountCents());

		if (preg_match('/\bE2E-\d{8}-\d{6}-[0-9A-F]{8}\b/', $haystack, $m)) {
			$item = $this->itemMapper->findByEndToEndId($m[0]);
			if ($item !== null && $item->getStatus() === 'pending') {
				return $item;
			}
		}

		if (preg_match('/\bM\d{8}-[0-9A-F]{6}\b/', $haystack, $m)) {
			$mandate = $this->mandateMapper->findByReference($m[0]);
			if ($mandate !== null) {
				foreach ($this->itemMapper->findPendingByMandate($mandate->getId()) as $candidate) {
					if ($candidate->getAmountCents() === $amountCents) {
						return $candidate;
					}
				}
			}
		}

		if ($tx->getCounterpartyIban() !== null) {
			foreach ($this->itemMapper->findPendingByAmount($amountCents) as $candidate) {
				try {
					$mandate = $this->mandateMapper->find($candidate->getMandateId());
				} catch (DoesNotExistException) {
					continue;
				}
				if ($mandate->getIban() === $tx->getCounterpartyIban()) {
					return $candidate;
				}
			}
		}

		return null;
	}
}
