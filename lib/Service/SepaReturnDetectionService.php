<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\SepaBatchItem;
use OCA\Vereinsbuchhaltung\Db\SepaBatchItemMapper;
use OCA\Vereinsbuchhaltung\Db\SepaMandateMapper;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaReference;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Erkennt zurückgegebene SEPA-Lastschriften im Kontoauszugs-Import und
 * verknüpft sie mit dem passenden {@see SepaBatchItem}. Läuft für JEDE
 * importierte Bankbuchung mit (siehe ImportService::doCommit()), meldet aber
 * nur dann etwas, wenn sowohl die Erkennungsmerkmale als auch eine passende
 * offene Sammeleinzug-Zeile gefunden wurden – rein additiv, betrifft Vereine
 * ohne SEPA-Modul überhaupt nicht (findUnreturnedByMandate()/
 * findUnreturnedByAmount() sind dann immer leer).
 *
 * Bewusst kein Ersatz für die normale Kontenzuordnung: die zurückgebuchte
 * Bankbuchung selbst bleibt wie jede andere unzugeordnet und muss weiterhin
 * auf ein Konto gebucht werden (z. B. Forderungsausfälle/Bankgebühren) –
 * dieser Dienst markiert nur die Sammeleinzug-Zeile als zurückgebucht und
 * öffnet den ursprünglichen offenen Posten wieder. Am Mandat ändert sich
 * nichts: ob der Verein es nach einer Rückbuchung widerruft, ist eine
 * Entscheidung, die ihm gehört und nicht dem Import.
 *
 * Die Erkennung ist Best-Effort auf Basis von Verwendungszweck/Buchungstext
 * (CSV- und CAMT-Exporte unterscheiden sich hier stark zwischen Banken) und
 * arbeitet in drei Stufen abnehmender Sicherheit:
 *
 * 1. eigene End-to-End-Referenz im Text – eindeutig, die Bank spiegelt sie oft;
 * 2. eigene Mandatsreferenz plus passender Betrag – nahezu eindeutig;
 * 3. Betrag plus IBAN unter den noch offenen Zeilen – nur ein Indiz.
 *
 * Für Stufe 3 reicht ein deutsches Stichwort wie „Rücklastschrift" allein
 * nicht: dasselbe Wort steht auch in der Gebührenbuchung, die die Bank für
 * eine Rückbuchung erhebt („Entgelt Rücklastschrift"), und deren Betrag kann
 * zufällig passen. Dort wird ein echter ISO-Rückgabegrund verlangt. Eine
 * falsch erkannte Rückbuchung lässt sich außerdem zurücknehmen, siehe
 * {@see SepaBatchService::revertReturn()}.
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
			// War der Einzug schon als ausgeführt verbucht, hängt am Posten die
			// Sammelgutschrift als Zahlungsnachweis. Die gilt für ihn nicht mehr.
			$openItem->setPaidJournalId(null);
			$this->openItemMapper->update($openItem);
		} catch (DoesNotExistException) {
			// Offener Posten wurde inzwischen gelöscht - das Mandat/die Einzugs-
			// zeile trotzdem als zurückgebucht markieren, das ist unabhängig.
		}

		$this->rewindMandateUsage($item->getMandateId());

		$this->audit->log('SEPA-Rücklastschrift erkannt', 'sepa_batch_item', $item->getId(), [
			'endToEndId' => $item->getEndToEndId(),
			'grund' => $item->getReturnReason(),
			'betrag' => $item->getAmountCents() / 100,
		]);
		return true;
	}

	/**
	 * Setzt `last_used_date` des Mandats auf den letzten Einzug zurück, der
	 * tatsächlich Bestand hat.
	 *
	 * Ohne das bliebe ein zurückgegebener **Ersteinzug** als Benutzung stehen:
	 * das Mandat gälte als eingelöst, und der nächste Versuch liefe als RCUR,
	 * obwohl nie ein Einzug durchging. Manche Institute weisen das zurück.
	 * Beim Verwerfen eines Einzugs wird derselbe Stand schon länger korrekt
	 * zurückgedreht (SepaBatchService::deleteBatch()) – bei einer
	 * Rücklastschrift fehlte es.
	 */
	private function rewindMandateUsage(int $mandateId): void {
		try {
			$mandate = $this->mandateMapper->find($mandateId);
		} catch (DoesNotExistException) {
			return;
		}
		$mandate->setLastUsedDate($this->itemMapper->findLastExecutionDateByMandate($mandateId));
		$this->mandateMapper->update($mandate);
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

	/**
	 * Sucht einen ISO-Rückgabegrund als eigenständiges Wort.
	 *
	 * Die Abgrenzung ist strenger als ein bloßes \b: das würde auch in
	 * „Rechnung AC01-2026" anschlagen, weil der Bindestrich als Wortgrenze
	 * zählt. Ein Rückgabegrund steht im Bankdeutsch für sich, nie als Teil
	 * einer längeren Kennung.
	 */
	private function extractReasonCode(string $haystack): ?string {
		foreach (self::REASON_CODES as $code) {
			if (preg_match('/(?<![A-Z0-9-])' . $code . '(?![A-Z0-9-])/', $haystack)) {
				return $code;
			}
		}
		return null;
	}

	private function resolveItem(BankTransaction $tx, string $haystack): ?SepaBatchItem {
		$amountCents = abs($tx->getAmountCents());

		$endToEndId = SepaReference::findEndToEnd($haystack);
		if ($endToEndId !== null) {
			$item = $this->itemMapper->findByEndToEndId($endToEndId);
			if ($item !== null && in_array($item->getStatus(), SepaBatchItem::OPEN_STATUSES, true)) {
				return $item;
			}
		}

		$mandateReference = SepaReference::findMandate($haystack);
		if ($mandateReference !== null) {
			$mandate = $this->mandateMapper->findByReference($mandateReference);
			if ($mandate !== null) {
				foreach ($this->itemMapper->findUnreturnedByMandate($mandate->getId()) as $candidate) {
					if ($candidate->getAmountCents() === $amountCents) {
						return $candidate;
					}
				}
			}
		}

		// Schwächste Stufe: keine eigene Referenz im Text, nur Betrag und IBAN.
		// Hier verlangen wir einen echten ISO-Rückgabegrund – ein deutsches
		// Stichwort allein trifft sonst auch die Gebührenbuchung zur Rückgabe.
		if ($tx->getCounterpartyIban() !== null && $this->extractReasonCode($haystack) !== null) {
			foreach ($this->itemMapper->findUnreturnedByAmount($amountCents) as $candidate) {
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
