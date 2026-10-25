<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\NumberRange;
use OCA\Vereinsbuchhaltung\Db\NumberRangeMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;
use OCP\IL10N;

/**
 * Fortlaufende Rechnungsnummern (§ 14 Abs. 4 Nr. 4 UStG: einmalig, aus einer
 * oder mehreren Zahlenreihen). Pro Art gibt es einen Kreis: `rechnung`
 * (`RE-{JJJJ}-{NNNN}`) und `storno` (`ST-{JJJJ}-{NNNN}`).
 *
 * Muster: `{JJJJ}` ist das Jahr des Rechnungsdatums, `{JJ}` die letzten zwei
 * Stellen, `{NNNN}` der Zähler. Die Anzahl der N bestimmt die Mindestbreite
 * (mit führenden Nullen). Dazwischen nur Buchstaben, Ziffern und `-_/.`; so
 * bleibt die Nummer im Verwendungszweck der Überweisung wiederzufinden.
 *
 * Vergabe: {@see issue()} muss in der Transaktion der Festschreibung laufen.
 * Der Zähler wird dort mit Compare-and-Swap weitergesetzt. Rollt die
 * Festschreibung zurück, rollt der Zähler mit zurück, es entstehen keine
 * Lücken. Zusätzlich verhindert der eindeutige Index auf der Rechnungsnummer
 * jede doppelte Vergabe.
 *
 * Jahreswechsel: Steht der Zähler auf `yearly`, beginnt er mit dem ersten
 * Rechnungsdatum eines neuen Jahres wieder bei 1. Der Zähler gehört zum Jahr
 * der zuletzt vergebenen Nummer; eine nachträglich ausgestellte Rechnung mit
 * Datum im Vorjahr würde den Kreis zurückdrehen. Deshalb lehnt
 * {@see issue()} ein Datum ab, das vor dem Jahr des Zählers liegt.
 */
class NumberRangeService {

	public const DEFAULT_PATTERNS = [
		NumberRange::KIND_INVOICE => 'RE-{JJJJ}-{NNNN}',
		NumberRange::KIND_CREDIT => 'ST-{JJJJ}-{NNNN}',
	];

	private const MAX_ATTEMPTS = 5;
	private const MAX_PATTERN_LENGTH = 40;

	public function __construct(
		private NumberRangeMapper $mapper,
		private ?IL10N $l10n = null,
	) {
	}

	/** @return NumberRange[] beide Kreise, fehlende werden mit dem Standardmuster angelegt */
	public function all(): array {
		$ranges = [];
		foreach (NumberRange::KINDS as $kind) {
			$ranges[] = $this->ensure($kind);
		}
		return $ranges;
	}

	public function ensure(string $kind): NumberRange {
		if (!in_array($kind, NumberRange::KINDS, true)) {
			throw new \InvalidArgumentException($this->msg('Unbekannte Art des Nummernkreises.'));
		}
		try {
			return $this->mapper->findByKind($kind);
		} catch (DoesNotExistException) {
		}
		$range = new NumberRange();
		$range->setKind($kind);
		$range->setPrefixPattern(self::DEFAULT_PATTERNS[$kind]);
		$range->setPadding(4);
		$range->setResetMode(NumberRange::RESET_YEARLY);
		$range->setNextNo(1);
		try {
			return $this->mapper->insert($range);
		} catch (DbException $e) {
			// Ein gleichzeitiger Aufruf war schneller; der eindeutige Index
			// auf `kind` lässt nur eine Zeile zu.
			if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			return $this->mapper->findByKind($kind);
		}
	}

	/**
	 * Ändert Muster, Zählerverhalten und optional den nächsten Zählerstand.
	 *
	 * @throws \InvalidArgumentException bei ungültigem Muster oder Zählerstand
	 */
	public function update(string $kind, string $pattern, string $resetMode, ?int $nextNo): NumberRange {
		$range = $this->ensure($kind);
		$error = self::validatePattern($pattern);
		if ($error !== null) {
			throw new \InvalidArgumentException($this->msg($error));
		}
		if (!in_array($resetMode, NumberRange::RESET_MODES, true)) {
			throw new \InvalidArgumentException($this->msg('Unbekannte Zählerstand-Regel.'));
		}
		if ($resetMode === NumberRange::RESET_YEARLY && !str_contains($pattern, '{JJJJ}') && !str_contains($pattern, '{JJ}')) {
			// Ohne Jahr im Muster würde der Zähler jährlich neu beginnen und
			// Nummern doppelt vergeben.
			throw new \InvalidArgumentException($this->msg('Bei jährlichem Neubeginn muss das Muster das Jahr enthalten ({JJJJ} oder {JJ}).'));
		}
		if ($nextNo !== null && ($nextNo < 1 || $nextNo > 99999999)) {
			throw new \InvalidArgumentException($this->msg('Der nächste Zählerstand muss mindestens 1 sein.'));
		}
		$range->setPrefixPattern($pattern);
		$range->setPadding(self::counterWidth($pattern));
		$range->setResetMode($resetMode);
		if ($nextNo !== null) {
			$range->setNextNo($nextNo);
			// Eine ausdrückliche Vorgabe gilt für das nächste Jahr des Zählers.
		}
		return $this->mapper->update($range);
	}

	/**
	 * Vergibt die nächste Nummer. Muss in der Transaktion der Festschreibung
	 * laufen.
	 *
	 * @return array{number: string, rangeId: int}
	 * @throws \InvalidArgumentException wenn das Datum vor dem Jahr der zuletzt vergebenen Nummer liegt
	 * @throws \RuntimeException wenn der Zähler trotz Wiederholungen nicht weiterkam
	 */
	public function issue(string $kind, \DateTimeInterface $date): array {
		for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
			$range = $this->ensure($kind);
			$key = $range->getResetMode() === NumberRange::RESET_YEARLY ? $date->format('Y') : 'all';
			$counterKey = $range->getCounterKey();
			if ($counterKey !== null && $key !== 'all' && $key < $counterKey) {
				throw new \InvalidArgumentException($this->msg('Das Rechnungsdatum liegt vor dem Jahr der zuletzt vergebenen Rechnungsnummer (%s). Bitte ein Datum ab diesem Jahr wählen.', [$counterKey]));
			}
			// Beginnt ein neues Jahr, startet der Zähler bei 1. Ist der Zähler
			// noch unbenutzt (kein Schlüssel), gilt ein vom Verwalter gesetzter Startwert.
			$no = ($counterKey === null || $counterKey === $key) ? $range->getNextNo() : 1;
			$issuedAt = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
			if ($this->mapper->advance((int)$range->getId(), $range->getNextNo(), $no + 1, $key, $issuedAt)) {
				return [
					'number' => self::format($range->getPrefixPattern(), $date, $no),
					'rangeId' => (int)$range->getId(),
				];
			}
		}
		throw new \RuntimeException($this->msg('Die Rechnungsnummer konnte nicht vergeben werden. Bitte noch einmal versuchen.'));
	}

	/** Die Nummer, die {@see issue()} jetzt vergäbe, ohne den Zähler zu verändern. */
	public function peek(string $kind, \DateTimeInterface $date): string {
		$range = $this->ensure($kind);
		$key = $range->getResetMode() === NumberRange::RESET_YEARLY ? $date->format('Y') : 'all';
		$counterKey = $range->getCounterKey();
		$no = ($counterKey === null || $counterKey === $key) ? $range->getNextNo() : 1;
		return self::format($range->getPrefixPattern(), $date, $no);
	}

	public static function format(string $pattern, \DateTimeInterface $date, int $no): string {
		$width = self::counterWidth($pattern);
		$out = str_replace(['{JJJJ}', '{JJ}'], [$date->format('Y'), $date->format('y')], $pattern);
		return (string)preg_replace_callback('/\{N+\}/', static fn () => str_pad((string)$no, $width, '0', STR_PAD_LEFT), $out);
	}

	public static function counterWidth(string $pattern): int {
		return preg_match('/\{(N+)\}/', $pattern, $m) === 1 ? strlen($m[1]) : 4;
	}

	/**
	 * Regulärer Ausdruck für Nummern dieses Musters, ohne Begrenzer. Für den
	 * Bankabgleich, der die Nummer im Verwendungszweck sucht.
	 */
	public static function toRegex(string $pattern): string {
		$parts = preg_split('/(\{JJJJ\}|\{JJ\}|\{N+\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
		$regex = '';
		foreach ($parts as $part) {
			$regex .= match (true) {
				$part === '{JJJJ}' => '\d{4}',
				$part === '{JJ}' => '\d{2}',
				str_starts_with($part, '{N') => '\d{' . (strlen($part) - 2) . ',}',
				default => preg_quote($part, '/'),
			};
		}
		return $regex;
	}

	/**
	 * @return string|null Fehlermeldung (Quelltext, noch nicht übersetzt) oder null, wenn das Muster taugt
	 */
	public static function validatePattern(string $pattern): ?string {
		if ($pattern === '' || strlen($pattern) > self::MAX_PATTERN_LENGTH) {
			return 'Das Muster muss zwischen 1 und 40 Zeichen lang sein.';
		}
		if (preg_match_all('/\{N+\}/', $pattern) !== 1) {
			return 'Das Muster muss genau einen Zähler enthalten, z. B. {NNNN}.';
		}
		if (preg_match('/\{(N+)\}/', $pattern, $m) === 1 && strlen($m[1]) > 8) {
			return 'Der Zähler darf höchstens acht Stellen haben.';
		}
		$rest = (string)preg_replace('/\{(JJJJ|JJ|N+)\}/', '', $pattern);
		if (preg_match('/^[A-Za-z0-9_\-\/.]*$/', $rest) !== 1) {
			return 'Erlaubt sind Buchstaben, Ziffern und - _ / . neben den Platzhaltern {JJJJ}, {JJ} und {NNNN}.';
		}
		return null;
	}

	/** @param list<int|string> $params */
	private function msg(string $text, array $params = []): string {
		return $this->l10n !== null ? $this->l10n->t($text, $params) : vsprintf($text, $params);
	}
}
