<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\ReceiptIndex;

use OCA\Vereinsbuchhaltung\Service\EInvoice\EInvoiceException;
use OCA\Vereinsbuchhaltung\Service\EInvoice\InvoiceSuiteEInvoiceWriter;
use PrinsFrank\PdfParser\PdfParser;

/**
 * Liest eine einzelne Belegdatei und liefert die Merkmale für die
 * Belegvorschläge: Referenz-Kerne aus dem Dateinamen, bei PDFs bis 15 MB aus
 * der E-Rechnung (ZUGFeRD) oder aus der Textebene. Bilder und große Dateien
 * zählen nur über den Dateinamen. Ein Fehler beim Lesen wirft nie, er ergibt
 * den Status `unreadable`.
 */
class ReceiptReader {
	/** Ändern sich die Leseregeln, hochzählen: dann werden alle Belege neu gelesen. */
	public const VERSION = 1;

	public const MAX_PDF_BYTES = 15 * 1024 * 1024;
	public const MAX_TEXT_CHARS = 200000;
	public const MIN_VISIBLE_CHARS = 20;
	public const MAX_VENDOR_CHARS = 120;

	private const NS_RSM = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';
	private const NS_RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
	private const NS_UDT = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';

	/** @var \Closure(): \DateTimeImmutable */
	private \Closure $today;

	/** @param ?\Closure(): \DateTimeImmutable $today für Tests; sonst die aktuelle Zeit */
	public function __construct(
		private ?InvoiceSuiteEInvoiceWriter $eInvoice = null,
		?\Closure $today = null,
	) {
		$this->today = $today ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('today');
	}

	/**
	 * @param \Closure(): string $content liefert die Bytes; wird nur bei PDFs bis 15 MB aufgerufen
	 */
	public function read(string $fileName, string $mime, int $size, \Closure $content): ReceiptFacts {
		$nameRefs = ReceiptFeatures::refs(self::stripExtension($fileName));
		$isPdf = $mime === 'application/pdf' || strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) === 'pdf';

		if (!$isPdf || $size > self::MAX_PDF_BYTES) {
			return new ReceiptFacts(ReceiptFacts::SOURCE_NAME, ReceiptFacts::STATUS_SKIPPED, $nameRefs, $nameRefs);
		}

		try {
			$bytes = $content();
			return $this->readEInvoice($bytes, $nameRefs) ?? $this->readText($bytes, $nameRefs);
		} catch (\Throwable) {
			return new ReceiptFacts(ReceiptFacts::SOURCE_NAME, ReceiptFacts::STATUS_UNREADABLE, $nameRefs, $nameRefs);
		}
	}

	/**
	 * @param list<string> $nameRefs
	 */
	private function readEInvoice(string $bytes, array $nameRefs): ?ReceiptFacts {
		try {
			$xml = ($this->eInvoice ??= new InvoiceSuiteEInvoiceWriter())->extractXml($bytes);
		} catch (EInvoiceException) {
			return null; // kein eingebettetes XML: weiter mit der Textebene
		}

		$doc = @simplexml_load_string($xml);
		if ($doc === false) {
			return null;
		}
		$doc->registerXPathNamespace('rsm', self::NS_RSM);
		$doc->registerXPathNamespace('ram', self::NS_RAM);
		$doc->registerXPathNamespace('udt', self::NS_UDT);

		$invoiceNo = self::first($doc, '//rsm:ExchangedDocument/ram:ID');
		$issueDate = self::isoDate(self::first($doc, '//rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString'));
		$gross = self::first($doc, '//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:GrandTotalAmount');
		$totalCents = $gross !== null && is_numeric($gross) ? (int)round((float)$gross * 100) : null;
		$vendor = self::first($doc, '//ram:SellerTradeParty/ram:Name');
		if ($vendor !== null) {
			$vendor = mb_substr($vendor, 0, self::MAX_VENDOR_CHARS);
		}
		if ($invoiceNo === null && $totalCents === null) {
			return null;
		}

		$refs = $nameRefs;
		foreach ($invoiceNo !== null ? ReceiptFeatures::refs($invoiceNo) : [] as $core) {
			if (!in_array($core, $refs, true)) {
				$refs[] = $core;
			}
		}
		$amounts = $totalCents !== null && $totalCents > 0 ? [$totalCents] : [];

		return new ReceiptFacts(
			ReceiptFacts::SOURCE_EINVOICE,
			ReceiptFacts::STATUS_OK,
			array_slice($refs, 0, ReceiptFeatures::MAX_REFS),
			$nameRefs,
			$amounts,
			$issueDate !== null ? [$issueDate] : [],
			$invoiceNo !== null ? mb_substr($invoiceNo, 0, 64) : null,
			$totalCents,
			$issueDate,
			$vendor,
		);
	}

	/**
	 * @param list<string> $nameRefs
	 */
	private function readText(string $bytes, array $nameRefs): ReceiptFacts {
		$text = (new PdfParser())->parseString($bytes)->getText();
		$text = mb_substr($text, 0, self::MAX_TEXT_CHARS);

		if (mb_strlen((string)preg_replace('/\s+/u', '', $text)) < self::MIN_VISIBLE_CHARS) {
			return new ReceiptFacts(ReceiptFacts::SOURCE_NAME, ReceiptFacts::STATUS_NO_TEXT, $nameRefs, $nameRefs);
		}

		$refs = $nameRefs;
		foreach (ReceiptFeatures::refs($text) as $core) {
			if (!in_array($core, $refs, true)) {
				$refs[] = $core;
			}
		}

		return new ReceiptFacts(
			ReceiptFacts::SOURCE_TEXT,
			ReceiptFacts::STATUS_OK,
			array_slice($refs, 0, ReceiptFeatures::MAX_REFS),
			$nameRefs,
			ReceiptFeatures::amounts($text),
			ReceiptFeatures::dates($text, ($this->today)()),
		);
	}

	private static function stripExtension(string $fileName): string {
		$dot = strrpos($fileName, '.');
		return $dot === false || $dot === 0 ? $fileName : substr($fileName, 0, $dot);
	}

	private static function first(\SimpleXMLElement $doc, string $xpath): ?string {
		$nodes = $doc->xpath($xpath);
		if (!is_array($nodes) || $nodes === []) {
			return null;
		}
		$value = trim((string)$nodes[0]);
		return $value === '' ? null : $value;
	}

	/** Format 102 (JJJJMMTT) → ISO; sonst null. */
	private static function isoDate(?string $raw): ?string {
		if ($raw === null || preg_match('/^(\d{4})(\d{2})(\d{2})$/', $raw, $m) !== 1 || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
			return null;
		}
		return $m[1] . '-' . $m[2] . '-' . $m[3];
	}
}
