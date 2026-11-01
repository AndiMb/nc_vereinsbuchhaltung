<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\InvoiceBatch;

use OCA\Vereinsbuchhaltung\Db\Debtor;

/**
 * Findet zu einem Empfänger aus der Tabelle einen vorhandenen Debitor (#243):
 * 1. gleiche Kundennummer, 2. gleicher Name und gleiche E-Mail, 3. gleicher
 * Name und gleiche PLZ. Vergleiche ohne Groß-/Kleinschreibung und überzählige
 * Leerzeichen.
 *
 * Ein Treffer wird nie überschrieben: Angaben, die beim Debitor schon stehen
 * und in der Tabelle anders lauten, meldet {@see match()} als `differences`
 * (die Vorschau weist darauf hin). Felder, die beim Debitor leer sind und in
 * der Tabelle stehen, liefert es als `fill` – der Import ergänzt sie, damit
 * etwa eine erstmals bekannte E-Mail-Adresse den Versand möglich macht.
 */
class DebtorMatcher {

	private const COMPARED = ['name2', 'street', 'postalCode', 'city', 'email'];

	/** @param list<Debtor> $debtors nur nicht anonymisierte */
	public function __construct(private array $debtors) {
	}

	/**
	 * @param array<string, string|null> $recipient
	 * @return array{debtor: Debtor|null, differences: list<string>, fill: array<string, string>}
	 */
	public function match(array $recipient): array {
		$name = self::norm($recipient['name'] ?? null);
		$customerNo = self::norm($recipient['customerNo'] ?? null);
		$email = self::norm($recipient['email'] ?? null);
		$postalCode = self::norm($recipient['postalCode'] ?? null);

		$found = null;
		if ($customerNo !== '') {
			$found = $this->first(static fn (Debtor $d): bool => self::norm($d->getCustomerNo()) === $customerNo);
		}
		if ($found === null && $email !== '') {
			$found = $this->first(static fn (Debtor $d): bool => self::norm($d->getName()) === $name && self::norm($d->getEmail()) === $email);
		}
		if ($found === null && $postalCode !== '') {
			$found = $this->first(static fn (Debtor $d): bool => self::norm($d->getName()) === $name && self::norm($d->getPostalCode()) === $postalCode);
		}
		if ($found === null) {
			return ['debtor' => null, 'differences' => [], 'fill' => []];
		}

		$differences = [];
		$fill = [];
		foreach (self::COMPARED as $field) {
			$value = self::norm($recipient[$field] ?? null);
			if ($value === '') {
				continue;
			}
			$getter = 'get' . ucfirst($field);
			$current = self::norm($found->$getter());
			if ($current === '') {
				$fill[$field] = trim((string)$recipient[$field]);
			} elseif ($current !== $value) {
				$differences[] = $field;
			}
		}
		return ['debtor' => $found, 'differences' => $differences, 'fill' => $fill];
	}

	/** @param callable(Debtor): bool $test */
	private function first(callable $test): ?Debtor {
		foreach ($this->debtors as $debtor) {
			if ($test($debtor)) {
				return $debtor;
			}
		}
		return null;
	}

	private static function norm(?string $value): string {
		return (string)preg_replace('/\s+/u', ' ', mb_strtolower(trim((string)$value)));
	}
}
