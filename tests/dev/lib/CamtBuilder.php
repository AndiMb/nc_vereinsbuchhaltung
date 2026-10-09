<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Dev;

/**
 * Baut einen camt.053-Kontoauszug (ISO 20022, camt.053.001.02) aus Umsätzen –
 * das PHP-Gegenstück zu `camtStatement()`/`collectionEntry()`/`returnEntry()`
 * in tests/e2e/fixtures/nextcloud.mjs.
 *
 * Erzeugt nur, was der Bankabgleich der App liest: die Sammelgutschrift eines
 * Einzugs mit einer `TxDtls`-Zeile je Posten (End-to-End-ID, Mandatsreferenz,
 * Betrag), die Rücklastschrift mit Rückgabegrund, Ursprungsbetrag und Gebühr
 * sowie gewöhnliche Umsätze ohne SEPA-Referenzen. Ob das Ergebnis vom echten
 * {@see \OCA\Vereinsbuchhaltung\Service\Statement\Camt053Parser} gelesen wird,
 * hält tests/unit/DevCamtBuilderTest.php fest.
 *
 * Reine Textarbeit ohne Nextcloud-Abhängigkeit, damit sich der Aufbau ohne
 * laufende Instanz prüfen lässt.
 *
 * @phpstan-type Detail array{
 *     endToEndId?: ?string, mandateReference?: ?string, amountCents?: int,
 *     originalAmountCents?: int, chargesCents?: int, counterparty?: ?string,
 *     counterpartyIban?: ?string, purpose?: ?string,
 *     returnReasonCode?: ?string, returnReasonText?: ?string,
 * }
 * @phpstan-type Entry array{
 *     bookingDate: string, direction: 'CRDT'|'DBIT', amountCents: int,
 *     bookingText?: string, batchReference?: string,
 *     details?: list<Detail>,
 *     counterparty?: ?string, counterpartyIban?: ?string, purpose?: ?string,
 * }
 * @phpstan-type Item array{endToEndId:string, mandateReference:string, amountCents:int, purpose:string}
 */
final class CamtBuilder {

	private function __construct() {
		// Nur statische Hilfen.
	}

	/**
	 * @param string $iban IBAN des eigenen Kontos (`Stmt/Acct/Id/IBAN`)
	 * @param list<Entry> $entries Umsätze in Buchungsreihenfolge
	 * @param string $messageId `GrpHdr/MsgId`
	 * @param string $createdAt `GrpHdr/CreDtTm`, z. B. 2026-10-05T08:00:00
	 * @param string $statementId `Stmt/Id`
	 */
	public static function statement(string $iban, array $entries, string $messageId, string $createdAt, string $statementId): string {
		$body = [];
		foreach ($entries as $entry) {
			$body[] = self::entry($entry);
		}
		return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
			. "<Document xmlns=\"urn:iso:std:iso:20022:tech:xsd:camt.053.001.02\">\n"
			. "\t<BkToCstmrStmt>\n"
			. "\t\t<GrpHdr>\n"
			. "\t\t\t<MsgId>" . self::text($messageId) . "</MsgId>\n"
			. "\t\t\t<CreDtTm>" . self::text($createdAt) . "</CreDtTm>\n"
			. "\t\t</GrpHdr>\n"
			. "\t\t<Stmt>\n"
			. "\t\t\t<Id>" . self::text($statementId) . "</Id>\n"
			. "\t\t\t<Acct><Id><IBAN>" . self::text($iban) . "</IBAN></Id><Ccy>EUR</Ccy></Acct>\n"
			. implode("\n", $body) . "\n"
			. "\t\t</Stmt>\n"
			. "\t</BkToCstmrStmt>\n"
			. "</Document>\n";
	}

	/**
	 * Die Sammelgutschrift eines eigenen Einzugs: ein Umsatz über die Summe, mit
	 * einer Zeile je Posten.
	 *
	 * @param list<Item> $items
	 * @return Entry
	 */
	public static function collectionEntry(string $bookingDate, array $items, string $batchReference, string $bookingText = 'SEPA-LASTSCHRIFT-EINREICHUNG'): array {
		$details = [];
		$total = 0;
		foreach ($items as $item) {
			$total += $item['amountCents'];
			$details[] = [
				'endToEndId' => $item['endToEndId'],
				'mandateReference' => $item['mandateReference'],
				'amountCents' => $item['amountCents'],
				'purpose' => $item['purpose'],
			];
		}
		return [
			'bookingDate' => $bookingDate,
			'direction' => 'CRDT',
			'amountCents' => $total,
			'bookingText' => $bookingText,
			'batchReference' => $batchReference,
			'details' => $details,
		];
	}

	/**
	 * Die Rücklastschrift eines Postens: die Bank belastet den Ursprungsbetrag
	 * plus ihre Gebühr und nennt Rückgabegrund und End-to-End-ID.
	 *
	 * @param Item $item
	 * @return Entry
	 */
	public static function returnEntry(string $bookingDate, array $item, string $reasonCode, ?string $reasonText, ?int $chargesCents): array {
		$detail = [
			'endToEndId' => $item['endToEndId'],
			'mandateReference' => $item['mandateReference'],
			'originalAmountCents' => $item['amountCents'],
			'purpose' => $item['purpose'],
			'returnReasonCode' => $reasonCode,
			'returnReasonText' => $reasonText,
		];
		if ($chargesCents !== null) {
			$detail['chargesCents'] = $chargesCents;
		}
		return [
			'bookingDate' => $bookingDate,
			'direction' => 'DBIT',
			'amountCents' => $item['amountCents'] + ($chargesCents ?? 0),
			'bookingText' => 'LASTSCHRIFT-RUECKGABE',
			'details' => [$detail],
		];
	}

	/**
	 * Ein gewöhnlicher Umsatz ohne SEPA-Referenzen (Überweisung, Spende, Entgelt):
	 * er erzeugt keine Detail-Zeile im Bankabgleich, erscheint aber unter
	 * „Zuzuordnen" bzw. als Zahlungseingang.
	 *
	 * @param 'CRDT'|'DBIT' $direction
	 * @return Entry
	 */
	public static function plainEntry(string $bookingDate, string $direction, int $amountCents, ?string $counterparty, ?string $counterpartyIban, string $purpose, string $bookingText): array {
		return [
			'bookingDate' => $bookingDate,
			'direction' => $direction,
			'amountCents' => $amountCents,
			'bookingText' => $bookingText,
			'counterparty' => $counterparty,
			'counterpartyIban' => $counterpartyIban,
			'purpose' => $purpose,
		];
	}

	/** @param Entry $entry */
	private static function entry(array $entry): string {
		$direction = $entry['direction'];
		$details = $entry['details'] ?? [[
			'counterparty' => $entry['counterparty'] ?? null,
			'counterpartyIban' => $entry['counterpartyIban'] ?? null,
			'purpose' => $entry['purpose'] ?? null,
		]];
		$text = $entry['bookingText'] ?? ($direction === 'CRDT' ? 'GUTSCHRIFT' : 'LASTSCHRIFT');

		$lines = [];
		$lines[] = "\t\t\t<Ntry>";
		$lines[] = "\t\t\t\t<Amt Ccy=\"EUR\">" . self::euros($entry['amountCents']) . '</Amt><CdtDbtInd>' . $direction . '</CdtDbtInd><Sts>BOOK</Sts>';
		$lines[] = "\t\t\t\t<BookgDt><Dt>" . self::text($entry['bookingDate']) . '</Dt></BookgDt><ValDt><Dt>' . self::text($entry['bookingDate']) . '</Dt></ValDt>';
		$lines[] = "\t\t\t\t<NtryDtls>";
		if (isset($entry['batchReference']) && $entry['batchReference'] !== '') {
			$lines[] = "\t\t\t\t\t<Btch><PmtInfId>" . self::text($entry['batchReference']) . '</PmtInfId></Btch>';
		}
		foreach ($details as $detail) {
			$lines[] = "\t\t\t\t\t" . self::detail($detail, $direction);
		}
		$lines[] = "\t\t\t\t</NtryDtls>";
		$lines[] = "\t\t\t\t<AddtlNtryInf>" . self::text($text) . '</AddtlNtryInf>';
		$lines[] = "\t\t\t</Ntry>";
		return implode("\n", $lines);
	}

	/**
	 * Eine Detail-Zeile (`TxDtls`). Nur gesetzte Felder erscheinen im XML – so
	 * lässt sich auch die Zeile ohne End-to-End-ID bauen, die nur über
	 * Mandatsreferenz und Betrag zu einem Posten findet.
	 *
	 * @param Detail $detail
	 * @param 'CRDT'|'DBIT' $direction
	 */
	private static function detail(array $detail, string $direction): string {
		$parts = [];
		$endToEndId = $detail['endToEndId'] ?? null;
		$mandateReference = $detail['mandateReference'] ?? null;
		if ($endToEndId !== null || $mandateReference !== null) {
			$parts[] = '<Refs>'
				. ($endToEndId !== null ? '<EndToEndId>' . self::text($endToEndId) . '</EndToEndId>' : '')
				. ($mandateReference !== null ? '<MndtId>' . self::text($mandateReference) . '</MndtId>' : '')
				. '</Refs>';
		}
		if (isset($detail['amountCents'])) {
			$parts[] = '<Amt Ccy="EUR">' . self::euros($detail['amountCents']) . '</Amt><CdtDbtInd>' . $direction . '</CdtDbtInd>';
		}
		if (isset($detail['originalAmountCents'])) {
			$parts[] = '<AmtDtls><TxAmt><Amt Ccy="EUR">' . self::euros($detail['originalAmountCents']) . '</Amt></TxAmt></AmtDtls>';
		}
		if (isset($detail['chargesCents'])) {
			$parts[] = '<Chrgs><TotalChargesAndTaxAmt Ccy="EUR">' . self::euros($detail['chargesCents']) . '</TotalChargesAndTaxAmt></Chrgs>';
		}
		$counterparty = $detail['counterparty'] ?? null;
		if ($counterparty !== null) {
			$side = $direction === 'CRDT' ? 'Dbtr' : 'Cdtr';
			$counterpartyIban = $detail['counterpartyIban'] ?? null;
			$parts[] = '<RltdPties><' . $side . '><Nm>' . self::text($counterparty) . '</Nm></' . $side . '>'
				. ($counterpartyIban !== null ? '<' . $side . 'Acct><Id><IBAN>' . self::text($counterpartyIban) . '</IBAN></Id></' . $side . 'Acct>' : '')
				. '</RltdPties>';
		}
		$purpose = $detail['purpose'] ?? null;
		if ($purpose !== null) {
			$parts[] = '<RmtInf><Ustrd>' . self::text($purpose) . '</Ustrd></RmtInf>';
		}
		$reasonCode = $detail['returnReasonCode'] ?? null;
		if ($reasonCode !== null) {
			$reasonText = $detail['returnReasonText'] ?? null;
			$parts[] = '<RtrInf><Rsn><Cd>' . self::text($reasonCode) . '</Cd></Rsn>'
				. ($reasonText !== null ? '<AddtlInf>' . self::text($reasonText) . '</AddtlInf>' : '')
				. '</RtrInf>';
		}
		return '<TxDtls>' . implode('', $parts) . '</TxDtls>';
	}

	/** XML-Text mit den fünf Pflicht-Ersetzungen. */
	private static function text(string $text): string {
		return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}

	/** Betrag in camt-Schreibweise: positiv, Punkt als Dezimaltrenner, zwei Stellen. */
	private static function euros(int $cents): string {
		return number_format(abs($cents) / 100, 2, '.', '');
	}
}
