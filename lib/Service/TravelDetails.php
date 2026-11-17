<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Service\Text\Utf8;
use OCP\IL10N;

/**
 * Angaben einer Reisekostenabrechnung (Issue #353, Stufe 1): Ziel, Beginn und
 * Ende der Reise und die Positionen – Fahrtkosten (Verkehrsmittel, Strecke,
 * wahlweise Kilometer, Betrag) und Parkgebühren. Erstattet werden nur
 * tatsächliche Kosten, die das Mitglied als Betrag einträgt; Pauschalen und
 * Kilometersätze folgen in späteren Stufen.
 *
 * Die Angaben liegen als JSON am Antrag (`travel_details`) und werden nach dem
 * Einreichen nie geändert: korrigiert die Verwaltung den Betrag, bleiben die
 * Positionen so, wie das Mitglied sie eingetragen hat.
 *
 * Zwei Lesarten: {@see fromInput()} mit `$strict` prüft alles, was zum
 * Einreichen nötig ist; ohne `$strict` (Entwurf) bleibt Unvollständiges
 * stehen, nur Form und Längen werden geprüft.
 */
final class TravelDetails {

	public const TYPE_TRAVEL = 'travel';
	public const TYPE_PARKING = 'parking';
	public const TRANSPORTS = ['car', 'public', 'bike', 'other'];
	public const MAX_ITEMS = 20;
	private const MAX_TEXT = 255;
	private const MAX_AMOUNT_CENTS = 99999999;

	/**
	 * @param list<array{type: string, transport: ?string, route: ?string, distanceKm: ?float, note: ?string, amountCents: ?int}> $items
	 */
	private function __construct(
		public readonly ?string $destination,
		public readonly ?string $start,
		public readonly ?string $end,
		public readonly array $items,
	) {
	}

	/**
	 * @param mixed $input dekodiertes JSON aus dem Formular
	 * @throws \InvalidArgumentException bei ungültigen Angaben; mit `$strict` auch bei fehlenden
	 */
	public static function fromInput(mixed $input, IL10N $l10n, bool $strict, ?string $today = null): self {
		if (!is_array($input)) {
			throw new \InvalidArgumentException($l10n->t('Die Reiseangaben fehlen oder sind unlesbar.'));
		}
		$destination = self::text($input['destination'] ?? null, $l10n);
		$start = self::dateTime($input['start'] ?? null, $l10n);
		$end = self::dateTime($input['end'] ?? null, $l10n);
		$rawItems = $input['items'] ?? [];
		if (!is_array($rawItems) || !array_is_list($rawItems)) {
			throw new \InvalidArgumentException($l10n->t('Die Reiseangaben fehlen oder sind unlesbar.'));
		}
		if (count($rawItems) > self::MAX_ITEMS) {
			throw new \InvalidArgumentException($l10n->t('Zu viele Positionen (höchstens %d).', [self::MAX_ITEMS]));
		}
		$items = [];
		foreach ($rawItems as $raw) {
			$items[] = self::item($raw, $l10n, $strict);
		}

		if ($start !== null && $end !== null && $end < $start) {
			throw new \InvalidArgumentException($l10n->t('Das Ende der Reise liegt vor ihrem Beginn.'));
		}
		if ($strict) {
			if ($destination === null) {
				throw new \InvalidArgumentException($l10n->t('Bitte das Reiseziel angeben.'));
			}
			if ($start === null || $end === null) {
				throw new \InvalidArgumentException($l10n->t('Bitte Beginn und Ende der Reise angeben.'));
			}
			if ($today !== null && substr($start, 0, 10) > $today) {
				throw new \InvalidArgumentException($l10n->t('Die Reise darf nicht in der Zukunft beginnen.'));
			}
			if ($items === []) {
				throw new \InvalidArgumentException($l10n->t('Bitte mindestens eine Position (Fahrtkosten oder Parkgebühren) angeben.'));
			}
		}
		return new self($destination, $start, $end, $items);
	}

	/**
	 * Das gespeicherte JSON als Array; unlesbares wird zu null statt zum Fehler.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function decode(?string $json): ?array {
		if ($json === null || $json === '') {
			return null;
		}
		$decoded = json_decode($json, true);
		return is_array($decoded) ? $decoded : null;
	}

	/** Summe aller Positionsbeträge in Cent. */
	public function totalCents(): int {
		return array_sum(array_map(static fn (array $item): int => $item['amountCents'] ?? 0, $this->items));
	}

	/** Der Tag des Reisebeginns – er gilt als Ausgabedatum des Antrags. */
	public function startDate(): ?string {
		return $this->start !== null ? substr($this->start, 0, 10) : null;
	}

	/** @return array<string, mixed> */
	public function toArray(): array {
		return [
			'destination' => $this->destination,
			'start' => $this->start,
			'end' => $this->end,
			'items' => $this->items,
		];
	}

	public function toJson(): string {
		return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
	}

	/**
	 * Für die Anonymisierung: Strecken und Notizen können Adressen enthalten
	 * (Wohnort als Startpunkt). Ziel, Zeiten, Verkehrsmittel und Beträge
	 * bleiben als Beleg.
	 */
	public static function redactJson(?string $json): ?string {
		$data = self::decode($json);
		if ($data === null) {
			return $json;
		}
		foreach ($data['items'] ?? [] as $i => $item) {
			if (is_array($item)) {
				$data['items'][$i]['route'] = null;
				$data['items'][$i]['note'] = null;
			}
		}
		return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
	}

	/** @return array{type: string, transport: ?string, route: ?string, distanceKm: ?float, note: ?string, amountCents: ?int} */
	private static function item(mixed $raw, IL10N $l10n, bool $strict): array {
		if (!is_array($raw)) {
			throw new \InvalidArgumentException($l10n->t('Die Reiseangaben fehlen oder sind unlesbar.'));
		}
		$type = $raw['type'] ?? null;
		if ($type !== self::TYPE_TRAVEL && $type !== self::TYPE_PARKING) {
			throw new \InvalidArgumentException($l10n->t('Unbekannte Art einer Position.'));
		}
		$amount = $raw['amountCents'] ?? null;
		if ($amount !== null && (!is_int($amount) || $amount < 0 || $amount > self::MAX_AMOUNT_CENTS)) {
			throw new \InvalidArgumentException($l10n->t('Bitte einen gültigen Betrag angeben, z. B. 12,50.'));
		}
		$item = [
			'type' => $type,
			'transport' => null,
			'route' => null,
			'distanceKm' => null,
			'note' => null,
			'amountCents' => $amount,
		];
		if ($type === self::TYPE_TRAVEL) {
			$transport = $raw['transport'] ?? null;
			if ($transport !== null && $transport !== '' && !in_array($transport, self::TRANSPORTS, true)) {
				throw new \InvalidArgumentException($l10n->t('Unbekanntes Verkehrsmittel.'));
			}
			$item['transport'] = $transport !== '' ? $transport : null;
			$item['route'] = self::text($raw['route'] ?? null, $l10n);
			$km = $raw['distanceKm'] ?? null;
			if ($km !== null && $km !== '') {
				if ((!is_int($km) && !is_float($km)) || $km <= 0 || $km > 100000) {
					throw new \InvalidArgumentException($l10n->t('Bitte eine gültige Entfernung in Kilometern angeben.'));
				}
				$item['distanceKm'] = round((float)$km, 1);
			}
		} else {
			$item['note'] = self::text($raw['note'] ?? null, $l10n);
		}

		if ($strict) {
			if ($item['amountCents'] === null || $item['amountCents'] <= 0) {
				throw new \InvalidArgumentException($l10n->t('Jede Position braucht einen Betrag größer als 0.'));
			}
			if ($type === self::TYPE_TRAVEL && ($item['transport'] === null || $item['route'] === null)) {
				throw new \InvalidArgumentException($l10n->t('Bitte bei den Fahrtkosten Verkehrsmittel und Strecke angeben.'));
			}
		}
		return $item;
	}

	private static function text(mixed $value, IL10N $l10n): ?string {
		if ($value === null) {
			return null;
		}
		if (!is_string($value)) {
			throw new \InvalidArgumentException($l10n->t('Die Reiseangaben fehlen oder sind unlesbar.'));
		}
		$value = Utf8::wellFormed(trim($value), $l10n);
		if ($value === '') {
			return null;
		}
		if (mb_strlen($value) > self::MAX_TEXT) {
			throw new \InvalidArgumentException($l10n->t('Eine Angabe ist zu lang (höchstens %d Zeichen).', [self::MAX_TEXT]));
		}
		return $value;
	}

	/** „JJJJ-MM-TTThh:mm“ wie aus einem datetime-local-Feld. */
	private static function dateTime(mixed $value, IL10N $l10n): ?string {
		if ($value === null || $value === '') {
			return null;
		}
		if (!is_string($value)) {
			throw new \InvalidArgumentException($l10n->t('Bitte Beginn und Ende als Datum mit Uhrzeit angeben.'));
		}
		$parsed = \DateTime::createFromFormat('!Y-m-d\\TH:i', $value);
		if ($parsed === false || $parsed->format('Y-m-d\\TH:i') !== $value) {
			throw new \InvalidArgumentException($l10n->t('Bitte Beginn und Ende als Datum mit Uhrzeit angeben.'));
		}
		return $value;
	}
}
