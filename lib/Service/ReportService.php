<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\CostCenterMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCP\IConfig;

/**
 * Auswertungen, insbesondere nach Kostenstellen.
 *
 * Die Kostenstelle steckt als zweite Zahlengruppe in der Kontonummer:
 *   "111 51 2021"  ->  Typ 111, Kostenstelle 51, Jahr 2021
 *   "546 01 01"    ->  Typ 546, Kostenstelle 01 (ideell)
 *   "111"          ->  nur Einnahmen-/Ausgabentyp, keine Kostenstelle
 */
class ReportService {

	/** Eingebaute Standardnamen für feste Kostenstellen. */
	private const BUILTIN = [
		'01' => 'Ideeller Bereich',
		'11' => 'Verbandszeitung',
	];

	/**
	 * Freigrenze für den wirtschaftlichen Geschäftsbetrieb (§ 64 Abs. 3 AO),
	 * Stand seit 2020: 45.000 € Bruttoeinnahmen/Jahr, als SUMME über alle
	 * wirtschaftlichen Aktivitäten. Ändert sich der Gesetzeswert, hier
	 * anpassen (Phase 3 des Sphären-Konzepts macht diesen Wert konfigurierbar).
	 */
	private const WIRTSCHAFTLICH_FREIGRENZE_CENTS = 4500000;

	/** Anzeigenamen der vier steuerlichen Sphären, siehe Account::SPHERES. */
	private const SPHERE_LABELS = [
		'ideell' => 'Ideeller Bereich',
		'vermoegensverwaltung' => 'Vermögensverwaltung',
		'zweckbetrieb' => 'Zweckbetrieb',
		'wirtschaftlich' => 'Wirtschaftlicher Geschäftsbetrieb',
	];

	/** Anzeigenamen der drei Rücklagen-Arten, siehe Account::RESERVE_KINDS. */
	private const RESERVE_LABELS = [
		'frei' => 'Freie Rücklage',
		'zweckgebunden' => 'Zweckgebundene Rücklage',
		'wiederbeschaffung' => 'Wiederbeschaffungsrücklage',
	];

	public function __construct(
		private AccountMapper $accountMapper,
		private JournalLineMapper $lineMapper,
		private CostCenterMapper $costCenterMapper,
		private JournalMapper $journalMapper,
		private IConfig $config,
	) {
	}

	/** Erlaubte Werte der Einstellung `cost_center_mode`. */
	public const MODES = ['group', 'account', 'manual'];

	/**
	 * Kostenstellen-Modus:
	 *  - 'group'   – 2. Zahlengruppe der Kontonummer (Standard, Altbestand)
	 *  - 'account' – jedes Erfolgskonto ist seine eigene Kostenstelle
	 *  - 'manual'  – frei angelegte Kostenstellen, Konten werden ihnen
	 *                ausdrücklich zugeordnet (Account::$costCenterId)
	 *
	 * Die ersten beiden leiten die Kostenstelle aus dem Kontenrahmen ab und
	 * setzen damit voraus, dass er entsprechend aufgebaut ist. 'manual' macht
	 * keine solche Annahme.
	 */
	public function costCenterMode(): string {
		$mode = $this->config->getAppValue(Application::APP_ID, 'cost_center_mode', 'group');
		return in_array($mode, self::MODES, true) ? $mode : 'group';
	}

	/**
	 * Liefert die Kostenstelle (2-stellige Zahl) aus einer Kontonummer oder null.
	 */
	public static function costCode(string $number): ?string {
		$parts = preg_split('/\s+/', trim($number)) ?: [];
		if (count($parts) >= 2 && preg_match('/^\d{2}$/', $parts[1])) {
			return $parts[1];
		}
		return null;
	}

	/**
	 * Gruppenschlüssel einer frei angelegten Kostenstelle (Modus 'manual'),
	 * oder null für „ohne Kostenstelle".
	 *
	 * Eine Zuordnung auf eine inzwischen gelöschte Kostenstelle zählt als
	 * „ohne": das Schema kennt keine Fremdschlüssel, und eine Gruppe ohne
	 * Namen wäre im Bericht nicht zu gebrauchen.
	 *
	 * @param array<int, mixed> $defined vorhandene Kostenstellen, indiziert nach ID
	 */
	public static function manualGroupKey(?int $costCenterId, array $defined): ?string {
		if ($costCenterId === null || !isset($defined[$costCenterId])) {
			return null;
		}
		return 'cc-' . $costCenterId;
	}

	/**
	 * Auswertung Einnahmen/Ausgaben/Ergebnis je Kostenstelle.
	 *
	 * @return array{costCenters: array<int, array<string,mixed>>, totals: array<string,float>}
	 */
	public function costCenterReport(string $userId, ?int $year = null): array {
		$accounts = $this->accountMapper->findAll($userId);
		$from = null;
		$to = null;
		if ($year !== null && $year > 0) {
			$from = sprintf('%04d-01-01', $year);
			$to = sprintf('%04d-12-31', $year);
		}
		$sums = $this->lineMapper->sumByAccount($userId, $from, $to);
		$mode = $this->costCenterMode();

		$names = [];
		$defined = [];
		foreach ($this->costCenterMapper->findAll($userId) as $cc) {
			$names[$cc->getCode()] = $cc->getName();
			$defined[$cc->getId()] = $cc;
		}

		$groups = [];
		if ($mode === 'manual') {
			// Alle angelegten Kostenstellen erscheinen, auch ohne zugeordnetes
			// Konto: sonst wäre eine gerade angelegte Kostenstelle im Bericht
			// unsichtbar und man könnte nicht sehen, dass die Zuordnung fehlt.
			foreach ($defined as $cc) {
				$groups[(string)self::manualGroupKey($cc->getId(), $defined)] = [
					'code' => $cc->getCode(),
					'name' => $cc->getName(),
					'incomeCents' => 0,
					'expenseCents' => 0,
					'accounts' => [],
				];
			}
		}
		foreach ($accounts as $a) {
			// Erfolgswirksam sind alle Nicht-Geldkonten außer Eigenkapital
			// (siehe Account::isResultRelevant()); Seite nach Kontonatur.
			if (!$a->isResultRelevant()) {
				continue;
			}
			$type = $a->getType();
			if ($mode === 'account') {
				// Jedes Erfolgskonto mit Bewegung ist seine eigene Kostenstelle.
				$id = $a->getId();
				if (($sums[$id]['debit'] ?? 0) === 0 && ($sums[$id]['credit'] ?? 0) === 0) {
					continue;
				}
				$code = $a->getNumber();
				$key = 'acc-' . $id;
				if (!isset($groups[$key])) {
					$groups[$key] = [
						'code' => $code,
						'name' => mb_substr($a->getName(), 0, 255),
						'incomeCents' => 0,
						'expenseCents' => 0,
						'accounts' => [],
					];
				}
			} elseif ($mode === 'manual') {
				$manualKey = self::manualGroupKey($a->getCostCenterId(), $defined);
				$code = $manualKey !== null ? $defined[$a->getCostCenterId()]->getCode() : null;
				$key = $manualKey ?? '';
			} else {
				$code = self::costCode($a->getNumber());
				$key = $code ?? '';
			}
			if (!isset($groups[$key])) {
				$groups[$key] = [
					'code' => $code,
					'name' => $this->resolveName($code, $names),
					'incomeCents' => 0,
					'expenseCents' => 0,
					'accounts' => [],
				];
			}
			$id = $a->getId();
			$debit = $sums[$id]['debit'] ?? 0;
			$credit = $sums[$id]['credit'] ?? 0;
			if ($a->isCreditNature()) {
				$balCents = $credit - $debit;
				$groups[$key]['incomeCents'] += $balCents;
			} else {
				$balCents = $debit - $credit;
				$groups[$key]['expenseCents'] += $balCents;
			}
			// Im Modus 'group' interessieren nur bewegte Konten; wo die Zuordnung
			// ausdrücklich ist ('account', 'manual'), gehören auch Konten ohne
			// Bewegung sichtbar dazu.
			if ($balCents !== 0 || $mode !== 'group') {
				$groups[$key]['accounts'][] = [
					'accountId' => $id,
					'number' => $a->getNumber(),
					'name' => $a->getName(),
					'type' => $type,
					'balance' => $balCents / 100,
				];
			}
		}

		// Sortierung: nach Code aufsteigend, "(ohne Kostenstelle)" ans Ende
		uasort($groups, static function ($a, $b) {
			$ca = $a['code'] ?? 'zzz';
			$cb = $b['code'] ?? 'zzz';
			return strcmp($ca, $cb);
		});

		$result = [];
		$totalIncome = 0;
		$totalExpense = 0;
		foreach ($groups as $g) {
			$totalIncome += $g['incomeCents'];
			$totalExpense += $g['expenseCents'];
			usort($g['accounts'], static fn ($x, $y) => strcmp($x['number'], $y['number']));
			$result[] = [
				'code' => $g['code'],
				'name' => $g['name'],
				'income' => $g['incomeCents'] / 100,
				'expense' => $g['expenseCents'] / 100,
				'result' => ($g['incomeCents'] - $g['expenseCents']) / 100,
				'accounts' => $g['accounts'],
			];
		}

		return [
			'mode' => $mode,
			'costCenters' => $result,
			'totals' => [
				'income' => $totalIncome / 100,
				'expense' => $totalExpense / 100,
				'result' => ($totalIncome - $totalExpense) / 100,
			],
		];
	}

	/**
	 * Auswertung Einnahmen/Ausgaben/Ergebnis je steuerlicher Sphäre, inkl.
	 * Freigrenzen-Status des wirtschaftlichen Geschäftsbetriebs. Kein Ersatz
	 * für Steuerberatung – reine Sichtbarkeits-/Frühwarnhilfe, siehe HANDBUCH.md.
	 *
	 * @return array{spheres: array<int, array<string,mixed>>, totals: array<string,float>, freigrenze: array<string,mixed>}
	 */
	public function sphereReport(string $userId, ?int $year = null): array {
		$accounts = $this->accountMapper->findAll($userId);
		$from = null;
		$to = null;
		if ($year !== null && $year > 0) {
			$from = sprintf('%04d-01-01', $year);
			$to = sprintf('%04d-12-31', $year);
		}
		$sums = $this->lineMapper->sumByAccount($userId, $from, $to);

		// Alle vier Sphären + „nicht zugeordnet" immer anlegen, auch ohne
		// Bewegung – macht offene Zuordnungen im Bericht sofort sichtbar.
		$groups = [];
		foreach (array_merge(Account::SPHERES, [null]) as $code) {
			$groups[$code ?? ''] = [
				'code' => $code,
				'name' => $code !== null ? self::SPHERE_LABELS[$code] : '(nicht zugeordnet)',
				'incomeCents' => 0,
				'expenseCents' => 0,
				'accounts' => [],
			];
		}

		foreach ($accounts as $a) {
			if (!$a->isResultRelevant()) {
				continue;
			}
			$key = $a->getSphere() ?? '';
			$id = $a->getId();
			$debit = $sums[$id]['debit'] ?? 0;
			$credit = $sums[$id]['credit'] ?? 0;
			if ($a->isCreditNature()) {
				$balCents = $credit - $debit;
				$groups[$key]['incomeCents'] += $balCents;
			} else {
				$balCents = $debit - $credit;
				$groups[$key]['expenseCents'] += $balCents;
			}
			if ($balCents !== 0) {
				$groups[$key]['accounts'][] = [
					'accountId' => $id,
					'number' => $a->getNumber(),
					'name' => $a->getName(),
					'type' => $a->getType(),
					'balance' => $balCents / 100,
				];
			}
		}

		$result = [];
		$totalIncome = 0;
		$totalExpense = 0;
		$commercialIncomeCents = 0;
		foreach ($groups as $g) {
			$totalIncome += $g['incomeCents'];
			$totalExpense += $g['expenseCents'];
			if ($g['code'] === 'wirtschaftlich') {
				$commercialIncomeCents = $g['incomeCents'];
			}
			usort($g['accounts'], static fn ($x, $y) => strcmp($x['number'], $y['number']));
			$result[] = [
				'code' => $g['code'],
				'name' => $g['name'],
				'income' => $g['incomeCents'] / 100,
				'expense' => $g['expenseCents'] / 100,
				'result' => ($g['incomeCents'] - $g['expenseCents']) / 100,
				'accounts' => $g['accounts'],
			];
		}

		$ratio = $commercialIncomeCents / self::WIRTSCHAFTLICH_FREIGRENZE_CENTS;
		$level = $ratio >= 1 ? 'over' : ($ratio >= 0.7 ? 'warn' : 'ok');

		return [
			'spheres' => $result,
			'totals' => [
				'income' => $totalIncome / 100,
				'expense' => $totalExpense / 100,
				'result' => ($totalIncome - $totalExpense) / 100,
			],
			'freigrenze' => [
				'thresholdCents' => self::WIRTSCHAFTLICH_FREIGRENZE_CENTS,
				'threshold' => self::WIRTSCHAFTLICH_FREIGRENZE_CENTS / 100,
				'incomeCents' => $commercialIncomeCents,
				'income' => $commercialIncomeCents / 100,
				'ratio' => $ratio,
				'level' => $level,
			],
		];
	}

	/**
	 * Rücklagen-Salden je Art (§ 62 AO: frei/zweckgebunden/Wiederbeschaffung).
	 * Rücklagen sind Eigenkapital-Konten mit gesetztem `reserveKind` – die
	 * Zuweisung selbst ist eine normale Buchung (Experten-Modus, Eigenkapital-
	 * zu-Eigenkapital-Umbuchung), kein eigener Mechanismus. Bestandsgröße wie
	 * jedes Eigenkapitalkonto, daher kumulierter Saldo ohne Jahresfilter.
	 *
	 * @return array{reserves: array<int, array<string,mixed>>, total: float}
	 */
	public function reserveReport(string $userId): array {
		$accounts = $this->accountMapper->findAll($userId);
		$sums = $this->lineMapper->sumByAccount($userId);

		$groups = [];
		foreach (Account::RESERVE_KINDS as $kind) {
			$groups[$kind] = [
				'kind' => $kind,
				'name' => self::RESERVE_LABELS[$kind],
				'balanceCents' => 0,
				'accounts' => [],
			];
		}

		foreach ($accounts as $a) {
			$kind = $a->getReserveKind();
			if ($kind === null || $a->getType() !== 'equity') {
				continue;
			}
			$id = $a->getId();
			$debit = $sums[$id]['debit'] ?? 0;
			$credit = $sums[$id]['credit'] ?? 0;
			// Eigenkapital ist Haben-Natur.
			$balCents = $credit - $debit;
			$groups[$kind]['balanceCents'] += $balCents;
			$groups[$kind]['accounts'][] = [
				'accountId' => $id,
				'number' => $a->getNumber(),
				'name' => $a->getName(),
				'balance' => $balCents / 100,
			];
		}

		$result = [];
		$totalCents = 0;
		foreach ($groups as $g) {
			$totalCents += $g['balanceCents'];
			usort($g['accounts'], static fn ($x, $y) => strcmp($x['number'], $y['number']));
			$result[] = [
				'kind' => $g['kind'],
				'name' => $g['name'],
				'balance' => $g['balanceCents'] / 100,
				'accounts' => $g['accounts'],
			];
		}

		return [
			'reserves' => $result,
			'total' => $totalCents / 100,
		];
	}

	/**
	 * Einnahmen/Ausgaben/Ergebnis je Jahr, für ein Mehrjahres-Trend-Diagramm
	 * (Sitzungspräsentation). Bewusst eine eigene, schlanke Methode statt
	 * Wiederverwendung von ExportController::multiyear() – die CSV-Methode
	 * bleibt unangetastet, um deren Regressionsrisiko nicht zu erhöhen.
	 *
	 * @return array{years: array<int, array{year:int,income:float,expense:float,result:float}>}
	 */
	public function multiyearTrend(string $userId): array {
		$years = $this->journalMapper->distinctYears($userId);
		sort($years);
		$accounts = $this->accountMapper->findAll($userId);

		$rows = [];
		foreach ($years as $y) {
			$sums = $this->lineMapper->sumByAccount($userId, sprintf('%04d-01-01', $y), sprintf('%04d-12-31', $y));
			$incomeCents = 0;
			$expenseCents = 0;
			foreach ($accounts as $a) {
				if (!$a->isResultRelevant()) {
					continue;
				}
				$id = $a->getId();
				$debit = $sums[$id]['debit'] ?? 0;
				$credit = $sums[$id]['credit'] ?? 0;
				if ($a->isCreditNature()) {
					$incomeCents += $credit - $debit;
				} else {
					$expenseCents += $debit - $credit;
				}
			}
			$rows[] = [
				'year' => $y,
				'income' => $incomeCents / 100,
				'expense' => $expenseCents / 100,
				'result' => ($incomeCents - $expenseCents) / 100,
			];
		}

		return ['years' => $rows];
	}

	/**
	 * @param array<string,string> $names
	 */
	private function resolveName(?string $code, array $names): string {
		if ($code === null) {
			return '(ohne Kostenstelle)';
		}
		return $names[$code] ?? self::BUILTIN[$code] ?? ('Kostenstelle ' . $code);
	}
}
