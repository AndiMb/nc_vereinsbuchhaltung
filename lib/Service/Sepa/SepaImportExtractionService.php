<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Sepa;

use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\BankTxSepaDetail;
use OCA\Vereinsbuchhaltung\Db\BankTxSepaDetailMapper;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Überführt die vom jeweiligen Format-Parser mitgelieferten SEPA-Detail-
 * Rohdaten (`$row['sepaDetails']`, additiv über {@see \OCA\Vereinsbuchhaltung\Service\Statement\RowNormalizer})
 * in `vbh_bank_tx_sepa_details`-Zeilen, sobald die zugehörige
 * {@see BankTransaction} gespeichert ist (Issue #72, Spec §2.2/§5).
 *
 * Läuft nach {@see \OCA\Vereinsbuchhaltung\Service\ImportService::doCommit()}
 * für JEDE neu importierte Bankbuchung, unabhängig von SEPA-Bezug - genau wie
 * die bestehende {@see \OCA\Vereinsbuchhaltung\Service\SepaReturnDetectionService}
 * für das alte Mandatssystem. Rein additiv: `vbh_bank_tx` selbst bleibt
 * unangetastet.
 *
 * Referenzlose Formate (Spec §5 "referenzlose Formate: bestehende
 * Text-Heuristik als dokumentierter Fallback"): liefert der Parser für eine
 * BELASTUNGS-Buchung (Geldabgang) keine strukturierten Detail-Daten, wird der
 * kombinierte Buchungstext/Verwendungszweck nach der App-eigenen
 * End-to-End-/Mandatsreferenz ({@see SepaReference}, dieselbe Fassung, die
 * die Bank bei einer Rückbuchung oft spiegelt) und einem ISO-Rückgabegrund
 * ({@see SepaReturnReasonCodes}) abgesucht - dokumentiert über
 * {@see BankTxSepaDetail::DETECTION_TEXT_HEURISTIC}.
 */
class SepaImportExtractionService {

	public function __construct(
		private BankTxSepaDetailMapper $mapper,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @param list<array<string,mixed>> $rawDetails aus dem Parser, siehe RowNormalizer::build()
	 */
	public function extract(BankTransaction $tx, array $rawDetails): void {
		if ($rawDetails === []) {
			$fallback = $this->textHeuristicFallback($tx);
			if ($fallback !== null) {
				$rawDetails = [$fallback];
			}
		}

		foreach (array_values($rawDetails) as $index => $raw) {
			$detail = new BankTxSepaDetail();
			$detail->setBankTxId((int)$tx->getId());
			$detail->setDetailIndex($index);
			$detail->setEndToEndId($this->nullableString($raw['endToEndId'] ?? null));
			$detail->setMandateReference($this->nullableString($raw['mandateReference'] ?? null));
			$detail->setReturnReasonCode($this->nullableString($raw['returnReasonCode'] ?? null));
			$detail->setReturnReasonText($this->nullableString($raw['returnReasonText'] ?? null));
			$detail->setOriginalAmountCents(isset($raw['originalAmountCents']) ? (int)$raw['originalAmountCents'] : null);
			$detail->setChargesCents(isset($raw['chargesCents']) ? (int)$raw['chargesCents'] : null);
			$detail->setGvc($this->nullableString($raw['gvc'] ?? null));
			$detail->setBatchReference($this->nullableString($raw['batchReference'] ?? null));
			$detail->setAmountCents(isset($raw['amountCents']) ? (int)$raw['amountCents'] : $tx->getAmountCents());
			$detail->setIsReturn((bool)($raw['isReturn'] ?? false));
			$detail->setDetectionSource((string)($raw['detectionSource'] ?? BankTxSepaDetail::DETECTION_STRUCTURED));
			$detail->setStatus(BankTxSepaDetail::STATUS_OPEN);
			$detail->setCreatedAt($this->now());
			$this->mapper->insert($detail);
		}
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function textHeuristicFallback(BankTransaction $tx): ?array {
		// Nur Geldabgänge kommen als Rücklastschrift in Frage - ein Geldeingang
		// ohne Struktur ist entweder ein normaler Beitragseingang (siehe
		// Zuordnungs-Vorschlag für Zahlungseingänge, separater Mechanismus) oder
		// fachlich uninteressant für die SEPA-Erkennung.
		if ($tx->getAmountCents() >= 0) {
			return null;
		}
		$haystack = trim(($tx->getBookingText() ?? '') . ' ' . ($tx->getPurpose() ?? ''));
		if ($haystack === '') {
			return null;
		}
		$endToEndId = SepaReference::findEndToEnd($haystack);
		$mandateReference = SepaReference::findMandate($haystack);
		$reasonCode = SepaReturnReasonCodes::findInText($haystack);
		if ($endToEndId === null && $mandateReference === null && $reasonCode === null) {
			return null;
		}
		return [
			'endToEndId' => $endToEndId,
			'mandateReference' => $mandateReference,
			'returnReasonCode' => $reasonCode,
			'returnReasonText' => null,
			'originalAmountCents' => null,
			'chargesCents' => null,
			'gvc' => null,
			'batchReference' => null,
			'amountCents' => $tx->getAmountCents(),
			'isReturn' => true,
			'detectionSource' => BankTxSepaDetail::DETECTION_TEXT_HEURISTIC,
		];
	}

	private function nullableString(mixed $value): ?string {
		if ($value === null) {
			return null;
		}
		$s = trim((string)$value);
		return $s !== '' ? $s : null;
	}

	private function now(): string {
		return $this->time->getDateTime()->format('Y-m-d H:i:s');
	}
}
