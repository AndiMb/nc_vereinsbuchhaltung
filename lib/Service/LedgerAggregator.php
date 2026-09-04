<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\AccountNature;

/**
 * Die Rechenregeln, nach denen aus Buchungssummen eine Auswertung wird.
 *
 * Alle Auswertungen der App – Saldenliste, Einnahmen-/Ausgabenrechnung,
 * Kassenbericht, Kurzbericht, Mehrjahresübersicht, Kostenstellen, Sphären,
 * Finanzplan – bauen auf denselben Entscheidungen auf, die {@see AccountNature}
 * beantwortet: zählt das Konto ins Ergebnis, auf welcher Seite steht es, und
 * wird sein Saldo über die Jahresgrenze fortgeschrieben.
 *
 * Was daraus an Vorzeichen und Summen folgt, stand bisher an acht Stellen
 * ausgeschrieben – in ExportController, JournalController und ReportService,
 * jeweils leicht anders formuliert. Eine Änderung an der Ergebnisdefinition
 * musste an allen acht nachgezogen werden, und die einzige Absicherung dagegen
 * war Sorgfalt. Die Kommentare dort verwiesen aufeinander („wie report()",
 * „spiegelt BudgetController::index()"), was den Zusammenhang zwar festhielt,
 * aber nicht erzwang.
 *
 * Bewusst statisch und ohne Datenbankzugriff: die Summen kommen als fertige
 * Karte aus {@see \OCA\Vereinsbuchhaltung\Db\JournalLineMapper::sumByAccount()}
 * herein. Dadurch ist jede Regel hier ohne Nextcloud-Instanz prüfbar – vorher
 * war sie das nirgends, weil sie in Controllern lag.
 *
 * Alle Beträge sind Cent (int). Gerundet wird erst in der Ausgabe.
 */
final class LedgerAggregator {

	/**
	 * Soll- und Habenbewegung eines Kontos im Zeitraum.
	 *
	 * @param array<int, array{debit:int, credit:int}> $sums Summen je Konto-ID
	 * @return array{debit:int, credit:int}
	 */
	public static function movement(AccountNature $account, array $sums): array {
		$id = $account->getId();
		return [
			'debit' => $sums[$id]['debit'] ?? 0,
			'credit' => $sums[$id]['credit'] ?? 0,
		];
	}

	/**
	 * Bewegungssaldo eines Kontos, vorzeichenbehaftet nach seiner Natur.
	 *
	 * Ein Habenkonto (Einnahmen, Verbindlichkeit, Eigenkapital) hat einen
	 * positiven Saldo, wenn es im Haben steht; ein Sollkonto (Ausgaben,
	 * Aktiva), wenn es im Soll steht. So ist der Wert für beide Seiten
	 * gleichsinnig „so viel ist zusammengekommen bzw. angefallen".
	 *
	 * @param array<int, array{debit:int, credit:int}> $sums
	 */
	public static function net(AccountNature $account, array $sums): int {
		['debit' => $debit, 'credit' => $credit] = self::movement($account, $sums);
		return $account->isCreditNature() ? $credit - $debit : $debit - $credit;
	}

	/**
	 * Bestand eines Geldkontos: Soll − Haben über den Zeitraum der übergebenen
	 * Summen. Für einen Kontostand müssen das kumulierte Summen bis zum
	 * Stichtag sein (sumByAccount($userId, null, $stichtag)).
	 *
	 * @param array<int, array{debit:int, credit:int}> $cumSums
	 */
	public static function stock(AccountNature $account, array $cumSums): int {
		['debit' => $debit, 'credit' => $credit] = self::movement($account, $cumSums);
		return $debit - $credit;
	}

	/**
	 * Saldo für die Saldenliste.
	 *
	 * Geldkonten zeigen ihren Kontostand (kumulativ, deshalb die zweite
	 * Summenkarte), alle anderen Konten ihre Bewegung im Zeitraum. Ohne
	 * Jahresfilter sind beide Karten dieselbe; dann fallen die Fälle zusammen.
	 *
	 * @param array<int, array{debit:int, credit:int}> $moveSums Bewegung im Zeitraum
	 * @param array<int, array{debit:int, credit:int}> $balSums kumuliert bis Zeitraumende
	 */
	public static function listBalance(AccountNature $account, array $moveSums, array $balSums): int {
		return self::net($account, $account->isStockAccount() ? $balSums : $moveSums);
	}

	/**
	 * Erfolgsrechnung: alle erfolgswirksamen Konten, nach Seiten getrennt.
	 *
	 * Die Zuordnung zu Einnahmen oder Ausgaben folgt der Kontonatur, nicht dem
	 * Vorzeichen: ein Einnahmenkonto mit negativem Saldo (mehr Rückzahlungen
	 * als Einnahmen) bleibt auf der Einnahmenseite und mindert sie dort. Würde
	 * man nach Vorzeichen einsortieren, stimmten zwar die Summen, aber der
	 * Bericht wiese eine Beitragsrückzahlung unter „Ausgaben" aus.
	 *
	 * Konten ohne Bewegung sind enthalten; wer sie nicht zeigen will, filtert
	 * auf `cents !== 0`. Die Auswertungen sind sich darin nicht einig – die
	 * Kostenstellenübersicht etwa zeigt ausdrücklich zugeordnete Konten auch
	 * ohne Bewegung –, deshalb entscheidet das der Aufrufer.
	 *
	 * @template T of AccountNature
	 * @param iterable<T> $accounts
	 * @param array<int, array{debit:int, credit:int}> $sums
	 * @return array{
	 *     income: list<array{account:T, cents:int}>,
	 *     expense: list<array{account:T, cents:int}>,
	 *     incomeCents:int, expenseCents:int, resultCents:int
	 * }
	 */
	public static function incomeExpense(iterable $accounts, array $sums): array {
		$income = [];
		$expense = [];
		$incomeCents = 0;
		$expenseCents = 0;
		foreach ($accounts as $account) {
			if (!$account->isResultRelevant()) {
				continue;
			}
			$cents = self::net($account, $sums);
			if ($account->isCreditNature()) {
				$income[] = ['account' => $account, 'cents' => $cents];
				$incomeCents += $cents;
			} else {
				$expense[] = ['account' => $account, 'cents' => $cents];
				$expenseCents += $cents;
			}
		}
		return [
			'income' => $income,
			'expense' => $expense,
			'incomeCents' => $incomeCents,
			'expenseCents' => $expenseCents,
			'resultCents' => $incomeCents - $expenseCents,
		];
	}

	/**
	 * Vermögensübersicht: je Geldkonto der Bestand am Anfang und am Ende.
	 *
	 * Konten, die zu beiden Zeitpunkten bei null stehen, bleiben weg – ein nie
	 * benutztes Bankkonto soll den Kassenbericht nicht verlängern.
	 *
	 * @template T of AccountNature
	 * @param iterable<T> $accounts
	 * @param array<int, array{debit:int, credit:int}> $cumStart kumuliert bis zum Tag vor Beginn
	 * @param array<int, array{debit:int, credit:int}> $cumEnd kumuliert bis Ende
	 * @return array{rows: list<array{account:T, start:int, end:int}>, startCents:int, endCents:int}
	 */
	public static function wealthRows(iterable $accounts, array $cumStart, array $cumEnd): array {
		$rows = [];
		$startTotal = 0;
		$endTotal = 0;
		foreach ($accounts as $account) {
			if (!$account->isStockAccount()) {
				continue;
			}
			$start = self::stock($account, $cumStart);
			$end = self::stock($account, $cumEnd);
			if ($start === 0 && $end === 0) {
				continue;
			}
			$rows[] = ['account' => $account, 'start' => $start, 'end' => $end];
			$startTotal += $start;
			$endTotal += $end;
		}
		return ['rows' => $rows, 'startCents' => $startTotal, 'endCents' => $endTotal];
	}

	/**
	 * Geldbestand für die Kopfzeile: eine Zahl über alle Geldkonten.
	 *
	 * Getrennt von {@see wealth()}, weil beide verschiedene Fragen
	 * beantworten. Die Vermögensübersicht muss jeden Euro zeigen – auch den
	 * auf einem Festgeld- oder Durchlaufkonto. Die Kopfzeile beantwortet
	 * dagegen „wie viel Geld habe ich gerade", und dafür darf ein Konto
	 * abgewählt sein (Account::countsInCashTotal()).
	 *
	 * Beide Werte kommen zusammen zurück, damit die Oberfläche die Summe der
	 * Geldkonten-Tabelle und den Bestand der Kopfzeile nebeneinander zeigen
	 * kann, ohne dieselbe Schleife dreimal nachzubauen – und damit auffällt,
	 * wenn sie auseinanderlaufen.
	 *
	 * @param iterable<AccountNature> $accounts
	 * @param array<int, array{debit:int, credit:int}> $cumSums kumuliert bis zum Stichtag
	 * @return array{cents:int, count:int, allCents:int, allCount:int}
	 */
	public static function cashTotal(iterable $accounts, array $cumSums): array {
		$cents = 0;
		$count = 0;
		$allCents = 0;
		$allCount = 0;
		foreach ($accounts as $account) {
			if (!$account->isStockAccount()) {
				continue;
			}
			$stock = self::stock($account, $cumSums);
			$allCents += $stock;
			$allCount++;
			if ($account->countsInCashTotal()) {
				$cents += $stock;
				$count++;
			}
		}
		return ['cents' => $cents, 'count' => $count, 'allCents' => $allCents, 'allCount' => $allCount];
	}

	/**
	 * Vermögen zu einem Stichtag: die Summe aller Geldkonten-Bestände.
	 *
	 * @param iterable<AccountNature> $accounts
	 * @param array<int, array{debit:int, credit:int}> $cumSums kumuliert bis zum Stichtag
	 */
	public static function wealth(iterable $accounts, array $cumSums): int {
		$total = 0;
		foreach ($accounts as $account) {
			if ($account->isStockAccount()) {
				$total += self::stock($account, $cumSums);
			}
		}
		return $total;
	}

	/**
	 * Soll-Ist-Vergleich des Finanzplans.
	 *
	 * Die Reihenfolge ist die der übergebenen Konten – wer nach Kontonummer
	 * sortiert ausgeben will, sortiert die Konten vorher; das spart dieser
	 * Klasse die Kenntnis der Beschriftungsfelder.
	 *
	 * @template T of AccountNature
	 * @param iterable<T> $accounts
	 * @param array<int, array{debit:int, credit:int}> $sums Ist-Bewegung des Planjahres
	 * @param array<int, array{amount?:int, note?:string}> $plan Planwerte je Konto-ID
	 * @return array{
	 *     rows: list<array{account:T, planCents:int, actualCents:int, note:string}>,
	 *     planIncomeCents:int, actualIncomeCents:int,
	 *     planExpenseCents:int, actualExpenseCents:int
	 * }
	 */
	public static function planActual(iterable $accounts, array $sums, array $plan): array {
		$rows = [];
		$planIncome = 0;
		$actualIncome = 0;
		$planExpense = 0;
		$actualExpense = 0;
		foreach ($accounts as $account) {
			if (!$account->isBudgetable()) {
				continue;
			}
			$actualCents = self::net($account, $sums);
			$planCents = $plan[$account->getId()]['amount'] ?? 0;
			$rows[] = [
				'account' => $account,
				'planCents' => $planCents,
				'actualCents' => $actualCents,
				'note' => (string)($plan[$account->getId()]['note'] ?? ''),
			];
			if ($account->isCreditNature()) {
				$planIncome += $planCents;
				$actualIncome += $actualCents;
			} else {
				$planExpense += $planCents;
				$actualExpense += $actualCents;
			}
		}
		return [
			'rows' => $rows,
			'planIncomeCents' => $planIncome,
			'actualIncomeCents' => $actualIncome,
			'planExpenseCents' => $planExpense,
			'actualExpenseCents' => $actualExpense,
		];
	}
}
