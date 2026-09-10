<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\PeriodRule;
use PHPUnit\Framework\TestCase;

/**
 * Das Raster, nach dem Geschäftsjahre entstehen (Issue #8).
 *
 * Ein Fehler hier meldet sich nicht, sondern zeigt sich als Buchung im
 * falschen Geschäftsjahr – und damit als falscher Kassenbericht. Die beiden
 * Zusicherungen, auf die sich der PeriodService verlässt, sind deshalb
 * eigens geprüft: die Perioden überlappen einander nicht, und zwischen ihnen
 * klafft keine Lücke.
 */
class PeriodRuleTest extends TestCase {

	/**
	 * @return array<string, array{0:string, 1:string, 2:string, 3:string}>
	 */
	public static function raster(): array {
		return [
			'Kalenderjahr' => ['calendar', '2026-03-15', '2026-01-01', '2026-12-31'],
			'Kalenderjahr, erster Tag' => ['calendar', '2026-01-01', '2026-01-01', '2026-12-31'],
			'Kalenderjahr, letzter Tag' => ['calendar', '2026-12-31', '2026-01-01', '2026-12-31'],
			'Okt-Sep, vor dem Wechsel' => ['oct-sep', '2026-09-30', '2025-10-01', '2026-09-30'],
			'Okt-Sep, nach dem Wechsel' => ['oct-sep', '2026-10-01', '2026-10-01', '2027-09-30'],
			'Okt-Sep, Jahresmitte' => ['oct-sep', '2026-01-15', '2025-10-01', '2026-09-30'],
			'Aug-Jul (Schuljahr)' => ['aug-jul', '2026-07-31', '2025-08-01', '2026-07-31'],
			'Aug-Jul, Schuljahresbeginn' => ['aug-jul', '2026-08-01', '2026-08-01', '2027-07-31'],
			'Wintersemester' => ['semester', '2026-01-15', '2025-10-01', '2026-03-31'],
			'Sommersemester' => ['semester', '2026-04-01', '2026-04-01', '2026-09-30'],
			'Semestergrenze' => ['semester', '2026-03-31', '2025-10-01', '2026-03-31'],
		];
	}

	/**
	 * @dataProvider raster
	 */
	public function testGrenzenDerPeriodeUmEinDatum(string $preset, string $datum, string $von, string $bis): void {
		$rule = PeriodRule::validate(['preset' => $preset]);
		$this->assertSame([$von, $bis], PeriodRule::containing($rule, $datum));
	}

	/**
	 * Der 29. Februar existiert nur im Schaltjahr. Wer zum 29. Februar
	 * beginnt, beginnt in den übrigen Jahren am 28. – und zwar nur dort:
	 * gerechnet wird immer mit dem ursprünglichen Starttag, sonst zöge ein
	 * kurzer Monat den Stichtag dauerhaft nach vorn.
	 */
	public function testSchalttagAlsStichtag(): void {
		$rule = PeriodRule::validate(['preset' => 'custom', 'startDay' => 29, 'startMonth' => 2, 'lengthMonths' => 12]);

		$this->assertSame(['2028-02-29', '2029-02-27'], PeriodRule::containing($rule, '2028-06-01'));
		$this->assertSame(['2029-02-28', '2030-02-27'], PeriodRule::containing($rule, '2029-06-01'));
		// Entscheidend: 2032 ist wieder ein Schaltjahr, und der Stichtag ist
		// wieder der 29. – der kurze Februar 2029 hat ihn nicht verschoben.
		$this->assertSame('2032-02-29', PeriodRule::containing($rule, '2032-06-01')[0]);
	}

	/**
	 * Ein Starttag über der Monatslänge wird auf den Monatsletzten gekürzt.
	 */
	public function testStarttagWirdAufMonatslaengeGekuerzt(): void {
		$rule = PeriodRule::validate(['preset' => 'custom', 'startDay' => 31, 'startMonth' => 2, 'lengthMonths' => 12]);
		$this->assertSame('2026-02-28', PeriodRule::containing($rule, '2026-06-01')[0]);

		$quartal = PeriodRule::validate(['preset' => 'custom', 'startDay' => 31, 'startMonth' => 1, 'lengthMonths' => 3]);
		$this->assertSame(['2026-01-31', '2026-04-29'], PeriodRule::containing($quartal, '2026-02-15'));
		$this->assertSame(['2026-04-30', '2026-07-30'], PeriodRule::containing($quartal, '2026-05-15'));
	}

	/**
	 * @return array<string, array{0:array<string,mixed>}>
	 */
	public static function regeln(): array {
		return [
			'Kalenderjahr' => [['preset' => 'calendar']],
			'Okt-Sep' => [['preset' => 'oct-sep']],
			'Aug-Jul' => [['preset' => 'aug-jul']],
			'Semester' => [['preset' => 'semester']],
			'monatlich' => [['preset' => 'custom', 'startDay' => 1, 'startMonth' => 1, 'lengthMonths' => 1]],
			'Quartale ab Mai' => [['preset' => 'custom', 'startDay' => 1, 'startMonth' => 5, 'lengthMonths' => 3]],
			'krummer Stichtag' => [['preset' => 'custom', 'startDay' => 15, 'startMonth' => 7, 'lengthMonths' => 6]],
			'Monatsletzter' => [['preset' => 'custom', 'startDay' => 31, 'startMonth' => 3, 'lengthMonths' => 4]],
		];
	}

	/**
	 * Die Kette ist lückenlos und überlappungsfrei – über einen Schaltjahres-
	 * Zyklus hinweg, mit jedem der vorgesehenen Startpunkte. Auf genau diese
	 * beiden Eigenschaften verlässt sich der PeriodService: sonst gehörte ein
	 * Buchungsdatum zu keinem oder zu zwei Geschäftsjahren.
	 *
	 * @dataProvider regeln
	 * @param array<string,mixed> $raw
	 */
	public function testKetteIstLueckenlosUndUeberlappungsfrei(array $raw): void {
		$rule = PeriodRule::validate($raw);

		[$von, $bis] = PeriodRule::containing($rule, '2024-01-01');
		$this->assertLessThanOrEqual($bis, $von, 'Eine Periode darf nicht rückwärts laufen');

		for ($i = 0; $i < 40; $i++) {
			$naechsterBeginn = PeriodRule::nextDay($bis);
			[$naechstesVon, $naechstesBis] = PeriodRule::containing($rule, $naechsterBeginn);

			$this->assertSame(
				$naechsterBeginn,
				$naechstesVon,
				'Zwischen zwei Perioden darf kein Tag liegen und keiner doppelt zählen',
			);
			$this->assertLessThan($naechstesBis, $naechstesVon, 'Jede Periode ist mindestens zwei Tage lang');

			$von = $naechstesVon;
			$bis = $naechstesBis;
		}
	}

	/**
	 * Jeder Tag eines langen Zeitraums gehört zu genau der Periode, deren
	 * Grenzen ihn einschließen – die Umkehrprobe zur Kettenprüfung.
	 */
	public function testJederTagLiegtInSeinerPeriode(): void {
		$rule = PeriodRule::validate(['preset' => 'semester']);
		$tag = new \DateTimeImmutable('2027-11-20');

		for ($i = 0; $i < 400; $i++) {
			$datum = $tag->modify('+' . $i . ' day')->format('Y-m-d');
			[$von, $bis] = PeriodRule::containing($rule, $datum);
			$this->assertGreaterThanOrEqual($von, $datum);
			$this->assertLessThanOrEqual($bis, $datum);
		}
	}

	/**
	 * @return array<string, array{0:array<string,mixed>, 1:string, 2:string}>
	 */
	public static function bezeichnungen(): array {
		return [
			'Kalenderjahr bleibt die blanke Zahl' => [['preset' => 'calendar'], '2026-01-01', '2026'],
			'abweichendes Jahr nennt beide' => [['preset' => 'oct-sep'], '2025-10-01', '2025/26'],
			'Schuljahr' => [['preset' => 'aug-jul'], '2025-08-01', '2025/26'],
			'Wintersemester' => [['preset' => 'semester'], '2025-10-01', '2025/26-1'],
			'Sommersemester' => [['preset' => 'semester'], '2026-04-01', '2025/26-2'],
			'Jahrhundertwechsel' => [['preset' => 'oct-sep'], '2099-10-01', '2099/00'],
			'Quartal im Kalenderjahr' => [
				['preset' => 'custom', 'startDay' => 1, 'startMonth' => 1, 'lengthMonths' => 3],
				'2026-07-01',
				'2026-3',
			],
		];
	}

	/**
	 * @dataProvider bezeichnungen
	 * @param array<string,mixed> $raw
	 */
	public function testBezeichnungsvorschlag(array $raw, string $beginn, string $erwartet): void {
		$this->assertSame($erwartet, PeriodRule::proposeLabel(PeriodRule::validate($raw), $beginn));
	}

	/**
	 * Ein Preset gewinnt immer über mitgeschickte Zahlen. Sonst könnte eine
	 * gespeicherte Regel „oct-sep" heißen und etwas anderes bedeuten.
	 */
	public function testPresetSchlaegtEinzelwerte(): void {
		$rule = PeriodRule::validate(['preset' => 'oct-sep', 'startMonth' => 3, 'lengthMonths' => 1]);
		$this->assertSame(
			['preset' => 'oct-sep', 'startDay' => 1, 'startMonth' => 10, 'lengthMonths' => 12],
			$rule,
		);
	}

	/**
	 * Umgekehrt: wer von Hand genau das Kalenderjahr einstellt, soll auf der
	 * Einstellungsseite nicht „Eigene Regel" angezeigt bekommen.
	 */
	public function testEigeneRegelWirdAlsPresetErkannt(): void {
		$rule = PeriodRule::validate(['preset' => 'custom', 'startDay' => 1, 'startMonth' => 1, 'lengthMonths' => 12]);
		$this->assertSame('calendar', $rule['preset']);
	}

	/**
	 * @return array<string, array{0:array<string,mixed>}>
	 */
	public static function unbrauchbareRegeln(): array {
		return [
			'unbekanntes Preset' => [['preset' => 'quartalsweise']],
			'Starttag 0' => [['preset' => 'custom', 'startDay' => 0, 'startMonth' => 1, 'lengthMonths' => 12]],
			'Starttag 32' => [['preset' => 'custom', 'startDay' => 32, 'startMonth' => 1, 'lengthMonths' => 12]],
			'Monat 0' => [['preset' => 'custom', 'startDay' => 1, 'startMonth' => 0, 'lengthMonths' => 12]],
			'Monat 13' => [['preset' => 'custom', 'startDay' => 1, 'startMonth' => 13, 'lengthMonths' => 12]],
			'Länge 0' => [['preset' => 'custom', 'startDay' => 1, 'startMonth' => 1, 'lengthMonths' => 0]],
			// 5 teilt die 12 nicht: der Zyklus liefe gegen den Kalender.
			'Länge 5' => [['preset' => 'custom', 'startDay' => 1, 'startMonth' => 1, 'lengthMonths' => 5]],
			'Länge 24' => [['preset' => 'custom', 'startDay' => 1, 'startMonth' => 1, 'lengthMonths' => 24]],
		];
	}

	/**
	 * @dataProvider unbrauchbareRegeln
	 * @param array<string,mixed> $raw
	 */
	public function testUnbrauchbareRegelWirdAbgelehnt(array $raw): void {
		$this->expectException(\InvalidArgumentException::class);
		PeriodRule::validate($raw);
	}

	public function testUnmoeglichesDatumWirdAbgelehnt(): void {
		$this->expectException(\InvalidArgumentException::class);
		PeriodRule::containing(PeriodRule::DEFAULT_RULE, '2026-02-30');
	}

	public function testTagesrechnungUeberMonatsUndJahresgrenze(): void {
		$this->assertSame('2026-12-31', PeriodRule::previousDay('2027-01-01'));
		$this->assertSame('2027-01-01', PeriodRule::nextDay('2026-12-31'));
		$this->assertSame('2028-02-29', PeriodRule::nextDay('2028-02-28'));
		$this->assertSame('2029-02-28', PeriodRule::previousDay('2029-03-01'));
	}
}
