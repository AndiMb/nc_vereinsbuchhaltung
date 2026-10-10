<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Migration\LegacyContributionPlanner;
use PHPUnit\Framework\TestCase;

/**
 * Übernahme der Mandate und Beiträge des bisherigen flachen Moduls ({@see LegacyContributionPlanner}):
 * welche Alt-Zeile wird zu welchem Mandat beziehungsweise welcher Zuweisung, und was bleibt ausdrücklich liegen.
 */
class LegacyContributionPlannerTest extends TestCase {

	private function mandate(int $id, int $memberId, string $status = 'active', string $reference = '', string $type = 'RCUR', array $extra = []): array {
		return $extra + [
			'id' => $id,
			'member_id' => $memberId,
			'iban' => 'de02 1203 0000 0000 2020 51',
			'bic' => 'BYLADEM1001',
			'mandate_reference' => $reference !== '' ? $reference : 'M' . $id,
			'mandate_type' => $type,
			'signed_date' => '2024-03-01',
			'status' => $status,
			'last_used_date' => '2026-09-01',
			'created_at' => '2024-03-02 10:00:00',
		];
	}

	private function fee(int $id, int $memberId, int $amountCents, string $frequency = 'monthly', array $extra = []): array {
		return $extra + [
			'id' => $id,
			'member_id' => $memberId,
			'amount_cents' => $amountCents,
			'frequency' => $frequency,
			'start_date' => '2024-01-01',
			'next_due_date' => '2026-11-15',
			'mandate_id' => null,
			'active' => 1,
		];
	}

	public function testEinAktivesMandatWirdAktivMitAltenWerten(): void {
		$plan = LegacyContributionPlanner::planMandates([$this->mandate(1, 7)], [7 => 'Anna Koch'], []);

		$this->assertCount(1, $plan['inserts']);
		$row = $plan['inserts'][0]['row'];
		$this->assertSame('aktiv', $row['status']);
		$this->assertSame('DE02120300000000202051', $row['iban'], 'IBAN ohne Leerzeichen, in Großbuchstaben');
		$this->assertSame('M1', $row['mandate_reference'], 'Die alte Referenz bleibt erhalten');
		$this->assertSame('papier', $row['signature_type']);
		$this->assertSame('2024-03-01', $row['signed_at']);
		$this->assertSame('2026-09-01', $row['last_presented_due_date'], 'Die 36-Monats-Frist läuft vom letzten Einzug');
		$this->assertSame('Anna Koch', $row['account_holder']);
		$this->assertSame([7 => true], $plan['activeMembers']);
		$this->assertStringContainsString('Referenz M1', $plan['inserts'][0]['message']);
		$this->assertStringContainsString('01.03.2024', $plan['inserts'][0]['message']);
	}

	public function testNurDasJuengsteAktiveMandatBleibtLebendDieAnderenSindErsetzt(): void {
		$plan = LegacyContributionPlanner::planMandates([
			$this->mandate(1, 7),
			$this->mandate(2, 7),
			$this->mandate(3, 7),
		], [7 => 'Anna Koch'], []);

		$statuses = array_map(static fn (array $i): string => $i['row']['status'] . ':' . ($i['row']['end_reason'] ?? '-'), $plan['inserts']);
		$this->assertSame(['erloschen:ersetzt', 'erloschen:ersetzt', 'aktiv:-'], $statuses);
	}

	public function testWiderrufeneUndEinmalmandateSindErloschen(): void {
		$plan = LegacyContributionPlanner::planMandates([
			$this->mandate(1, 7, 'revoked'),
			$this->mandate(2, 8, 'active', '', 'OOFF'),
		], [7 => 'Anna Koch', 8 => 'Bernd Neumann'], []);

		$this->assertSame('erloschen', $plan['inserts'][0]['row']['status']);
		$this->assertSame('widerrufen', $plan['inserts'][0]['row']['end_reason']);
		$this->assertSame('erloschen', $plan['inserts'][1]['row']['status']);
		$this->assertSame('beendet', $plan['inserts'][1]['row']['end_reason']);
		$this->assertSame([], $plan['activeMembers'], 'Ohne wirksames Mandat kein Lastschrift-Mitglied');
	}

	public function testVergebeneReferenzenUndUnbekannteMitgliederWerdenUebersprungen(): void {
		$plan = LegacyContributionPlanner::planMandates([
			$this->mandate(1, 7, 'active', 'M-1'),
			$this->mandate(2, 99),
			$this->mandate(3, 8, 'active', 'M-1'),
		], [7 => 'Anna Koch', 8 => 'Bernd Neumann'], ['M-1' => true]);

		$this->assertSame([], $plan['inserts']);
		$reasons = array_column($plan['skipped'], 'reason', 'id');
		$this->assertStringContainsString('Referenz schon vorhanden', $reasons[1]);
		$this->assertSame('Mitglied unbekannt', $reasons[2]);
		$this->assertStringContainsString('Referenz schon vorhanden', $reasons[3]);
	}

	public function testDieselbeReferenzImAltenStandWirdNurEinmalUebernommen(): void {
		$plan = LegacyContributionPlanner::planMandates([
			$this->mandate(1, 7, 'active', 'DOPPELT'),
			$this->mandate(2, 8, 'active', 'DOPPELT'),
		], [7 => 'Anna Koch', 8 => 'Bernd Neumann'], []);

		$this->assertCount(1, $plan['inserts']);
		$this->assertCount(1, $plan['skipped']);
	}

	public function testFehlendeReferenzBekommtEineErsatzreferenz(): void {
		$mandate = $this->mandate(5, 7);
		$mandate['mandate_reference'] = '  ';
		$plan = LegacyContributionPlanner::planMandates([$mandate], [7 => 'Anna Koch'], []);

		$this->assertSame('ALT-5', $plan['inserts'][0]['row']['mandate_reference']);
	}

	public function testBeitragMitGanzzahligemMonatsbetragWirdZuweisung(): void {
		$plan = LegacyContributionPlanner::planFees([
			$this->fee(1, 7, 1500),
			$this->fee(2, 8, 4500, 'quarterly'),
			$this->fee(3, 9, 6000, 'yearly'),
		], [7 => 'A', 8 => 'B', 9 => 'C'], [], [7 => true], '2026-10-10');

		$this->assertSame([], $plan['notTransferred']);
		$this->assertCount(3, $plan['assignments']);
		[$a, $b, $c] = $plan['assignments'];
		$this->assertSame([1500, 1], [$a['monthlyCents'], $a['intervalMonths']]);
		$this->assertSame([1500, 3], [$b['monthlyCents'], $b['intervalMonths']], '45,00 € je Quartal sind 15,00 € im Monat');
		$this->assertSame([500, 12], [$c['monthlyCents'], $c['intervalMonths']], '60,00 € im Jahr sind 5,00 € im Monat');
		$this->assertSame('direct_debit', $a['paymentMethod'], 'Wirksames Mandat: Lastschrift');
		$this->assertSame('ueberweisung', $b['paymentMethod'], 'Ohne Mandat: Überweisung');
	}

	public function testNichtTeilbarerBetragWirdNichtUebernommenSondernNotiert(): void {
		$plan = LegacyContributionPlanner::planFees([
			$this->fee(1, 7, 10000, 'yearly', ['next_due_date' => '2027-01-01']),
		], [7 => 'A'], [], [], '2026-10-10');

		$this->assertSame([], $plan['assignments']);
		$this->assertCount(1, $plan['notTransferred']);
		$this->assertSame(7, $plan['notTransferred'][0]['memberId']);
		$this->assertStringContainsString('100,00 € jährlich', $plan['notTransferred'][0]['note']);
		$this->assertStringContainsString('01.01.2027', $plan['notTransferred'][0]['note']);
	}

	public function testUnbekannteHaeufigkeitUndBetragNullWerdenNichtUebernommen(): void {
		$plan = LegacyContributionPlanner::planFees([
			$this->fee(1, 7, 1500, 'weekly'),
			$this->fee(2, 8, 0),
		], [7 => 'A', 8 => 'B'], [], [], '2026-10-10');

		$this->assertSame([], $plan['assignments']);
		$this->assertCount(2, $plan['notTransferred']);
	}

	public function testDieZuweisungBeginntNieInDerVergangenheit(): void {
		$plan = LegacyContributionPlanner::planFees([
			$this->fee(1, 7, 1500, 'monthly', ['next_due_date' => '2026-03-15']),
			$this->fee(2, 8, 1500, 'monthly', ['next_due_date' => '2026-11-15']),
			$this->fee(3, 9, 1500, 'monthly', ['next_due_date' => '']),
		], [7 => 'A', 8 => 'B', 9 => 'C'], [], [], '2026-10-10');

		$this->assertSame('2026-10-01', $plan['assignments'][0]['validFrom'], 'Längst Fälliges beginnt im laufenden Monat, ohne Nachholen');
		$this->assertSame('2026-11-01', $plan['assignments'][1]['validFrom'], 'Künftig Fälliges beginnt im Monat der Fälligkeit');
		$this->assertSame('2026-10-01', $plan['assignments'][2]['validFrom']);
	}

	public function testInaktiveBeitraegeUndMitgliederMitZuweisungWerdenBehandelt(): void {
		$plan = LegacyContributionPlanner::planFees([
			$this->fee(1, 7, 1500, 'monthly', ['active' => 0]),
			$this->fee(2, 8, 1500),
			$this->fee(3, 9, 1500),
			$this->fee(4, 9, 2500),
		], [7 => 'A', 8 => 'B', 9 => 'C'], [8 => true], [], '2026-10-10');

		$this->assertSame(1, $plan['inactive']);
		$this->assertCount(1, $plan['assignments'], 'Nur der erste Beitrag von Mitglied 9; Mitglied 8 hat schon eine Zuweisung');
		$this->assertSame(9, $plan['assignments'][0]['memberId']);
		$this->assertCount(2, $plan['notTransferred']);
	}

	public function testBereitsUebernommeneBeitraegeWerdenBeimZweitenLaufStillUebergangen(): void {
		$plan = LegacyContributionPlanner::planFees([
			$this->fee(1, 7, 1500),
			$this->fee(2, 8, 10000, 'yearly'),
		], [7 => 'A', 8 => 'B'], [7 => true], [], '2026-10-10', [1 => true]);

		$this->assertSame([], $plan['assignments']);
		$this->assertCount(1, $plan['notTransferred'], 'Nur der nicht teilbare Beitrag taucht (weiterhin) auf, nicht der übernommene');
		$this->assertSame(2, $plan['notTransferred'][0]['legacyId']);
	}

	public function testGruppenNameNenntDenMonatsbetrag(): void {
		$this->assertSame('Beitrag 15,00 € im Monat (übernommen)', LegacyContributionPlanner::groupName(1500));
	}
}
