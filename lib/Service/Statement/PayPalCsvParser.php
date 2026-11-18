<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Statement;

use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCA\Vereinsbuchhaltung\Service\ClubCurrency;
use OCP\IL10N;

/**
 * Parser für den deutschen Aktivitätsdownload von PayPal (Issue #375).
 *
 * PayPal liefert keine Kontoauszugsdatei, sondern eine Aktivitätsliste mit
 * rund 40 Spalten (Datum, Uhrzeit, Name, Typ, Status, Währung, Brutto,
 * Gebühr, Netto, Absender/Empfänger E-Mail-Adresse, Transaktionscode, …,
 * Auswirkung auf Guthaben). Dieser Parser bringt sie auf die kanonische
 * Umsatzform, damit Dublettenerkennung, Regeln und „Zuzuordnen" PayPal wie
 * einen Kontoauszug behandeln.
 *
 * Festlegungen, die man der Datei nicht ansieht:
 *
 * - Übernommen werden nur Zeilen mit „Auswirkung auf Guthaben" Soll oder
 *   Haben in Vereinswährung. Memo-Zeilen (Autorisierungen, Vormerkungen) und
 *   Fremdwährungszeilen bewegen das Guthaben in dieser Währung nicht; sie
 *   werden gezählt ({@see skipped()}), damit die Oberfläche sie nennen kann.
 * - Die Gebühr ist ein eigener Umsatz. Das Geldkonto sieht in Wahrheit
 *   Brutto und Gebühr getrennt, und die Gebühr gehört auf ein anderes Konto
 *   als die Spende.
 * - Der Hash hängt nur am Transaktionscode (`paypal|CODE|gross` bzw. `|fee`).
 *   PayPal ändert Status, Betreff und Name nachträglich (Ausstehend →
 *   Abgeschlossen); ein Hash über diese Felder ließe jeden neu geladenen
 *   Download als lauter neue Umsätze erscheinen.
 * - Die Kennung des Kontos ist die häufigste eigene Adresse der Datei
 *   (Soll → Absender, Haben → Empfänger), siehe {@see PayPalAddresses::key()}.
 *
 * $l10n ist wie bei CamtCsvParser optional, damit der Parser ohne laufende
 * Nextcloud-Instanz instanziierbar bleibt.
 */
final class PayPalCsvParser implements StatementParser, StatementBalanceReader {

	/** Pflichtspalten der Kopfzeile (klein geschrieben, getrimmt). */
	private const COLUMNS = [
		'date' => 'datum',
		'currency' => 'währung',
		'gross' => 'brutto',
		'fee' => 'gebühr',
		'net' => 'netto',
		'from' => 'absender e-mail-adresse',
		'to' => 'empfänger e-mail-adresse',
		'code' => 'transaktionscode',
		'effect' => 'auswirkung auf guthaben',
	];

	/** Spalten, die fehlen dürfen. */
	private const OPTIONAL_COLUMNS = [
		'name' => 'name',
		'type' => 'typ',
		'item' => 'artikelbezeichnung',
		'related' => 'zugehöriger transaktionscode',
		'subject' => 'betreff',
		'note' => 'hinweis',
		'time' => 'uhrzeit',
		'balance' => 'guthaben',
	];

	public function __construct(
		private RowNormalizer $normalizer = new RowNormalizer(),
		private ?IL10N $l10n = null,
		private ?ClubCurrency $currency = null,
	) {
	}

	/** @param array<int, mixed> $params Parameter für sprintf, siehe {@see IL10N::t()} */
	private function msg(string $text, array $params = []): string {
		if ($this->l10n !== null) {
			return $this->l10n->t($text, $params);
		}
		return $params === [] ? $text : vsprintf($text, $params);
	}

	public function sourceKey(): string {
		return 'paypal';
	}

	public function supports(string $content): bool {
		$firstLine = strtok($this->prepare($content), "\r\n");
		if ($firstLine === false) {
			return false;
		}
		$header = str_getcsv($firstLine, $this->detectDelimiter($firstLine), '"', '\\');
		$map = $this->mapHeader($header);
		foreach (['code', 'gross', 'net', 'effect'] as $key) {
			if (!isset($map[$key])) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 * @throws UserFacingException bei unbrauchbarer Datei
	 */
	public function parse(string $content): array {
		return $this->read($content)['rows'];
	}

	/**
	 * Anzahl der Datenzeilen, die nicht übernommen wurden: Memo und andere
	 * Zeilen ohne Soll/Haben sowie Zeilen in fremder Währung. Zeilen über
	 * 0,00 zählen nicht – sie sind nichts, was der Nutzer vermissen würde.
	 */
	public function skipped(string $content): int {
		return $this->read($content)['skipped'];
	}

	/**
	 * Anfangs- und Endsaldo aus der Spalte „Guthaben" für die Kontoabstimmung.
	 *
	 * Die Kette läuft über die übernommenen CSV-Zeilen, nicht über die daraus
	 * erzeugten Umsätze: Guthaben ist der Stand nach der ganzen Zeile, also
	 * nach Brutto und Gebühr zusammen (Netto). Memo- und Fremdwährungszeilen
	 * ändern das Guthaben in Vereinswährung nicht und bleiben außen vor.
	 *
	 * @return list<StatementBalance>
	 * @throws UserFacingException bei unbrauchbarer Datei
	 */
	public function balances(string $content): array {
		$read = $this->read($content);
		$chain = $this->orderChain($read['chain']);
		if ($chain === []) {
			return [];
		}
		$gaps = [];
		for ($i = 1; $i < count($chain); $i++) {
			$expected = $chain[$i - 1]['balance'] + $chain[$i]['net'];
			if ($expected !== $chain[$i]['balance']) {
				$gaps[] = ['date' => $chain[$i]['date'], 'expectedCents' => $expected, 'actualCents' => $chain[$i]['balance']];
			}
		}
		$first = $chain[0];
		$last = $chain[count($chain) - 1];
		return [new StatementBalance(
			$read['ownAccount'],
			$first['balance'] - $first['net'],
			$first['date'],
			$last['balance'],
			$last['date'],
			$gaps,
		)];
	}

	/**
	 * Zeitliche Reihenfolge der Kette. PayPal löst nur auf die Sekunde auf, und
	 * Zeilen derselben Sekunde stehen in der Datei in beliebiger Folge. Innerhalb
	 * einer solchen Gruppe wird daher die Zeile vorgezogen, deren Guthaben
	 * abzüglich Netto dem laufenden Stand entspricht; passt keine, gilt die
	 * Dateireihenfolge. Für die allererste Gruppe ist der Stand unbekannt: dort
	 * beginnt die Zeile, deren Anfangsstand kein anderes Guthaben der Gruppe ist.
	 *
	 * @param list<array{date:string, time:string, net:int, balance:int}> $chain in Dateireihenfolge
	 * @return list<array{date:string, time:string, net:int, balance:int}>
	 */
	private function orderChain(array $chain): array {
		$groups = [];
		foreach ($chain as $entry) {
			$groups[$entry['date'] . ' ' . $entry['time']][] = $entry;
		}
		ksort($groups, SORT_STRING);

		$ordered = [];
		$running = null;
		foreach ($groups as $group) {
			while ($group !== []) {
				$pick = array_key_first($group);
				foreach ($group as $key => $candidate) {
					$start = $candidate['balance'] - $candidate['net'];
					$fits = $running !== null
						? $start === $running
						: !in_array($start, array_column(array_diff_key($group, [$key => true]), 'balance'), true);
					if ($fits) {
						$pick = $key;
						break;
					}
				}
				$ordered[] = $group[$pick];
				$running = $group[$pick]['balance'];
				unset($group[$pick]);
			}
		}
		return $ordered;
	}

	/**
	 * @return array{rows: array<int, array<string, mixed>>, skipped: int, chain: list<array{date:string, time:string, net:int, balance:int}>, ownAccount: string}
	 */
	private function read(string $content): array {
		$records = $this->readRecords($this->prepare($content));
		if (count($records) < 2) {
			throw new UserFacingException($this->msg('Die Datei enthält keine Buchungszeilen.'));
		}
		$map = $this->mapHeader($records[0]);
		foreach (self::COLUMNS as $key => $_) {
			if (!isset($map[$key])) {
				throw new UserFacingException($this->msg('Die Datei ist kein PayPal-Aktivitätsdownload: Spalten fehlen.'));
			}
		}

		$clubCurrency = strtoupper($this->currency?->code() ?? ClubCurrency::DEFAULT);

		// Namen je Code: Zeilen wie „Bankgutschrift auf PayPal-Konto" tragen
		// keinen Namen, verweisen aber auf die Transaktion, die ihn hat.
		$names = [];
		$entries = [];
		$ownCounts = [];
		$ownLabels = [];
		for ($i = 1; $i < count($records); $i++) {
			$cols = $records[$i];
			$entry = [
				'line' => $i + 1,
				'date' => $this->cell($cols, $map, 'date'),
				'currency' => strtoupper($this->cell($cols, $map, 'currency')),
				'gross' => $this->cell($cols, $map, 'gross'),
				'fee' => $this->cell($cols, $map, 'fee'),
				'net' => $this->cell($cols, $map, 'net'),
				'from' => $this->cell($cols, $map, 'from'),
				'to' => $this->cell($cols, $map, 'to'),
				'code' => $this->cell($cols, $map, 'code'),
				'effect' => $this->cell($cols, $map, 'effect'),
				'name' => $this->cell($cols, $map, 'name'),
				'type' => $this->cell($cols, $map, 'type'),
				'item' => $this->cell($cols, $map, 'item'),
				'related' => $this->cell($cols, $map, 'related'),
				'subject' => $this->cell($cols, $map, 'subject'),
				'note' => $this->cell($cols, $map, 'note'),
				'time' => $this->cell($cols, $map, 'time'),
				'balance' => $this->cell($cols, $map, 'balance'),
			];
			if ($entry['code'] !== '' && $entry['name'] !== '') {
				$names[$entry['code']] = $entry['name'];
			}
			$side = $this->side($entry['effect']);
			if ($side !== null) {
				$own = $side === 'soll' ? $entry['from'] : $entry['to'];
				if ($own !== '') {
					$ident = mb_strtolower($own);
					$ownCounts[$ident] = ($ownCounts[$ident] ?? 0) + 1;
					$ownLabels[$ident] ??= $own;
				}
			}
			$entries[] = $entry;
		}

		if ($ownCounts === []) {
			throw new UserFacingException($this->msg('In der Datei steht keine eigene PayPal-Adresse (Absender bzw. Empfänger E-Mail-Adresse).'));
		}
		arsort($ownCounts);
		$ownLabel = $ownLabels[array_key_first($ownCounts)];
		$ownAccount = PayPalAddresses::key($ownLabel);

		$rows = [];
		$chain = [];
		$skipped = 0;
		$seen = [];
		foreach ($entries as $entry) {
			if ($this->side($entry['effect']) === null || $entry['currency'] !== $clubCurrency) {
				$skipped++;
				continue;
			}

			// Der Code ist der einzige Anker des Hashs: ohne ihn oder bei Doppelung
			// würden Umsätze als Dublette verworfen bzw. nie wiedererkannt.
			if ($entry['code'] === '') {
				throw new UserFacingException($this->msg('Zeile %d: Transaktionscode fehlt.', [$entry['line']]));
			}
			if (isset($seen[$entry['code']])) {
				throw new UserFacingException($this->msg('Zeile %d: Transaktionscode %s kommt mehrfach vor.', [$entry['line'], $entry['code']]));
			}
			$seen[$entry['code']] = true;

			$date = $this->parseDate($entry['date']);
			$gross = $this->parseAmount($entry['gross']);
			$fee = $entry['fee'] === '' ? 0 : $this->parseAmount($entry['fee']);
			$net = $this->parseAmount($entry['net']);
			if ($date === null || $gross === null || $fee === null || $net === null) {
				throw new UserFacingException($this->msg('Zeile %d: Datum oder Betrag sind nicht lesbar.', [$entry['line']]));
			}
			// Prüfsumme: Netto ist Brutto plus (negative) Gebühr. Stimmt das
			// nicht, ist die Spalte verrutscht oder das Format ein anderes –
			// lieber abbrechen als falsche Beträge zu buchen.
			if ($gross + $fee !== $net) {
				throw new UserFacingException($this->msg('Zeile %d: Netto passt nicht zu Brutto und Gebühr.', [$entry['line']]));
			}

			$balance = $entry['balance'] === '' ? null : $this->parseAmount($entry['balance']);
			if ($balance !== null) {
				$chain[] = ['date' => $date, 'time' => $entry['time'], 'net' => $net, 'balance' => $balance];
			}

			$code = $entry['code'];
			$counterparty = $entry['name'] !== '' ? $entry['name'] : ($names[$entry['related']] ?? '');
			$text = implode(' / ', array_filter([$entry['subject'], $entry['note'], $entry['item']], static fn (string $s) => $s !== ''));
			$purpose = ($text !== '' ? $text . ' · ' : '') . 'PayPal ' . $code;

			$row = $this->normalizer->build([
				'ownAccount' => $ownAccount,
				'bookingDate' => $date,
				'valueDate' => $date,
				'bookingText' => $entry['type'],
				'purpose' => $purpose,
				'counterparty' => $counterparty,
				'amountCents' => $gross,
				'currency' => $entry['currency'],
			]);
			if ($row !== null) {
				$row['hash'] = hash('sha256', 'paypal|' . $code . '|gross');
				$row['ownAccountLabel'] = $ownLabel;
				$rows[] = $row;
			}

			if ($fee !== 0) {
				$feeRow = $this->normalizer->build([
					'ownAccount' => $ownAccount,
					'bookingDate' => $date,
					'valueDate' => $date,
					'bookingText' => $this->msg('PayPal-Gebühr'),
					'purpose' => $this->msg('PayPal-Gebühr zu %s', [$code]),
					'counterparty' => 'PayPal',
					'amountCents' => $fee,
					'currency' => $entry['currency'],
				]);
				if ($feeRow !== null) {
					$feeRow['hash'] = hash('sha256', 'paypal|' . $code . '|fee');
					$feeRow['ownAccountLabel'] = $ownLabel;
					$rows[] = $feeRow;
				}
			}
		}

		return ['rows' => $rows, 'skipped' => $skipped, 'chain' => $chain, 'ownAccount' => $ownAccount];
	}

	/** 'soll' oder 'haben'; alles andere (Memo, leer) wird nicht übernommen. */
	private function side(string $effect): ?string {
		$e = mb_strtolower($effect);
		return $e === 'soll' || $e === 'haben' ? $e : null;
	}

	/**
	 * „1.250,00" / „-1,60" → Cent; null bei nicht lesbarem Wert. Das Format
	 * ist bewusst das des deutschen Exports (Tausenderpunkt, Dezimalkomma).
	 */
	private function parseAmount(string $value): ?int {
		$v = str_replace(['.', ' ', "\xC2\xA0"], '', trim($value));
		$v = str_replace(',', '.', $v);
		if (!preg_match('/^[-+]?\d+(\.\d+)?$/', $v)) {
			return null;
		}
		return (int)round((float)$v * 100);
	}

	/** „TT.MM.JJJJ" → „JJJJ-MM-TT"; null bei ungültigem Datum. */
	private function parseDate(string $value): ?string {
		if (!preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', trim($value), $m)) {
			return null;
		}
		if (!checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
			return null;
		}
		return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
	}

	/**
	 * @param array<int, string|null> $cols
	 * @param array<string, int> $map
	 */
	private function cell(array $cols, array $map, string $key): string {
		if (!isset($map[$key])) {
			return '';
		}
		return trim((string)($cols[$map[$key]] ?? ''));
	}

	/**
	 * Spaltenindex je logischem Feld. Die Kopfzeile wird über Namen gelesen,
	 * nicht über Positionen: PayPal hat die Spalten schon umgeordnet.
	 *
	 * @param array<int, string|null> $header
	 * @return array<string, int>
	 */
	private function mapHeader(array $header): array {
		$names = [];
		foreach ($header as $i => $name) {
			$names[mb_strtolower(trim((string)$name))] ??= $i;
		}
		$map = [];
		foreach (self::COLUMNS + self::OPTIONAL_COLUMNS as $key => $name) {
			if (isset($names[$name])) {
				$map[$key] = $names[$name];
			}
		}
		return $map;
	}

	/** UTF-8 herstellen und BOM entfernen. */
	private function prepare(string $content): string {
		// BOM zuerst entfernen: Unter PHP 8.1/8.2 stuft mb_detect_encoding gueltiges
		// UTF-8 mit BOM als Windows-1252 ein und wuerde es doppelt kodieren (die
		// Spaltenkoepfe passten dann nicht mehr). Echte PayPal-Exporte tragen ein BOM.
		$content = (string)preg_replace('/^\xEF\xBB\xBF/', '', $content);
		if (mb_check_encoding($content, 'UTF-8')) {
			return $content;
		}
		$encoding = mb_detect_encoding($content, ['Windows-1252', 'ISO-8859-1'], true);
		$converted = mb_convert_encoding($content, 'UTF-8', $encoding === false ? 'Windows-1252' : $encoding);
		return $converted === false ? $content : $converted;
	}

	private function detectDelimiter(string $headerLine): string {
		return substr_count($headerLine, ';') > substr_count($headerLine, ',') ? ';' : ',';
	}

	/** @return array<int, array<int, string|null>> */
	private function readRecords(string $content): array {
		$firstLine = strtok($content, "\r\n");
		$delimiter = $this->detectDelimiter($firstLine === false ? '' : $firstLine);

		$handle = fopen('php://temp', 'r+');
		if ($handle === false) {
			throw new UserFacingException($this->msg('Die Datei konnte nicht gelesen werden.'));
		}
		fwrite($handle, $content);
		rewind($handle);

		$records = [];
		while (($cols = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
			if (trim(implode('', array_map(static fn ($c) => (string)$c, $cols))) === '') {
				continue;
			}
			$records[] = $cols;
		}
		fclose($handle);

		return $records;
	}
}
