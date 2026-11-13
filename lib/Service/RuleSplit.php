<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Rule;

/**
 * Wie eine Importregel einen Umsatz aufteilt (Issue #163).
 *
 * Erst die festen Anteile in ihrer Reihenfolge, dann der Rest auf das
 * Gegenkonto der Regel mit deren Kostenstelle. Ein Rest von 0 ergibt keinen
 * Teil. Reicht der Umsatz nicht für alle Anteile, greift die Regel nicht:
 * lieber unzugeordnet als still ein falscher Betrag auf einem
 * Abteilungskonto. Das Gegenstück im Browser ist src/lib/ruleSplit.js.
 */
final class RuleSplit {

	/**
	 * @param int $amountCents Umsatzbetrag; das Vorzeichen zählt nicht
	 * @return list<array{accountId:int, amountCents:int, costCenterId:int|null}>|null
	 *                                                                               null, wenn der Umsatz kleiner ist als die Anteile zusammen
	 */
	public static function parts(Rule $rule, int $amountCents): ?array {
		$total = abs($amountCents);
		$parts = [];
		$fixed = 0;
		foreach ($rule->getPartsList() as $part) {
			$cents = (int)$part->getAmountCents();
			$fixed += $cents;
			$parts[] = [
				'accountId' => (int)$part->getAccountId(),
				'amountCents' => $cents,
				'costCenterId' => $part->getCostCenterId() === null ? null : (int)$part->getCostCenterId(),
			];
		}
		$rest = $total - $fixed;
		if ($rest < 0) {
			return null;
		}
		if ($rest > 0 || $parts === []) {
			$parts[] = [
				'accountId' => (int)$rule->getContraAccountId(),
				'amountCents' => $rest,
				'costCenterId' => $rule->getCostCenterId() === null ? null : (int)$rule->getCostCenterId(),
			];
		}
		return $parts;
	}
}
