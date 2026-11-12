<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Statement;

/**
 * Optionaler Zusatz zu {@see StatementParser}: liest die Salden eines
 * Kontoauszugs für die Kontoabstimmung (Issue #159).
 *
 * Bewusst getrennt von parse(): der Import und sein Dedup-Hash bleiben davon
 * unberührt, und nicht jedes Format trägt Salden.
 */
interface StatementBalanceReader {

	/**
	 * @return list<StatementBalance> je Konto im Auszug höchstens einer; leer, wenn die Datei keine Salden trägt
	 * @throws \RuntimeException bei unbrauchbarem Inhalt
	 */
	public function balances(string $content): array;
}
