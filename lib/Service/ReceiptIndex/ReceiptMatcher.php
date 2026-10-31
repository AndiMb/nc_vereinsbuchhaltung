<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\ReceiptIndex;

/**
 * Gleicht Buchungen mit indexierten Belegdateien ab (rein, ohne Datenbank).
 *
 * Signale: `ref` (Referenz-Kern), `amount` (Betrag), `date` (Belegdatum im
 * Fenster Buchung − 90 bis + 10 Tage). Stufen: "sure" (ref und amount, und
 * eindeutig), "possible" (ref, oder amount und date), sonst kein Vorschlag.
 *
 * @phpstan-type Reason array{kind: string, value?: string, where?: string, cents?: int, date?: string}
 * @phpstan-type BookingInput array{key: string, date: string, amountCents: int, description: string, documentRef: string}
 * @phpstan-type FileInput array{fileId: int, mtime: int, facts: ReceiptFacts}
 * @phpstan-type Suggestion array{fileId: int, level: 'sure'|'possible', reasons: list<Reason>}
 */
final class ReceiptMatcher {
	public const MAX_SUGGESTIONS = 5;
	public const DAYS_BEFORE = 90;
	public const DAYS_AFTER = 10;

	public const LEVEL_SURE = 'sure';
	public const LEVEL_POSSIBLE = 'possible';

	/**
	 * @param list<BookingInput> $bookings
	 * @param list<FileInput> $files nur offene Dateien
	 * @return array<array-key, list<Suggestion>> je Buchungsschlüssel
	 */
	public function match(array $bookings, array $files): array {
		/** @var array<int, FileInput> $byId */
		$byId = [];
		/** @var array<string, list<int>> $refIndex */
		$refIndex = [];
		/** @var array<int, list<int>> $amountIndex */
		$amountIndex = [];
		foreach ($files as $file) {
			$id = $file['fileId'];
			$byId[$id] = $file;
			foreach (array_unique($file['facts']->refs) as $core) {
				$refIndex[(string)$core][] = $id;
			}
			$amounts = $file['facts']->totalCents !== null ? [$file['facts']->totalCents] : $file['facts']->amounts;
			foreach (array_unique($amounts) as $cents) {
				$amountIndex[$cents][] = $id;
			}
		}

		// Durchlauf 1: Kandidaten je Buchung
		/** @var array<string, list<array{fileId: int, mtime: int, sure: bool, ref: bool, date: bool, reasons: list<Reason>}>> $candidates */
		$candidates = [];
		/** @var array<int, int> $sureBookingsPerFile */
		$sureBookingsPerFile = [];
		foreach ($bookings as $booking) {
			$key = $booking['key'];
			$list = $this->candidatesFor($booking, $byId, $refIndex, $amountIndex);
			$candidates[$key] = $list;
			foreach ($list as $c) {
				if ($c['sure']) {
					$sureBookingsPerFile[$c['fileId']] = ($sureBookingsPerFile[$c['fileId']] ?? 0) + 1;
				}
			}
		}

		// Durchlauf 2: Eindeutigkeit prüfen, abstufen, sortieren
		$result = [];
		foreach ($candidates as $key => $list) {
			$sureCount = count(array_filter($list, static fn (array $c): bool => $c['sure']));
			$ranked = [];
			foreach ($list as $c) {
				$sure = $c['sure'] && $sureCount === 1 && $sureBookingsPerFile[$c['fileId']] === 1;
				$rank = $sure ? 0 : ($c['ref'] ? ($c['date'] ? 1 : 2) : 3);
				$ranked[] = [
					'rank' => $rank,
					'mtime' => $c['mtime'],
					'suggestion' => [
						'fileId' => $c['fileId'],
						'level' => $sure ? self::LEVEL_SURE : self::LEVEL_POSSIBLE,
						'reasons' => $c['reasons'],
					],
				];
			}
			usort($ranked, static function (array $a, array $b): int {
				return [$a['rank'], $b['mtime'], $b['suggestion']['fileId']]
					<=> [$b['rank'], $a['mtime'], $a['suggestion']['fileId']];
			});
			$ranked = array_slice($ranked, 0, self::MAX_SUGGESTIONS);
			$result[$key] = array_values(array_map(static fn (array $r): array => $r['suggestion'], $ranked));
		}
		return $result;
	}

	/**
	 * @param BookingInput $booking
	 * @param array<int, FileInput> $byId
	 * @param array<string, list<int>> $refIndex
	 * @param array<int, list<int>> $amountIndex
	 * @return list<array{fileId: int, mtime: int, sure: bool, ref: bool, date: bool, reasons: list<Reason>}>
	 */
	private function candidatesFor(array $booking, array $byId, array $refIndex, array $amountIndex): array {
		// Kern => Originalausschnitt; Buchungstext vor Belegnummer
		$bookingRefs = ReceiptFeatures::refsWithSource($booking['description']);
		foreach (ReceiptFeatures::refsWithSource($booking['documentRef']) as $core => $source) {
			$bookingRefs[(string)$core] ??= $source;
		}

		/** @var array<int, array<string, string>> $refHits fileId => Kern => Ausschnitt */
		$refHits = [];
		foreach ($bookingRefs as $core => $source) {
			foreach ($refIndex[(string)$core] ?? [] as $id) {
				$refHits[$id][(string)$core] = $source;
			}
		}

		$cents = abs($booking['amountCents']);
		$amountHits = $cents > 0 ? ($amountIndex[$cents] ?? []) : [];
		$ids = array_unique(array_merge(array_keys($refHits), $amountHits));

		$window = $this->window($booking['date']);
		$amountSet = array_flip($amountHits);

		$out = [];
		foreach ($ids as $id) {
			$file = $byId[$id];
			$facts = $file['facts'];
			$hasRef = isset($refHits[$id]);
			$hasAmount = isset($amountSet[$id]);
			$matchedDate = $this->matchingDate($facts, $window);

			if (!$hasRef && !($hasAmount && $matchedDate !== null)) {
				continue;
			}

			$reasons = [];
			foreach ($refHits[$id] ?? [] as $core => $source) {
				$reasons[] = ['kind' => 'ref', 'value' => $source, 'where' => $this->refSource((string)$core, $facts)];
			}
			if ($hasAmount) {
				$reasons[] = ['kind' => 'amount', 'cents' => $cents];
			}
			if ($matchedDate !== null) {
				$reasons[] = ['kind' => 'date', 'date' => $matchedDate];
			}

			$out[] = [
				'fileId' => $id,
				'mtime' => $file['mtime'],
				'sure' => $hasRef && $hasAmount,
				'ref' => $hasRef,
				'date' => $matchedDate !== null,
				'reasons' => $reasons,
			];
		}
		return $out;
	}

	private function refSource(string $core, ReceiptFacts $facts): string {
		if ($facts->invoiceNo !== null && preg_replace('/\D/', '', $facts->invoiceNo) === $core) {
			return ReceiptFacts::SOURCE_EINVOICE;
		}
		if (in_array($core, $facts->nameRefs, false)) {
			return ReceiptFacts::SOURCE_NAME;
		}
		return ReceiptFacts::SOURCE_TEXT;
	}

	/**
	 * @return array{string, string}|null ISO-Grenzen (von, bis), null bei ungültigem Datum
	 */
	private function window(string $date): ?array {
		try {
			$d = new \DateTimeImmutable($date);
		} catch (\Exception) {
			return null;
		}
		return [
			$d->modify('-' . self::DAYS_BEFORE . ' days')->format('Y-m-d'),
			$d->modify('+' . self::DAYS_AFTER . ' days')->format('Y-m-d'),
		];
	}

	/**
	 * @param array{string, string}|null $window
	 */
	private function matchingDate(ReceiptFacts $facts, ?array $window): ?string {
		if ($window === null) {
			return null;
		}
		$dates = $facts->dates;
		if ($facts->issueDate !== null) {
			$dates[] = $facts->issueDate;
		}
		foreach ($dates as $date) {
			if ($date >= $window[0] && $date <= $window[1]) {
				return $date;
			}
		}
		return null;
	}
}
