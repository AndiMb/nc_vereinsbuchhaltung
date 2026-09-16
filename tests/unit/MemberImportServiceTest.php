<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\ContributionGroup;
use OCA\Vereinsbuchhaltung\Db\ContributionGroupMapper;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Service\AssignmentService;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\MandateService;
use OCA\Vereinsbuchhaltung\Service\MemberImportService;
use OCA\Vereinsbuchhaltung\Service\Sepa\MemberCsvParser;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Der volle CSV-Import (Spec §3.1, Issue #69): Zeile = ein Mitglied mit zwei
 * optionalen, atomaren Blöcken (Mandat, Zuweisung). Schwerpunkt hier sind die
 * Regeln, die NICHT schon {@see MemberCsvParserTest} abdeckt, weil sie
 * Datenbankzugriff brauchen: Dublettenregeln, Mandats-Aktivierungs-Gate,
 * Beitragsgruppen-Auflösung, Zahlungsart-Ableitung.
 *
 * {@see MandateService}/{@see AssignmentService} sind gemockt (dieselbe
 * bereits an anderer Stelle im Modul geprüfte Fachlogik soll hier nicht ein
 * zweites Mal getestet werden) – geprüft wird nur, *dass* und *wie* diese
 * Klasse sie aufruft.
 */
class MemberImportServiceTest extends TestCase {

	private MemberMapper&MockObject $memberMapper;
	private MandateService&MockObject $mandates;
	private AssignmentService&MockObject $assignments;
	private ContributionGroupMapper&MockObject $groupMapper;
	private IUserManager&MockObject $userManager;

	protected function setUp(): void {
		// Bewusst NICHT hier schon findAll()/findByNcUserId()/findByMemberNumber()
		// vorbelegen: ein zweites method()-Stubbing in einem einzelnen Test würde
		// gegen dieses generische zuerst konfigurierte konkurrieren, PHPUnit
		// nimmt dann das zuerst passende – nicht das speziellere. Unkonfiguriert
		// liefert der generierte Mock ohnehin den typgerechten Leerwert (`[]`
		// bzw. `null`), das reicht als Default für die Tests, die es nicht
		// brauchen.
		$this->memberMapper = $this->createMock(MemberMapper::class);
		$this->memberMapper->method('insert')->willReturnCallback(static function (Member $m): Member {
			$m->setId(random_int(1000, 9999));
			return $m;
		});

		$this->mandates = $this->createMock(MandateService::class);
		$this->assignments = $this->createMock(AssignmentService::class);
		$this->groupMapper = $this->createMock(ContributionGroupMapper::class);

		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager->method('userExists')->willReturn(true);
	}

	private function service(): MemberImportService {
		$transaction = $this->createMock(TransactionRunner::class);
		$transaction->method('run')->willReturnCallback(static fn (callable $fn) => $fn());

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static fn (string $app, string $key, string $default = '') => $default);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf(str_replace('%s', '%1$s', $text), $parameters));

		return new MemberImportService(
			new MemberCsvParser(),
			$this->memberMapper,
			$this->mandates,
			$this->assignments,
			$this->groupMapper,
			$this->userManager,
			$transaction,
			$this->createMock(AuditService::class),
			$this->createMock(IUserSession::class),
			$config,
			$l10n,
		);
	}

	private function group(int $id, string $name): ContributionGroup {
		$group = new ContributionGroup();
		$group->setId($id);
		$group->setName($name);
		return $group;
	}

	public function testReineStammdatenzeileLegtNurEinMitgliedAn(): void {
		$csv = "Name;E-Mail\nKatrin Brunner;k.brunner@example.org\n";
		$result = $this->service()->import($csv, true);

		$this->assertNull($result['error']);
		$this->assertSame(['ok' => 1, 'skipped' => 0, 'failed' => 0, 'mandates' => 0, 'assignments' => 0, 'warnings' => 0], $result['summary']);
		$this->assertNotNull($result['rows'][0]['memberId']);
		$this->assertNull($result['rows'][0]['mandateId']);
		$this->assertNull($result['rows'][0]['assignmentId']);
	}

	public function testHarteDubletteUeberMitgliedsnummerWirdUebersprungenNichtAngelegt(): void {
		$this->memberMapper->method('findByMemberNumber')->with('0815')->willReturn(new Member());
		$this->mandates->expects($this->never())->method('createPaper');

		$csv = "Name;Mitgliedsnummer\nKatrin Brunner;0815\n";
		$result = $this->service()->import($csv, true);

		$this->assertSame(1, $result['summary']['skipped']);
		$this->assertSame(0, $result['summary']['ok']);
		$this->assertTrue($result['rows'][0]['skipped']);
		$this->assertNull($result['rows'][0]['memberId']);
	}

	public function testHarteDubletteUeberNcKontoWirdUebersprungen(): void {
		$this->memberMapper->method('findByNcUserId')->with('k.brunner')->willReturn(new Member());

		$csv = "Konto\nk.brunner\n";
		$result = $this->service()->preview($csv);

		$this->assertSame(1, $result['summary']['skipped']);
		$this->assertNotNull($result['rows'][0]['skipReason']);
	}

	public function testWeicheDubletteNamensgleichheitWirdNurGewarntUndTrotzdemAngelegt(): void {
		$existing = new Member();
		$existing->setMemberType(Member::TYPE_PERSON);
		$existing->setFirstName('Katrin');
		$existing->setLastName('Brunner');
		$this->memberMapper->method('findAll')->willReturn([$existing]);

		$csv = "Name\nKatrin Brunner\n";
		$result = $this->service()->import($csv, true);

		$this->assertSame(1, $result['summary']['ok']);
		$this->assertSame(1, $result['summary']['warnings']);
		$this->assertNotEmpty($result['rows'][0]['warnings']);
		$this->assertNotNull($result['rows'][0]['memberId'], 'trotz Warnung angelegt');
	}

	/** Mandatsdatum ist dank Parser-Regel bei einer IBAN immer gesetzt – jedes Import-Mandat aktiviert deshalb sofort. */
	public function testMandatWirdBeimImportSofortAktiviert(): void {
		$draft = new Mandate();
		$draft->setId(42);
		$draft->setStatus(Mandate::STATUS_DRAFT);
		$this->mandates->expects($this->once())->method('createPaper')
			->with($this->anything(), 'DE02120300000000202051', null, null, '2026-01-15', null)
			->willReturn($draft);

		$active = new Mandate();
		$active->setId(42);
		$active->setStatus(Mandate::STATUS_ACTIVE);
		$this->mandates->expects($this->once())->method('activatePaper')->with(42)->willReturn($active);

		$csv = "Name;IBAN;Mandat am\nKatrin Brunner;DE02120300000000202051;15.01.2026\n";
		$result = $this->service()->import($csv, true);

		$this->assertSame(Mandate::STATUS_ACTIVE, $result['rows'][0]['mandateStatus']);
		$this->assertSame(1, $result['summary']['mandates']);
	}

	public function testImportOhneBestaetigteMandateWirdKomplettAbgelehnt(): void {
		$this->mandates->expects($this->never())->method('createPaper');

		$csv = "Name;IBAN;Mandat am\nKatrin Brunner;DE02120300000000202051;15.01.2026\n";
		$result = $this->service()->import($csv, false);

		$this->assertNotNull($result['error']);
		$this->assertSame([], $result['rows']);
	}

	/** Reine Stammdatenzeilen brauchen die Mandats-Bestätigung nicht. */
	public function testImportOhneMandatsspaltenBrauchtKeineBestaetigung(): void {
		$csv = "Name\nKatrin Brunner\n";
		$result = $this->service()->import($csv, false);

		$this->assertNull($result['error']);
		$this->assertSame(1, $result['summary']['ok']);
	}

	public function testZeileOhneMailLandetAufUeberweisung(): void {
		$this->groupMapper->method('findAll')->willReturn([$this->group(1, 'Chormitglieder')]);
		$this->assignments->expects($this->once())->method('create')
			->with($this->anything(), 1, 1, 4250, Assignment::PAYMENT_METHOD_TRANSFER, '2026-02-01', null, null, null, $this->anything(), $this->anything())
			->willReturn((function () {
				$a = new Assignment();
				$a->setId(7);
				return $a;
			})());

		$csv = "Name;Beitragsgruppe;Betrag;Frequenz;Start\nBarzahler;Chormitglieder;42,50;monatlich;01.02.2026\n";
		$result = $this->service()->import($csv, true);

		$this->assertSame(7, $result['rows'][0]['assignmentId']);
	}

	public function testBeitragsgruppeWirdUeberNamenGrossKleinschreibungsUnabhaengigAufgeloest(): void {
		$this->groupMapper->method('findAll')->willReturn([$this->group(3, 'Chormitglieder')]);
		$this->assignments->method('create')->willReturnCallback(static function (...$args): Assignment {
			$a = new Assignment();
			$a->setId(9);
			return $a;
		});

		$csv = "Name;E-Mail;Beitragsgruppe;Betrag;Frequenz;Start\nKatrin Brunner;k.brunner@example.org;CHORMITGLIEDER;42,50;monatlich;01.02.2026\n";
		$result = $this->service()->import($csv, true);

		$this->assertSame(9, $result['rows'][0]['assignmentId']);
		$this->assertSame([], $result['rows'][0]['warnings']);
	}

	public function testGenauEineBeitragsgruppeIstDerFallbackOhneEigeneSpalte(): void {
		$this->groupMapper->method('findAll')->willReturn([$this->group(5, 'Nur diese Gruppe')]);
		$this->assignments->method('create')->willReturnCallback(static function (...$args): Assignment {
			$a = new Assignment();
			$a->setId(11);
			return $a;
		});

		$csv = "Name;E-Mail;Betrag;Frequenz;Start\nKatrin Brunner;k.brunner@example.org;42,50;monatlich;01.02.2026\n";
		$result = $this->service()->import($csv, true);

		$this->assertSame(11, $result['rows'][0]['assignmentId']);
	}

	public function testUnbekannteBeitragsgruppeIstNurEineWarnungKeinZeilenfehler(): void {
		$this->groupMapper->method('findAll')->willReturn([$this->group(1, 'Chormitglieder')]);
		$this->assignments->expects($this->never())->method('create');

		$csv = "Name;E-Mail;Beitragsgruppe;Betrag;Frequenz;Start\nKatrin Brunner;k.brunner@example.org;Nichtexistent;42,50;monatlich;01.02.2026\n";
		$result = $this->service()->import($csv, true);

		$this->assertSame(1, $result['summary']['ok']);
		$this->assertNotNull($result['rows'][0]['memberId'], 'Mitglied entsteht trotzdem');
		$this->assertNull($result['rows'][0]['assignmentId']);
		$this->assertNotEmpty($result['rows'][0]['warnings']);
	}
}
