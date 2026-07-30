<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Statement;

use OCA\Vereinsbuchhaltung\Service\CamtCsvParser;

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

	public function __construct(
		Camt053Parser $camt,
		Mt940Parser $mt940,
		CamtCsvParser $csv,
	) {
		$this->parsers = [$camt, $mt940, $csv];
	}

	/**
	 * @throws \RuntimeException wenn kein Format greift
	 */
	public function detect(string $content): StatementParser {
		if (trim($content) === '') {
			throw new \RuntimeException('Die Datei ist leer.');
		}
		foreach ($this->parsers as $parser) {
			if ($parser->supports($content)) {
				return $parser;
			}
		}
		throw new \RuntimeException(
			'Das Dateiformat wurde nicht erkannt. Unterstützt werden CSV-CAMT, '
			. 'CAMT.053 (XML) und MT940 – im Onlinebanking meist als '
			. '„Umsätze exportieren" auswählbar.'
		);
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
