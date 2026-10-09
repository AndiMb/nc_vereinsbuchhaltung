<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Tests\Dev\DevScenario;
use OCA\Vereinsbuchhaltung\Tests\Dev\IbanFactory;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../dev/lib/autoload.php';

/**
 * Das Testszenario (tests/dev) ist verbindlich: gegen Namen, Nummern und
 * Zuordnungen wird das Testprotokoll geschrieben. Diese Tests halten fest, dass
 * es in sich stimmt – ohne laufende Nextcloud.
 */
class DevScenarioTest extends TestCase {

	/** @return array<int, array<string, mixed>> Mitgliedsnummer → Mitglied */
	private function members(): array {
		$byNumber = [];
		foreach (DevScenario::members() as $member) {
			$byNumber[(int)$member['number']] = $member;
		}
		return $byNumber;
	}

	public function testMitgliedsnummernSindEindeutigUndAufsteigendVon1001(): void {
		$numbers = array_map(static fn (array $m): string => $m['number'], DevScenario::members());

		$this->assertCount(16, $numbers);
		$this->assertSame(array_map('strval', range(1001, 1016)), $numbers);
	}

	public function testNamenUndZuordnungenWieImTestprotokoll(): void {
		$m = $this->members();

		$this->assertSame('Hoffmann', $m[1001]['lastName']);
		$this->assertSame('jane', $m[1001]['ncUserId']);
		$this->assertSame('john', $m[1002]['ncUserId']);
		$this->assertSame('user1', $m[1003]['ncUserId']);
		$this->assertNull($m[1003]['mandate'], 'Lena hat kein Mandat');
		$this->assertSame('ueberweisung', $m[1003]['assignment']['method'] ?? null);
		$this->assertSame('electronic', $m[1001]['mandate']['kind'] ?? null);
		$this->assertSame('electronic_draft', $m[1002]['mandate']['kind'] ?? null);
		$this->assertSame('revoked', $m[1006]['mandate']['kind'] ?? null);
		$this->assertSame('Petra Lindner', $m[1008]['mandate']['holder'] ?? null);
		$this->assertSame('organisation', $m[1009]['type']);
		$this->assertSame('Musikhaus Schmidt GmbH', $m[1009]['organizationName']);
		$this->assertSame('2013-12-31', $m[1010]['leftAt']);
		$this->assertSame('2026-12-31', $m[1016]['leftAt']);
		$this->assertSame(12, $m[1005]['assignment']['interval'] ?? null);
		$this->assertSame(3, $m[1002]['assignment']['interval'] ?? null);
	}

	public function testAlleIbansHabenEinePruefsummeUndKeineDoppelten(): void {
		$ibans = [];
		foreach (DevScenario::members() as $member) {
			$iban = DevScenario::iban($member['bank']);
			if ($iban === null) {
				continue;
			}
			$this->assertTrue(IbanFactory::isValid($iban), $member['number'] . ' ' . $iban);
			$ibans[] = $iban;
		}
		foreach (DevScenario::plainBankEntries() as $entry) {
			$iban = DevScenario::iban($entry['bank']);
			if ($iban !== null) {
				$this->assertTrue(IbanFactory::isValid($iban), $entry['purpose']);
			}
		}
		$this->assertSame(array_values(array_unique($ibans)), $ibans, 'jede Test-IBAN nur einmal');
		$this->assertNotContains('DE12500105170648489890', $ibans, 'das Vereinskonto ist kein Mitgliedskonto');
	}

	public function testMandatBrauchtEineBankverbindung(): void {
		foreach (DevScenario::members() as $member) {
			if ($member['mandate'] !== null) {
				$this->assertNotNull($member['bank'], $member['number']);
			}
		}
	}

	public function testBetraegeLiegenInnerhalbDerGruppenregeln(): void {
		$groups = [];
		foreach (DevScenario::groups() as $group) {
			$this->assertGreaterThanOrEqual($group['minCents'], $group['defaultCents'], $group['name']);
			$this->assertContains($group['defaultInterval'], $group['intervals'], $group['name']);
			$groups[$group['name']] = $group;
		}
		$this->assertSame(1500, $groups['Vollmitglied']['defaultCents']);
		$this->assertSame(750, $groups['Ermäßigt']['defaultCents']);
		$this->assertSame(500, $groups['Jugend']['defaultCents']);
		$this->assertSame([12], $groups['Fördermitglied']['intervals']);

		foreach (DevScenario::members() as $member) {
			$assignment = $member['assignment'];
			if ($assignment === null) {
				continue;
			}
			$group = $groups[$assignment['group']];
			$this->assertContains($assignment['interval'], $group['intervals'], $member['number']);
			$min = $assignment['minOverrideCents'] ?? $group['minCents'];
			$this->assertGreaterThanOrEqual($min, $assignment['monthlyCents'], $member['number']);
		}
	}

	public function testGenauEineZuweisungHatEineIndividuelleUntergrenze(): void {
		$withOverride = array_filter(DevScenario::members(), static fn (array $m): bool => ($m['assignment']['minOverrideCents'] ?? null) !== null);

		$this->assertSame(['1011'], array_values(array_map(static fn (array $m): string => $m['number'], $withOverride)));
	}

	public function testRueckgabenBetreffenMitgliederMitAktivemMandatUndLastschrift(): void {
		$members = $this->members();
		$returns = DevScenario::returns();

		$this->assertSame([1004, 1005], array_keys($returns));
		$this->assertSame('AM04', $returns[1004]['code']);
		$this->assertSame('AC04', $returns[1005]['code']);
		foreach (array_keys($returns) as $number) {
			$this->assertContains($members[$number]['mandate']['kind'] ?? null, ['paper', 'electronic']);
			$this->assertSame('direct_debit', $members[$number]['assignment']['method'] ?? null);
		}
	}

	public function testOktoberlaufHatZehnLastschriftPosten(): void {
		$count = 0;
		foreach (DevScenario::members() as $member) {
			$assignment = $member['assignment'];
			$mandate = $member['mandate'];
			if ($assignment === null || $mandate === null || $assignment['method'] !== 'direct_debit') {
				continue;
			}
			if (!in_array($mandate['kind'], ['paper', 'electronic'], true) || $member['leftAt'] === '2013-12-31') {
				continue;
			}
			// Beginn bis zum Fälligkeitstag des Oktoberlaufs, kein Ende davor
			if ($assignment['validFrom'] <= DevScenario::RUN_DUE_DATE && ($assignment['validTo'] === null || $assignment['validTo'] >= DevScenario::RUN_DUE_DATE)) {
				$count++;
			}
		}

		$this->assertSame(10, $count);
	}

	public function testVorabinfoIstHeuteFaellig(): void {
		$daysUntilNextRun = (int)(new \DateTimeImmutable(DevScenario::SCENARIO_TODAY))->diff(new \DateTimeImmutable(DevScenario::NEXT_DUE_DATE))->days;

		$this->assertGreaterThanOrEqual($daysUntilNextRun, DevScenario::PRENOTIFICATION_LEAD_DAYS);
		$this->assertGreaterThanOrEqual(DevScenario::PRENOTIFICATION_LEAD_DAYS, DevScenario::WARNING_LEAD_DAYS);
	}

	public function testGlaeubigerIdHatDasFormatDerBundesbank(): void {
		$this->assertMatchesRegularExpression('/^DE\d{2}ZZZ\d{11}$/', DevScenario::CREDITOR_ID);
	}

	public function testPlainBankEntriesHabenKeineSepaReferenzen(): void {
		$kinds = array_column(DevScenario::plainBankEntries(), 'kind');

		$this->assertSame(['transfer', 'donation', 'fee'], $kinds);
		$transfer = DevScenario::plainBankEntries()[0];
		$this->assertSame(2250, $transfer['amountCents'], 'drei Monate Ermäßigt für Lena Bergmann');
		$this->assertSame('1003', $transfer['memberNumber']);
	}
}
