<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Statement;

use OCA\Vereinsbuchhaltung\Service\CamtCsvParser;
use OCP\IL10N;

/**
 * Wählt anhand des Dateiinhalts den passenden Parser.
 *
 * Bewusst inhaltsbasiert statt über die Dateiendung: die Institute benennen
 * ihre Auszüge uneinheitlich (.csv, .xml, .sta, .txt), und ein falsch
 * benannter Export soll nicht an einer Endung scheitern.
 *
 * Die Reihenfolge ist wichtig: CSV erkennt sich am lockersten (irgendeine
 * Kopfzeile mit Buchungstag und Betrag) und steht deshalb am Ende, damit es
 * die spezifischeren Formate nicht wegschnappt.
 */
class StatementParserRegistry {

	/** @var StatementParser[] */
	private array $parsers;

	/**
	 * $l10n ist bewusst optional: die Registry bleibt dadurch ohne laufende
	 * Nextcloud-Instanz mit drei positionellen Argumenten instanziierbar
	 * (siehe tests/unit/StatementFormatTest.php), übersetzt ihre
	 * Fehlermeldungen aber, sobald Nextclouds DI-Container sie mit einer
	 * echten IL10N versorgt.
	 */
	public function __construct(
		Camt053Parser $camt,
		Mt940Parser $mt940,
		CamtCsvParser $csv,
		private ?IL10N $l10n = null,
	) {
		$this->parsers = [$camt, $mt940, $csv];
	}

	private function msg(string $text): string {
		return $this->l10n !== null ? $this->l10n->t($text) : $text;
	}

	/**
	 * @throws \RuntimeException wenn kein Format greift
	 */
	public function detect(string $content): StatementParser {
		if (trim($content) === '') {
			throw new \RuntimeException($this->msg('Die Datei ist leer.'));
		}
		foreach ($this->parsers as $parser) {
			if ($parser->supports($content)) {
				return $parser;
			}
		}
		throw new \RuntimeException($this->msg(
			'Das Dateiformat wurde nicht erkannt. Unterstützt werden CSV-CAMT, '
			. 'CAMT.053 (XML) und MT940 – im Onlinebanking meist als '
			. '„Umsätze exportieren" auswählbar.'
		));
	}

	/**
	 * Erkennt das Format und liest die Datei in einem Schritt.
	 *
	 * @return array{0: array<int, array<string, mixed>>, 1: string} Zeilen und Quellenkürzel
	 */
	public function parse(string $content): array {
		$parser = $this->detect($content);
		return [$parser->parse($content), $parser->sourceKey()];
	}
}
