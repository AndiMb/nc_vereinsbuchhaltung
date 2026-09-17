<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Statement;

use OCP\IL10N;

/**
 * Parser für CAMT.053 (ISO 20022, „Bank to Customer Statement").
 *
 * Das ist das eigentliche Standardformat für Kontoauszüge – im Gegensatz zur
 * CSV, deren Spalten jede Bank anders benennt. Wer die Wahl hat, sollte diesen
 * Export nehmen: Vorzeichen, Datum und Zahlungsbeteiligte sind eindeutig
 * ausgezeichnet und müssen nicht erraten werden.
 *
 * Unterstützt werden die gängigen Fassungen camt.053.001.02 bis .08; sie
 * unterscheiden sich in den hier genutzten Elementen nicht.
 */
class Camt053Parser implements StatementParser {

	/**
	 * $l10n ist bewusst optional: der Parser bleibt dadurch ohne laufende
	 * Nextcloud-Instanz mit `new Camt053Parser()` instanziierbar (siehe
	 * tests/unit/Camt053ParserTest.php), übersetzt seine Fehlermeldungen aber,
	 * sobald ihn Nextclouds DI-Container mit einer echten IL10N versorgt.
	 */
	public function __construct(
		private RowNormalizer $normalizer = new RowNormalizer(),
		private ?IL10N $l10n = null,
	) {
	}

	private function msg(string $text): string {
		return $this->l10n !== null ? $this->l10n->t($text) : $text;
	}

	public function sourceKey(): string {
		return 'camt';
	}

	public function supports(string $content): bool {
		$head = substr(ltrim($content), 0, 2048);
		return str_contains($head, '<')
			&& (stripos($head, 'camt.053') !== false
				|| (stripos($head, 'BkToCstmrStmt') !== false));
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function parse(string $content): array {
		$entries = $this->entries($content);
		if ($entries === []) {
			throw new \RuntimeException($this->msg('Die CAMT-Datei enthält keine Buchungen (<Ntry>).'));
		}

		$rows = [];
		foreach ($entries as [$ntry, $ownAccount]) {
			$row = $this->buildRow($ntry, $ownAccount);
			if ($row !== null) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/**
	 * Liefert alle Buchungen samt der IBAN des Kontos, zu dem sie gehören.
	 *
	 * Eine Datei darf mehrere <Stmt> enthalten (mehrere Konten oder Zeiträume in
	 * einem Export). Das Konto steht je <Stmt>, nicht je Buchung – deshalb wird
	 * es hier mitgeführt statt später gesucht.
	 *
	 * @return array<int, array{0: \SimpleXMLElement, 1: ?string}>
	 */
	private function entries(string $content): array {
		$previous = libxml_use_internal_errors(true);
		try {
			// LIBXML_NONET: die Datei kommt von außen; ohne diese Sperre könnte
			// eine präparierte Doctype-Deklaration den Server dazu bringen,
			// Netzwerkverbindungen aufzubauen.
			$xml = simplexml_load_string($content, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
			if ($xml === false) {
				$first = libxml_get_errors()[0] ?? null;
				throw new \RuntimeException($this->msg('Die Datei ist kein gültiges XML')
					. ($first !== null ? ': ' . trim($first->message) : '.'));
			}
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}

		// CAMT-Dateien tragen einen Standard-Namensraum. Ohne registrierten
		// Präfix findet XPath nichts – das ist die häufigste Ursache dafür, dass
		// ein CAMT-Import "keine Buchungen" meldet, obwohl welche drinstehen.
		$namespaces = $xml->getDocNamespaces(false, true);
		$default = $namespaces[''] ?? null;
		$prefix = '';
		if ($default !== null && $default !== '') {
			$xml->registerXPathNamespace('c', $default);
			$prefix = 'c:';
		}

		$statements = $xml->xpath('//' . $prefix . 'Stmt') ?: [];
		$out = [];
		foreach ($statements as $stmt) {
			if ($default !== null && $default !== '') {
				$stmt->registerXPathNamespace('c', $default);
			}
			$ownAccount = $this->firstValue($stmt, './' . $prefix . 'Acct/' . $prefix . 'Id/' . $prefix . 'IBAN')
				?? $this->firstValue($stmt, './' . $prefix . 'Acct/' . $prefix . 'Id/' . $prefix . 'Othr/' . $prefix . 'Id');
			foreach ($stmt->xpath('./' . $prefix . 'Ntry') ?: [] as $ntry) {
				if ($default !== null && $default !== '') {
					$ntry->registerXPathNamespace('c', $default);
				}
				$out[] = [$ntry, $ownAccount];
			}
		}
		return $out;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function buildRow(\SimpleXMLElement $ntry, ?string $ownAccount): ?array {
		$p = $this->prefixOf($ntry);

		// Nur gebuchte Umsätze übernehmen. Vorgemerkte Posten (PDNG) ändern beim
		// endgültigen Buchen Betrag, Datum oder Text – sie kämen sonst zweimal
		// herein, das zweite Mal mit anderem Hash und damit als neue Buchung.
		$status = $this->firstValue($ntry, './' . $p . 'Sts')
			?? $this->firstValue($ntry, './' . $p . 'Sts/' . $p . 'Cd');
		if ($status !== null && strtoupper($status) !== 'BOOK') {
			return null;
		}

		$amount = $this->firstValue($ntry, './' . $p . 'Amt');
		if ($amount === null) {
			return null;
		}
		$cents = $this->toCents($amount);
		if ($cents === null) {
			return null;
		}
		// CdtDbtInd trägt die Richtung; der Betrag selbst ist immer positiv.
		if (strtoupper((string)$this->firstValue($ntry, './' . $p . 'CdtDbtInd')) === 'DBIT') {
			$cents = -$cents;
		}

		$currency = null;
		$amtNode = $ntry->xpath('./' . $p . 'Amt')[0] ?? null;
		if ($amtNode !== null && isset($amtNode['Ccy'])) {
			$currency = (string)$amtNode['Ccy'];
		}

		$incoming = $cents > 0;
		// Bei Geldeingang ist der Zahlungsbeteiligte der Auftraggeber (Dbtr),
		// bei Geldausgang der Empfänger (Cdtr).
		$partyPath = $incoming ? 'Dbtr' : 'Cdtr';
		$party = $this->firstValue($ntry, './/' . $p . $partyPath . '/' . $p . 'Nm');
		$partyIban = $this->firstValue($ntry, './/' . $p . $partyPath . 'Acct/' . $p . 'Id/' . $p . 'IBAN');
		$partyBic = $this->firstValue($ntry, './/' . $p . $partyPath . 'Agt/' . $p . 'FinInstnId/' . $p . 'BIC')
			?? $this->firstValue($ntry, './/' . $p . $partyPath . 'Agt/' . $p . 'FinInstnId/' . $p . 'BICFI');

		return $this->normalizer->build([
			'ownAccount' => $ownAccount,
			'bookingDate' => $this->date($ntry, $p, 'BookgDt'),
			'valueDate' => $this->date($ntry, $p, 'ValDt'),
			'bookingText' => $this->firstValue($ntry, './' . $p . 'AddtlNtryInf')
				?? $this->firstValue($ntry, './/' . $p . 'BkTxCd/' . $p . 'Prtry/' . $p . 'Cd'),
			'purpose' => $this->purpose($ntry, $p),
			'counterparty' => $party,
			'counterpartyIban' => $partyIban,
			'counterpartyBic' => $partyBic,
			'amountCents' => $cents,
			'currency' => $currency,
			'sepaDetails' => $this->sepaDetails($ntry, $p, $cents),
		]);
	}

	/**
	 * SEPA-Detail-Rohdaten je `TxDtls` (Spec §5/§2.2, Issue #72): eine
	 * Sammelbuchung (Einzug oder R-Transaktions-Bündel) bleibt EIN
	 * `vbh_bank_tx`-Umsatz (siehe {@see purpose()}), trägt aber unter
	 * `NtryDtls/TxDtls` eine Zeile je enthaltener Einzeltransaktion mit ihrer
	 * eigenen `EndToEndId`/Mandatsreferenz/Rückgabegrund – genau diese
	 * Struktur wird hier in die additive Nebentabelle
	 * `vbh_bank_tx_sepa_details` überführt, ohne den Bankumsatz selbst
	 * anzufassen.
	 *
	 * `Btch/PmtInfId` und der Geschäftsvorfallcode (`BkTxCd/Prtry/Cd`) stehen
	 * in der Praxis auf `NtryDtls`- bzw. `Ntry`-Ebene (für alle enthaltenen
	 * `TxDtls` gemeinsam) – hier einmal ermittelt und an jede Detail-Zeile
	 * weitergereicht, mit optionalem Override auf `TxDtls`-Ebene.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function sepaDetails(\SimpleXMLElement $ntry, string $p, int $entryTotalCents): array {
		$txDtlsList = $ntry->xpath('.//' . $p . 'NtryDtls/' . $p . 'TxDtls') ?: [];
		if ($txDtlsList === []) {
			return [];
		}

		$entryBatchRef = $this->firstValue($ntry, './/' . $p . 'NtryDtls/' . $p . 'Btch/' . $p . 'PmtInfId');
		$entryGvc = $this->firstValue($ntry, './/' . $p . 'BkTxCd/' . $p . 'Prtry/' . $p . 'Cd');
		// Der Namensraum ist je Dokument fix - einmal ermitteln statt je TxDtls
		// neu (eine Sammelbuchung kann viele TxDtls enthalten).
		$defaultNs = $this->docDefaultNs($ntry);

		$details = [];
		foreach ($txDtlsList as $tx) {
			if ($defaultNs !== null) {
				$tx->registerXPathNamespace('c', $defaultNs);
			}
			$gvc = $this->firstValue($tx, './/' . $p . 'BkTxCd/' . $p . 'Prtry/' . $p . 'Cd') ?? $entryGvc;
			$hasRtrInf = ($tx->xpath('.//' . $p . 'RtrInf') ?: []) !== [];
			// "ist Rückgabe" (Spec §5): camt GVC 109/108 ODER RtrInf vorhanden.
			$isReturn = $hasRtrInf || ($gvc !== null && in_array($gvc, ['108', '109'], true));

			$endToEndId = $this->firstValue($tx, './/' . $p . 'Refs/' . $p . 'EndToEndId');
			$mandateReference = $this->firstValue($tx, './/' . $p . 'Refs/' . $p . 'MndtId');
			$returnReasonCode = $this->firstValue($tx, './/' . $p . 'RtrInf/' . $p . 'Rsn/' . $p . 'Cd');
			$returnReasonText = $this->firstValue($tx, './/' . $p . 'RtrInf/' . $p . 'AddtlInf');
			$originalAmountCents = $this->amountCentsOf($tx, $p, './/' . $p . 'AmtDtls/' . $p . 'TxAmt/' . $p . 'Amt')
				?? $this->amountCentsOf($tx, $p, './/' . $p . 'AmtDtls/' . $p . 'InstdAmt/' . $p . 'Amt');
			$chargesCents = $this->chargesCentsOf($tx, $p);
			$batchReference = $this->firstValue($tx, './/' . $p . 'Refs/' . $p . 'PmtInfId') ?? $entryBatchRef;

			// Jede normale camt-Buchung trägt ihren Verwendungszweck ebenfalls
			// unter NtryDtls/TxDtls (siehe purpose()) - ohne dieses Gatter
			// entstünde für JEDEN Umsatz eine inhaltsleere Detail-Zeile. Ein
			// bloßer GVC allein (z. B. eine gewöhnliche Überweisung) zählt
			// bewusst nicht als "SEPA-relevant" - er dient nur der
			// Rückgabe-Erkennung an einem sonst schon relevanten Datensatz.
			$hasAnyField = $endToEndId !== null || $mandateReference !== null || $returnReasonCode !== null
				|| $returnReasonText !== null || $originalAmountCents !== null || $chargesCents !== null
				|| $batchReference !== null || $isReturn;
			if (!$hasAnyField) {
				continue;
			}

			$details[] = [
				'endToEndId' => $endToEndId,
				'mandateReference' => $mandateReference,
				'returnReasonCode' => $returnReasonCode,
				'returnReasonText' => $returnReasonText,
				'originalAmountCents' => $originalAmountCents,
				'chargesCents' => $chargesCents,
				'gvc' => $gvc,
				'batchReference' => $batchReference,
				'amountCents' => $this->detailAmountCents($tx, $p, $entryTotalCents),
				'isReturn' => $isReturn,
			];
		}
		return $details;
	}

	/** Eigener Betrag/Richtung einer TxDtls-Zeile, mit Rückfall auf den Umsatzbetrag (siehe sepaDetails()). */
	private function detailAmountCents(\SimpleXMLElement $tx, string $p, int $entryTotalCents): int {
		$amount = $this->firstValue($tx, './' . $p . 'Amt');
		if ($amount === null) {
			// Kein eigener Betrag je TxDtls (minimale Exporte, kommt bei echten
			// Sammelbuchungen praktisch nicht vor): der ganze Umsatzbetrag ist
			// die einzig verfügbare Näherung - bei genau einer Detail-Zeile ist
			// das sogar exakt richtig.
			return $entryTotalCents;
		}
		$cents = $this->toCents($amount);
		if ($cents === null) {
			return 0;
		}
		$direction = $this->firstValue($tx, './' . $p . 'CdtDbtInd');
		return $direction !== null && strtoupper($direction) === 'DBIT' ? -$cents : $cents;
	}

	private function amountCentsOf(\SimpleXMLElement $node, string $p, string $xpath): ?int {
		$value = $this->firstValue($node, $xpath);
		return $value !== null ? $this->toCents($value) : null;
	}

	/** Summe aller Entgelt-Einträge (`Chrgs/Rcrd/Amt`), oder die aggregierte Gesamtsumme, falls vorhanden. */
	private function chargesCentsOf(\SimpleXMLElement $tx, string $p): ?int {
		$total = $this->firstValue($tx, './/' . $p . 'Chrgs/' . $p . 'TotalChargesAndTaxAmt');
		if ($total !== null) {
			return $this->toCents($total);
		}
		$records = $tx->xpath('.//' . $p . 'Chrgs/' . $p . 'Rcrd/' . $p . 'Amt') ?: [];
		if ($records === []) {
			return null;
		}
		$sum = 0;
		foreach ($records as $r) {
			$sum += $this->toCents(trim((string)$r)) ?? 0;
		}
		return $sum;
	}

	private function docDefaultNs(\SimpleXMLElement $node): ?string {
		$ns = $node->getDocNamespaces(false, true);
		return ($ns[''] ?? '') !== '' ? $ns[''] : null;
	}

	/**
	 * Verwendungszweck aus allen <Ustrd>-Elementen.
	 *
	 * Sammelbuchungen (eine Lastschrifteinreichung mit vielen Posten) bleiben
	 * bewusst EINE Zeile: die Bank hat auch nur einen Betrag gebucht, und ein
	 * Aufteilen ließe den Kontostand der App vom Bankauszug abweichen. Die Zahl
	 * der Einzelposten wird dem Text vorangestellt, damit erkennbar ist, dass
	 * hier mehrere Zahlungen zusammengefasst sind.
	 */
	private function purpose(\SimpleXMLElement $ntry, string $p): ?string {
		$parts = [];
		foreach ($ntry->xpath('.//' . $p . 'RmtInf/' . $p . 'Ustrd') ?: [] as $node) {
			$text = trim((string)$node);
			if ($text !== '') {
				$parts[] = $text;
			}
		}
		$purpose = trim(implode(' ', $parts));

		$details = count($ntry->xpath('.//' . $p . 'NtryDtls/' . $p . 'TxDtls') ?: []);
		if ($details > 1) {
			$purpose = trim('Sammelbuchung (' . $details . ' Posten) ' . $purpose);
		}
		return $purpose === '' ? null : $purpose;
	}

	/** Datum aus <BookgDt>/<ValDt>, wahlweise als <Dt> oder <DtTm>. */
	private function date(\SimpleXMLElement $ntry, string $p, string $element): ?string {
		$value = $this->firstValue($ntry, './' . $p . $element . '/' . $p . 'Dt')
			?? $this->firstValue($ntry, './' . $p . $element . '/' . $p . 'DtTm');
		if ($value === null) {
			return null;
		}
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
			return null;
		}
		return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $m[0] : null;
	}

	private function toCents(string $value): ?int {
		$v = str_replace(',', '.', trim($value));
		if (!is_numeric($v)) {
			return null;
		}
		return (int)round(((float)$v) * 100);
	}

	private function firstValue(\SimpleXMLElement $node, string $xpath): ?string {
		$hits = $node->xpath($xpath);
		if ($hits === false || $hits === []) {
			return null;
		}
		$value = trim((string)$hits[0]);
		return $value === '' ? null : $value;
	}

	/** 'c:' wenn das Dokument einen Standard-Namensraum hat, sonst ''. */
	private function prefixOf(\SimpleXMLElement $node): string {
		$ns = $node->getDocNamespaces(false, true);
		return ($ns[''] ?? '') !== '' ? 'c:' : '';
	}
}
