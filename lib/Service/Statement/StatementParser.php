<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Statement;

/**
 * Eine Quelle für Kontoumsätze.
 *
 * Jede Umsatzquelle – hochgeladene CSV, CAMT.053-XML, MT940 und später der
 * FinTS-Abruf – liefert Zeilen in derselben kanonischen Form (siehe
 * {@see RowNormalizer::build()}). Alles danach (Dublettenerkennung,
 * Auto-Zuordnungsregeln, Import-Protokoll) ist in ImportService bereits
 * quellenneutral und muss je Format nicht angefasst werden.
 */
interface StatementParser {

	/**
	 * Erkennt am Inhalt, ob dieses Format vorliegt.
	 *
	 * Bewusst inhaltsbasiert und nicht an der Dateiendung: die Institute
	 * benennen ihre Auszüge uneinheitlich (.csv, .CSV, .xml, .sta, .txt), und
	 * beim FinTS-Abruf gibt es überhaupt keinen Dateinamen.
	 */
	public function supports(string $content): bool;

	/**
	 * @return array<int, array<string, mixed>> kanonische Zeilen inkl. Dedup-Hash
	 * @throws \RuntimeException bei unbrauchbarem Inhalt
	 */
	public function parse(string $content): array;

	/** Kürzel für das Import-Protokoll (vbh_imports.source). */
	public function sourceKey(): string;
}
