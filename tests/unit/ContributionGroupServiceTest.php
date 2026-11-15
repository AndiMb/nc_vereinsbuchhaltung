<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\ContributionGroup;
use OCA\Vereinsbuchhaltung\Db\ContributionGroupMapper;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Service\AssignmentService;
use OCA\Vereinsbuchhaltung\Service\ContributionGroupService;
use OCA\Vereinsbuchhaltung\Service\CostCenter\CostCenterDefaults;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Spec §3.3 „Untergrenzen-Erhöhung: Auto-Anhebung mit Vorschau – betroffene
 * Zuweisungen namentlich alt→neu, individuelle Untergrenzen separat
 * ausgewiesen". Eine Absenkung ist dagegen ein normales Feld-Update.
 */
class ContributionGroupServiceTest extends TestCase {

	private ContributionGroupMapper&MockObject $mapper;
	private AssignmentMapper&MockObject $assignmentMapper;
	private MemberMapper&MockObject $memberMapper;
	private AssignmentService&MockObject $assignmentService;
	/** Vorgabe-Kostenstellen (Issue #333); null = Dienst ohne sie */
	private (CostCenterDefaults&MockObject)|null $costCenterDefaults = null;

	protected function setUp(): void {
		$this->mapper = $this->createMock(ContributionGroupMapper::class);
		$this->assignmentMapper = $this->createMock(AssignmentMapper::class);
		$this->memberMapper = $this->createMock(MemberMapper::class);
		$this->assignmentService = $this->createMock(AssignmentService::class);
	}

	private function service(): ContributionGroupService {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-06-15'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		return new ContributionGroupService($this->mapper, $this->assignmentMapper, $this->memberMapper, $this->assignmentService, $time, $l10n, null, $this->costCenterDefaults);
	}

	private function group(int $minCents = 500): ContributionGroup {
		$group = new ContributionGroup();
		$group->setId(1);
		$group->setMinMonthlyAmountCents($minCents);
		return $group;
	}

	public function testCreateLehntLeerenNamenAb(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->create('', 500, 800, [1, 12], 12, true);
	}

	public function testCreateLehntNichtErlaubteTurnusseAb(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->create('Basisbeitrag', 500, 800, [5], 5, true);
	}

	public function testCreateLehntStandardbetragUnterUntergrenzeAb(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->create('Basisbeitrag', 800, 500, [1, 12], 12, true);
	}

	public function testCreateSpeichertGueltigeGruppe(): void {
		$this->mapper->method('insert')->willReturnArgument(0);
		$group = $this->service()->create('Basisbeitrag', 500, 800, [1, 12], 12, true);
		$this->assertSame('Basisbeitrag', $group->getName());
		$this->assertSame([1, 12], $group->getAllowedIntervalsArray());
	}

	public function testUpdateLehntErhoehungDerUntergrenzeAb(): void {
		$this->mapper->method('find')->willReturn($this->group(500));
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->update(1, 'Basisbeitrag', 600, 800, [1, 12], 12, true);
	}

	public function testUpdateErlaubtAbsenkungDerUntergrenze(): void {
		$this->mapper->method('find')->willReturn($this->group(500));
		$this->mapper->method('update')->willReturnArgument(0);
		$group = $this->service()->update(1, 'Basisbeitrag', 300, 800, [1, 12], 12, true);
		$this->assertSame(300, $group->getMinMonthlyAmountCents());
	}

	public function testDeleteLehntGruppeMitZuweisungenAb(): void {
		$this->mapper->method('find')->willReturn($this->group());
		$this->assignmentMapper->method('findByGroup')->willReturn([new Assignment()]);
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->delete(1);
	}

	public function testDeleteLoeschtGruppeOhneZuweisungen(): void {
		$this->mapper->method('find')->willReturn($this->group());
		$this->assignmentMapper->method('findByGroup')->willReturn([]);
		$this->mapper->expects($this->once())->method('delete');
		$this->service()->delete(1);
	}

	public function testPreviewLehntKeineErhoehungAb(): void {
		$this->mapper->method('find')->willReturn($this->group(500));
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->previewMinAmountIncrease(1, 500);
	}

	public function testPreviewTrenntBetroffeneVonIndividuellenUntergrenzen(): void {
		$this->mapper->method('find')->willReturn($this->group(500));

		$normal = new Assignment();
		$normal->setId(10);
		$normal->setMemberId(1);
		$normal->setMonthlyAmountCents(400);
		$normal->setValidFrom('2020-01-01');

		$override = new Assignment();
		$override->setId(11);
		$override->setMemberId(2);
		$override->setMonthlyAmountCents(200);
		$override->setMinMonthlyAmountOverrideCents(200);
		$override->setValidFrom('2020-01-01');

		$alreadyAboveNewMin = new Assignment();
		$alreadyAboveNewMin->setId(12);
		$alreadyAboveNewMin->setMemberId(3);
		$alreadyAboveNewMin->setMonthlyAmountCents(1000);
		$alreadyAboveNewMin->setValidFrom('2020-01-01');

		$this->assignmentMapper->method('findByGroup')->willReturn([$normal, $override, $alreadyAboveNewMin]);
		$this->memberMapper->method('displayNameOr')->willReturnCallback(fn (int $id, string $fallback) => match ($id) {
			1 => 'Anna Mitglied', 2 => 'Bert Mitglied', 3 => 'Clara Mitglied', default => $fallback,
		});

		$preview = $this->service()->previewMinAmountIncrease(1, 600);

		$this->assertCount(1, $preview['affected']);
		$this->assertSame(10, $preview['affected'][0]['assignmentId']);
		$this->assertSame(400, $preview['affected'][0]['oldAmountCents']);
		$this->assertSame(600, $preview['affected'][0]['newAmountCents']);

		$this->assertCount(1, $preview['individualOverridesUnaffected']);
		$this->assertSame(11, $preview['individualOverridesUnaffected'][0]['assignmentId']);
	}

	public function testPreviewNimmtAuchKuenftigBeginnendeUndKeineBeendetenZuweisungenMit(): void {
		$this->mapper->method('find')->willReturn($this->group(500));

		// „Heute“ ist im Test der 2026-06-15.
		$future = new Assignment();
		$future->setId(20);
		$future->setMemberId(1);
		$future->setMonthlyAmountCents(400);
		$future->setValidFrom('2026-11-15');

		$ended = new Assignment();
		$ended->setId(21);
		$ended->setMemberId(2);
		$ended->setMonthlyAmountCents(400);
		$ended->setValidFrom('2024-01-01');
		$ended->setValidTo('2026-03-31');

		$this->assignmentMapper->method('findByGroup')->willReturn([$future, $ended]);
		$this->memberMapper->method('displayNameOr')->willReturnCallback(fn (int $id, string $fallback) => 'Mitglied ' . $id);

		$preview = $this->service()->previewMinAmountIncrease(1, 600);

		$this->assertSame([20], array_column($preview['affected'], 'assignmentId'));
	}

	/** Sonst ließe sich die Gruppe nach der Erhöhung nicht mehr speichern („Standard-Monatsbeitrag darf die Untergrenze nicht unterschreiten“). */
	public function testApplyMinAmountIncreaseZiehtDenStandardbeitragMit(): void {
		$group = $this->group(500);
		$group->setDefaultMonthlyAmountCents(800);
		$this->mapper->method('find')->willReturn($group);
		$this->assignmentMapper->method('findByGroup')->willReturn([]);
		$this->mapper->expects($this->once())->method('update')->with($this->callback(
			static fn (ContributionGroup $g): bool => $g->getMinMonthlyAmountCents() === 1000 && $g->getDefaultMonthlyAmountCents() === 1000,
		));

		$this->service()->applyMinAmountIncrease(1, 1000, 'kassenwart');
	}

	public function testApplyMinAmountIncreaseLaesstEinenHoherenStandardbeitragStehen(): void {
		$group = $this->group(500);
		$group->setDefaultMonthlyAmountCents(1500);
		$this->mapper->method('find')->willReturn($group);
		$this->assignmentMapper->method('findByGroup')->willReturn([]);
		$this->mapper->expects($this->once())->method('update')->with($this->callback(
			static fn (ContributionGroup $g): bool => $g->getMinMonthlyAmountCents() === 1000 && $g->getDefaultMonthlyAmountCents() === 1500,
		));

		$this->service()->applyMinAmountIncrease(1, 1000, 'kassenwart');
	}

	public function testApplyMinAmountIncreaseHebtNurBetroffeneAn(): void {
		$this->mapper->method('find')->willReturn($this->group(500));
		$this->mapper->expects($this->once())->method('update');

		$normal = new Assignment();
		$normal->setId(10);
		$normal->setMemberId(1);
		$normal->setMonthlyAmountCents(400);
		$normal->setValidFrom('2020-01-01');
		$this->assignmentMapper->method('findByGroup')->willReturn([$normal]);
		$this->assignmentMapper->method('find')->with(10)->willReturn($normal);
		$this->memberMapper->method('displayNameOr')->willReturn('Anna Mitglied');

		$this->assignmentService->expects($this->once())->method('raiseToMinimum')->with($normal, 600, 'kassenwart');

		$result = $this->service()->applyMinAmountIncrease(1, 600, 'kassenwart');

		$this->assertSame(1, $result['raisedCount']);
		$this->assertSame(600, $result['newMinAmountCents']);
	}

	// ---- Vorgabe-Kostenstelle (Issue #333) ----

	private function withCostCenters(?string $error): void {
		$this->costCenterDefaults = $this->createMock(CostCenterDefaults::class);
		$this->costCenterDefaults->method('assignableError')->willReturn($error);
		$this->mapper->method('update')->willReturnArgument(0);
	}

	private function groupWithCostCenter(?int $costCenterId): ContributionGroup {
		$group = $this->group(500);
		$group->setBookingCcId($costCenterId);
		return $group;
	}

	public function testVorgabeKostenstelleWirdGesetzt(): void {
		$this->withCostCenters(null);
		$this->mapper->method('find')->willReturn($this->groupWithCostCenter(null));

		$group = $this->service()->update(1, 'Jugend', 500, 800, [1, 12], 12, true, 3);

		$this->assertSame(3, $group->getBookingCostCenterId());
	}

	/** Alter Client oder Formular bei ausgeschaltetem Modul: der Parameter fehlt, die Vorgabe bleibt. */
	public function testOhneAngabeBleibtDieVorgabeStehen(): void {
		$this->withCostCenters('disabled');
		$this->mapper->method('find')->willReturn($this->groupWithCostCenter(3));

		$group = $this->service()->update(1, 'Jugend', 500, 800, [1, 12], 12, true);

		$this->assertSame(3, $group->getBookingCostCenterId());
	}

	public function testNullEntferntDieVorgabeAuchBeiAusgeschaltetemModul(): void {
		$this->withCostCenters('disabled');
		$this->mapper->method('find')->willReturn($this->groupWithCostCenter(3));

		$group = $this->service()->update(1, 'Jugend', 500, 800, [1, 12], 12, true, null);

		$this->assertNull($group->getBookingCostCenterId());
	}

	public function testUnbekannteKostenstelleWirdAbgelehnt(): void {
		$this->withCostCenters('unknown');
		$this->mapper->method('find')->willReturn($this->groupWithCostCenter(null));

		$this->expectExceptionMessage('Kostenstelle nicht gefunden');
		$this->service()->update(1, 'Jugend', 500, 800, [1, 12], 12, true, 77);
	}

	public function testNeuerWertBeiAusgeschaltetemModulWirdAbgelehnt(): void {
		$this->withCostCenters('disabled');
		$this->mapper->method('find')->willReturn($this->groupWithCostCenter(null));

		$this->expectExceptionMessage('ausgeschaltet');
		$this->service()->update(1, 'Jugend', 500, 800, [1, 12], 12, true, 3);
	}

	/** Schickt ein Client den unveränderten Wert mit, scheitert das Speichern der Gruppe nicht am ausgeschalteten Modul. */
	public function testUnveraenderterWertBleibtAuchBeiAusgeschaltetemModul(): void {
		$this->withCostCenters('disabled');
		$this->mapper->method('find')->willReturn($this->groupWithCostCenter(3));

		$group = $this->service()->update(1, 'Jugend', 500, 800, [1, 12], 12, true, 3);

		$this->assertSame(3, $group->getBookingCostCenterId());
	}

	public function testAnlegenMitVorgabeKostenstelle(): void {
		$this->withCostCenters(null);
		$this->mapper->method('insert')->willReturnArgument(0);

		$group = $this->service()->create('Jugend', 500, 800, [1, 12], 12, true, 3);

		$this->assertSame(3, $group->getBookingCostCenterId());
	}

	// --- Jahresbeiträge (Issue #270) ---

	private function annualGroup(int $minAnnualCents = 5000): ContributionGroup {
		$group = new ContributionGroup();
		$group->setId(1);
		$group->setAmountBasis(ContributionGroup::BASIS_YEAR);
		$group->setMinAnnualAmountCents($minAnnualCents);
		$group->setDefaultAnnualAmountCents($minAnnualCents);
		$group->setMinMonthlyAmountCents((int)round($minAnnualCents / 12));
		$group->setDefaultMonthlyAmountCents((int)round($minAnnualCents / 12));
		return $group;
	}

	public function testJahresgruppeSpeichertDenJahresbetragUndDenGerundetenMonatswert(): void {
		$this->mapper->method('insert')->willReturnArgument(0);

		$group = $this->service()->create('Förderbeitrag', 2500, 5000, [12], 12, true, false, ContributionGroup::BASIS_YEAR, false);

		$this->assertTrue($group->isAnnual());
		$this->assertFalse($group->prorates());
		$this->assertSame(2500, $group->getMinAnnualAmountCents());
		$this->assertSame(5000, $group->getDefaultAnnualAmountCents());
		$this->assertSame(208, $group->getMinMonthlyAmountCents());
		$this->assertSame(417, $group->getDefaultMonthlyAmountCents());
	}

	public function testMonatsgruppeBleibtOhneJahresbetrag(): void {
		$this->mapper->method('insert')->willReturnArgument(0);

		$group = $this->service()->create('Basisbeitrag', 500, 800, [1, 12], 12, true);

		$this->assertFalse($group->isAnnual());
		$this->assertNull($group->getMinAnnualAmountCents());
		$this->assertSame(500, $group->getMinMonthlyAmountCents());
	}

	public function testJahresgruppeErlaubtNurDenJaehrlichenTurnus(): void {
		$this->expectExceptionMessage('jährliche Turnus');
		$this->service()->create('Förderbeitrag', 2500, 5000, [1, 12], 12, true, false, ContributionGroup::BASIS_YEAR);
	}

	public function testUnbekannteBetragsangabeWirdAbgelehnt(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->create('Förderbeitrag', 2500, 5000, [12], 12, true, false, 'quarter');
	}

	public function testBetragsangabeLaesstSichMitZuweisungenNichtMehrAendern(): void {
		$this->mapper->method('find')->willReturn($this->group(500));
		$this->assignmentMapper->method('findByGroup')->willReturn([new Assignment()]);

		$this->expectExceptionMessage('Betragsangabe');
		$this->service()->update(1, 'Basisbeitrag', 5000, 5000, [12], 12, true, false, ContributionGroup::BASIS_YEAR);
	}

	public function testBetragsangabeLaesstSichOhneZuweisungenAendern(): void {
		$this->mapper->method('find')->willReturn($this->group(500));
		$this->assignmentMapper->method('findByGroup')->willReturn([]);
		$this->mapper->method('update')->willReturnArgument(0);

		// 50 €/Jahr liegt über den bisherigen 5 €/Monat: kein Fall für die Untergrenzen-Erhöhung, die Angabe wechselt.
		$group = $this->service()->update(1, 'Basisbeitrag', 5000, 5000, [12], 12, true, false, ContributionGroup::BASIS_YEAR);

		$this->assertTrue($group->isAnnual());
		$this->assertSame(5000, $group->getMinAnnualAmountCents());
	}

	public function testWechselZurMonatsangabeLeertDieJahresbetraege(): void {
		$this->mapper->method('find')->willReturn($this->annualGroup());
		$this->assignmentMapper->method('findByGroup')->willReturn([]);
		$this->mapper->method('update')->willReturnArgument(0);

		$group = $this->service()->update(1, 'Basisbeitrag', 300, 500, [1, 12], 12, true);

		$this->assertFalse($group->isAnnual());
		$this->assertNull($group->getMinAnnualAmountCents());
		$this->assertNull($group->getDefaultAnnualAmountCents());
		$this->assertSame(300, $group->getMinMonthlyAmountCents());
	}

	public function testAnteiligLaesstSichAuchMitZuweisungenUmschalten(): void {
		$this->mapper->method('find')->willReturn($this->annualGroup());
		$this->assignmentMapper->method('findByGroup')->willReturn([new Assignment()]);
		$this->mapper->method('update')->willReturnArgument(0);

		$group = $this->service()->update(1, 'Förderbeitrag', 5000, 5000, [12], 12, true, false, ContributionGroup::BASIS_YEAR, false);

		$this->assertFalse($group->prorates());
	}

	public function testUpdateLehntErhoehungDerJahresUntergrenzeAb(): void {
		$this->mapper->method('find')->willReturn($this->annualGroup(5000));
		$this->assignmentMapper->method('findByGroup')->willReturn([new Assignment()]);

		$this->expectExceptionMessage('Erhöhung der Untergrenze');
		$this->service()->update(1, 'Förderbeitrag', 6000, 6000, [12], 12, true, false, ContributionGroup::BASIS_YEAR);
	}

	public function testUntergrenzenErhoehungRechnetBeiJahresgruppenMitJahresbetraegen(): void {
		$this->mapper->method('find')->willReturn($this->annualGroup(5000));

		$below = new Assignment();
		$below->setId(10);
		$below->setMemberId(1);
		$below->setMonthlyAmountCents(417);
		$below->setAnnualAmountCents(5000);
		$below->setValidFrom('2020-01-01');

		$above = new Assignment();
		$above->setId(11);
		$above->setMemberId(2);
		$above->setMonthlyAmountCents(667);
		$above->setAnnualAmountCents(8000);
		$above->setValidFrom('2020-01-01');

		$override = new Assignment();
		$override->setId(12);
		$override->setMemberId(3);
		$override->setMonthlyAmountCents(208);
		$override->setAnnualAmountCents(2500);
		$override->setMinAnnualOverrideCents(2500);
		$override->setValidFrom('2020-01-01');

		$this->assignmentMapper->method('findByGroup')->willReturn([$below, $above, $override]);
		$this->memberMapper->method('displayNameOr')->willReturn('Mitglied');

		$preview = $this->service()->previewMinAmountIncrease(1, 6000, true);

		$this->assertSame(5000, $preview['oldMinAmountCents']);
		$this->assertSame([10], array_column($preview['affected'], 'assignmentId'));
		$this->assertSame(5000, $preview['affected'][0]['oldAmountCents']);
		$this->assertSame(6000, $preview['affected'][0]['newAmountCents']);
		$this->assertSame(2500, $preview['individualOverridesUnaffected'][0]['minAmountOverrideCents']);
	}

	/** Ein Monatswert, der als Jahreswert gelesen würde (oder umgekehrt), wäre um den Faktor 12 falsch. */
	public function testUntergrenzenErhoehungLehntFalscheBetragsangabeAb(): void {
		$this->mapper->method('find')->willReturn($this->annualGroup(5000));

		$this->expectExceptionMessage('Betragsangabe');
		$this->service()->previewMinAmountIncrease(1, 6000);
	}

	public function testApplyMinAmountIncreaseSetztBeiJahresgruppenJahresUndMonatswert(): void {
		$this->mapper->method('find')->willReturn($this->annualGroup(5000));
		$this->assignmentMapper->method('findByGroup')->willReturn([]);
		$this->mapper->expects($this->once())->method('update')->with($this->callback(
			static fn (ContributionGroup $g): bool => $g->getMinAnnualAmountCents() === 7000
				&& $g->getDefaultAnnualAmountCents() === 7000
				&& $g->getMinMonthlyAmountCents() === 583
				&& $g->getDefaultMonthlyAmountCents() === 583,
		));

		$this->service()->applyMinAmountIncrease(1, 7000, 'kassenwart', true);
	}
}
