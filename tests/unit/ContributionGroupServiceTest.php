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
		return new ContributionGroupService($this->mapper, $this->assignmentMapper, $this->memberMapper, $this->assignmentService, $time, $l10n);
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
}
