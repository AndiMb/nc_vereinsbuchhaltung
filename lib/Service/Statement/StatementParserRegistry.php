<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Statement;

use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
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
	private StatementArchive $archive;

	public function __construct(
		Camt053Parser $camt,
		Mt940Parser $mt940,
		CamtCsvParser $csv,
		private ?IL10N $l10n = null,
		?StatementArchive $archive = null,
	) {
		$this->parsers = [$camt, $mt940, $csv];
		$this->archive = $archive ?? new StatementArchive($l10n);
	}

	private function msg(string $text): string {
		return $this->l10n !== null ? $this->l10n->t($text) : $text;
	}

	/**
	 * @throws \RuntimeException wenn kein Format greift
	 */
	public function detect(string $content): StatementParser {
		if (trim($content) === '') {
			throw new UserFacingException($this->msg('Die Datei ist leer.'));
		}
		foreach ($this->parsers as $parser) {
			if ($parser->supports($content)) {
				return $parser;
			}
		}
		throw new UserFacingException($this->msg(
			'Das Dateiformat wurde nicht erkannt. Unterstützt werden CSV-CAMT, '
			. 'CAMT.052 und CAMT.053 (XML, auch als ZIP-Archiv) und MT940 – im '
			. 'Onlinebanking meist als „Umsätze exportieren" auswählbar.'
		));
	}

	/**
	 * Erkennt das Format und liest die Datei in einem Schritt.
	 *
	 * Ein ZIP-Archiv (Issue #359) liest Datei für Datei; alle Dateien müssen
	 * dasselbe Format haben. Einzelne leere CAMT-Tagesreports sind kein
	 * Fehler, ein Archiv ganz ohne Buchungen schon.
	 *
	 * @return array{0: array<int, array<string, mixed>>, 1: string} Zeilen und Quellenkürzel
	 */
	public function parse(string $content): array {
		if (!StatementArchive::isZip($content)) {
			$parser = $this->detect($content);
			return [$parser->parse($content), $parser->sourceKey()];
		}

		$rows = [];
		$source = null;
		foreach ($this->archiveFiles($content) as $file) {
			$parser = $this->detect($file['content']);
			if ($source !== null && $parser->sourceKey() !== $source) {
				throw new UserFacingException($this->msg('Das ZIP-Archiv enthält Kontoauszüge in verschiedenen Formaten. Bitte jedes Format einzeln importieren.'));
			}
			$source = $parser->sourceKey();
			$fileRows = $parser instanceof Camt053Parser ? $parser->rows($file['content']) : $parser->parse($file['content']);
			array_push($rows, ...$fileRows);
		}
		if ($rows === [] || $source === null) {
			throw new UserFacingException($this->msg('Das ZIP-Archiv enthält keine Buchungen.'));
		}
		return [$rows, $source];
	}

	/**
	 * Salden des Auszugs für die Kontoabstimmung (Issue #159).
	 *
	 * Bei einem ZIP-Archiv tragen die einzelnen Dateien je einen Abschnitt;
	 * {@see mergeBalances()} setzt sie je Konto zu einem Zeitraum zusammen.
	 *
	 * @return list<StatementBalance> leer, wenn Format oder Datei keine Salden tragen
	 */
	public function balances(string $content): array {
		if (!StatementArchive::isZip($content)) {
			$parser = $this->detect($content);
			return $parser instanceof StatementBalanceReader ? $parser->balances($content) : [];
		}
		$parts = [];
		foreach ($this->archiveFiles($content) as $file) {
			$parser = $this->detect($file['content']);
			if ($parser instanceof StatementBalanceReader) {
				array_push($parts, ...$parser->balances($file['content']));
			}
		}
		return self::mergeBalances($parts);
	}

	/**
	 * @return list<array{name: string, content: string}>
	 */
	private function archiveFiles(string $content): array {
		$files = $this->archive->files($content);
		if ($files === []) {
			throw new UserFacingException($this->msg('Das ZIP-Archiv enthält keine Buchungen.'));
		}
		return $files;
	}

	/**
	 * Fasst Salden mehrerer Auszüge desselben Kontos zusammen: Anfangssaldo des
	 * frühesten, Endsaldo des letzten, dazwischen jede Stelle als Lücke, an der
	 * ein Anfangssaldo nicht zum vorigen Endsaldo passt – dieselbe Regel, die
	 * die Parser innerhalb einer Datei anwenden.
	 *
	 * @param list<StatementBalance> $parts
	 * @return list<StatementBalance>
	 */
	public static function mergeBalances(array $parts): array {
		$byAccount = [];
		foreach ($parts as $part) {
			$byAccount[$part->ownAccount ?? ''][] = $part;
		}
		$result = [];
		foreach ($byAccount as $key => $list) {
			usort($list, static fn (StatementBalance $a, StatementBalance $b): int => [$a->openingDate, $a->closingDate] <=> [$b->openingDate, $b->closingDate]);
			$gaps = $list[0]->gaps;
			for ($i = 1; $i < count($list); $i++) {
				if ($list[$i]->openingCents !== $list[$i - 1]->closingCents) {
					$gaps[] = ['date' => $list[$i]->openingDate, 'expectedCents' => $list[$i - 1]->closingCents, 'actualCents' => $list[$i]->openingCents];
				}
				array_push($gaps, ...$list[$i]->gaps);
			}
			$last = $list[count($list) - 1];
			$result[] = new StatementBalance(
				$key === '' ? null : (string)$key,
				$list[0]->openingCents,
				$list[0]->openingDate,
				$last->closingCents,
				$last->closingDate,
				$gaps,
			);
		}
		return $result;
	}
}
