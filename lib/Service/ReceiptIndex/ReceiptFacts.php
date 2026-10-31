<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\ReceiptIndex;

/**
 * Was aus einer Belegdatei gelesen wurde (unveränderlich).
 *
 * `refs` enthält alle Referenz-Kerne (Dateiname, Text, E-Rechnung), `nameRefs`
 * nur die aus dem Dateinamen. Der volle PDF-Text wird nicht festgehalten.
 */
final class ReceiptFacts {
	public const SOURCE_NAME = 'name';
	public const SOURCE_TEXT = 'text';
	public const SOURCE_EINVOICE = 'einvoice';

	public const STATUS_OK = 'ok';
	public const STATUS_NO_TEXT = 'no_text';
	public const STATUS_UNREADABLE = 'unreadable';
	public const STATUS_SKIPPED = 'skipped';

	/**
	 * @param list<string> $refs
	 * @param list<string> $nameRefs
	 * @param list<int> $amounts
	 * @param list<string> $dates
	 */
	public function __construct(
		public readonly string $source,
		public readonly string $status,
		public readonly array $refs = [],
		public readonly array $nameRefs = [],
		public readonly array $amounts = [],
		public readonly array $dates = [],
		public readonly ?string $invoiceNo = null,
		public readonly ?int $totalCents = null,
		public readonly ?string $issueDate = null,
		public readonly ?string $vendor = null,
	) {
	}

	/**
	 * @return array{source: string, status: string, refs: list<string>, nameRefs: list<string>, amounts: list<int>, dates: list<string>, invoiceNo: ?string, totalCents: ?int, issueDate: ?string, vendor: ?string}
	 */
	public function toArray(): array {
		return [
			'source' => $this->source,
			'status' => $this->status,
			'refs' => $this->refs,
			'nameRefs' => $this->nameRefs,
			'amounts' => $this->amounts,
			'dates' => $this->dates,
			'invoiceNo' => $this->invoiceNo,
			'totalCents' => $this->totalCents,
			'issueDate' => $this->issueDate,
			'vendor' => $this->vendor,
		];
	}

	/** @param array<string, mixed> $row */
	public static function fromArray(array $row): self {
		return new self(
			(string)($row['source'] ?? self::SOURCE_NAME),
			(string)($row['status'] ?? self::STATUS_OK),
			self::strings($row['refs'] ?? []),
			self::strings($row['nameRefs'] ?? []),
			array_values(array_map('intval', is_array($row['amounts'] ?? null) ? $row['amounts'] : [])),
			self::strings($row['dates'] ?? []),
			isset($row['invoiceNo']) ? (string)$row['invoiceNo'] : null,
			isset($row['totalCents']) ? (int)$row['totalCents'] : null,
			isset($row['issueDate']) ? (string)$row['issueDate'] : null,
			isset($row['vendor']) ? (string)$row['vendor'] : null,
		);
	}

	/** @return list<string> */
	private static function strings(mixed $value): array {
		return is_array($value) ? array_values(array_map('strval', $value)) : [];
	}
}
