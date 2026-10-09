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
use OCA\Vereinsbuchhaltung\Service\IbanValidator;
use OCA\Vereinsbuchhaltung\Service\MandateService;
use OCA\Vereinsbuchhaltung\Service\MemberImportService;
use OCA\Vereinsbuchhaltung\Service\Sepa\MemberCsvParser;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUser;
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
	/** @var list<Member> was der Import an Mitgliedern angelegt hat */
	private array $inserted = [];

	protected function setUp(): void {
		// Bewusst NICHT hier schon findAll()/findByNcUserId()/findByMemberNumber()
		// vorbelegen: ein zweites method()-Stubbing in einem einzelnen Test würde
		// gegen dieses generische zuerst konfigurierte konkurrieren, PHPUnit
		// nimmt dann das zuerst passende – nicht das speziellere. Unkonfiguriert
		// liefert der generierte Mock ohnehin den typgerechten Leerwert (`[]`
		// bzw. `null`), das reicht als Default für die Tests, die es nicht
		// brauchen.
		$this->memberMapper = $this->createMock(MemberMapper::class);
		$this->inserted = [];
		$this->memberMapper->method('insert')->willReturnCallback(function (Member $m): Member {
			$m->setId(random_int(1000, 9999));
			$this->inserted[] = $m;
			return $m;
		});

		$this->mandates = $this->createMock(MandateService::class);
		$this->assignments = $this->createMock(AssignmentService::class);
		$this->groupMapper = $this->createMock(ContributionGroupMapper::class);

		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager->method('userExists')->willReturn(true);
	}

	/**
	 * @param string $today „Heute" für den Service – fest, damit die Beispiel-
	 *                      Startdaten der Tests nicht mit dem Kalender verfaulen
	 *                      (der Zuweisungsbeginn darf nicht in der Vergangenheit liegen).
	 */
	private function service(string $today = '2026-01-01'): MemberImportService {
		$transaction = $this->createMock(TransactionRunner::class);
		$transaction->method('run')->willReturnCallback(static fn (callable $fn) => $fn());

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static fn (string $app, string $key, string $default = '') => $default);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf(str_replace('%s', '%1$s', $text), $parameters));

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): \DateTime => new \DateTime($today));

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
			$time,
			new IbanValidator($l10n),
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

	// --- Namensspalten, Stammdaten, Eintritt ---

	public function testVornameNachnameLandenGenauSoImMitglied(): void {
		$csv = "Vorname;Nachname\nAnna Maria;Beispiel Müller\n";
		$result = $this->service()->import($csv, true);

		$this->assertSame(1, $result['summary']['ok']);
		$member = $this->inserted[0];
		$this->assertSame(Member::TYPE_PERSON, $member->getMemberType());
		$this->assertSame('Anna Maria', $member->getFirstName());
		$this->assertSame('Beispiel Müller', $member->getLastName());
		$this->assertNull($member->getOrganizationName());
		$this->assertSame('Anna Maria Beispiel Müller', $result['rows'][0]['name'], 'zusammengesetzter Anzeigename');
		$this->assertSame(Member::TYPE_PERSON, $result['rows'][0]['memberType']);
	}

	public function testOrganisationsspalteErgibtEineOrganisation(): void {
		$csv = "Organisation;E-Mail\nMusikhaus Beispiel;info@example.org\n";
		$result = $this->service()->import($csv, true);

		$member = $this->inserted[0];
		$this->assertSame(Member::TYPE_ORGANIZATION, $member->getMemberType());
		$this->assertSame('Musikhaus Beispiel', $member->getOrganizationName());
		$this->assertNull($member->getFirstName());
		$this->assertNull($member->getLastName());
		$this->assertSame('Musikhaus Beispiel', $result['rows'][0]['name']);
		$this->assertSame(Member::TYPE_ORGANIZATION, $result['rows'][0]['memberType']);
	}

	/** Nur „Name": die zentrale Heuristik greift – auch die Rechtsform. */
	public function testNurNameMitRechtsformWirdEineOrganisation(): void {
		$csv = "Name\nMusikhaus Beispiel GmbH\nAnna Maria Beispiel\n";
		$result = $this->service()->import($csv, true);

		$this->assertSame(Member::TYPE_ORGANIZATION, $this->inserted[0]->getMemberType());
		$this->assertSame('Musikhaus Beispiel GmbH', $this->inserted[0]->getOrganizationName());
		$this->assertSame(Member::TYPE_PERSON, $this->inserted[1]->getMemberType());
		$this->assertSame('Anna', $this->inserted[1]->getFirstName());
		$this->assertSame('Maria Beispiel', $this->inserted[1]->getLastName());
		// Die Vorschau zeigt, wie die Heuristik entschieden hat, und die Eingabe unverändert.
		$this->assertSame('Musikhaus Beispiel GmbH', $result['rows'][0]['name']);
		$this->assertSame(Member::TYPE_ORGANIZATION, $result['rows'][0]['memberType']);
		$this->assertSame(Member::TYPE_PERSON, $result['rows'][1]['memberType']);
	}

	public function testStammdatenUndEintrittInDerVergangenheitLandenImMitglied(): void {
		$csv = "Name;Eintritt;Straße;PLZ;Ort;Telefon\nKatrin Brunner;01.05.2019;Musterweg 12;12345;Musterstadt;0123 456789\n";
		$this->service('2026-10-09')->import($csv, true);

		$member = $this->inserted[0];
		$this->assertSame('2019-05-01', $member->getJoinedAt());
		$this->assertSame('Musterweg 12', $member->getStreet());
		$this->assertSame('12345', $member->getPostalCode());
		$this->assertSame('Musterstadt', $member->getCity());
		$this->assertSame('0123 456789', $member->getPhone());
	}

	public function testOhneEintrittGiltDerImporttag(): void {
		$this->service('2026-10-09')->import("Name\nKatrin Brunner\n", true);

		$this->assertSame('2026-10-09', $this->inserted[0]->getJoinedAt());
		$this->assertNull($this->inserted[0]->getStreet());
		$this->assertNull($this->inserted[0]->getPhone());
	}

	public function testUngueltigerEintrittLegtNichtsAnUndStehtInDerVorschau(): void {
		$result = $this->service()->preview("Name;Eintritt\nKatrin Brunner;31.02.2019\n");

		$this->assertSame(1, $result['summary']['failed']);
		$this->assertStringContainsString('Unlesbares Eintrittsdatum', implode(' ', $result['rows'][0]['errors']));
	}

	public function testStrukturierterNameSchlaegtNextcloudAnzeigenameBeiVerknuepftemKonto(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Anna Maria Beispiel');
		$user->method('getEMailAddress')->willReturn('anna@example.org');
		$this->userManager->method('get')->willReturn($user);

		$this->service()->import("Konto;Vorname;Nachname\nanna;Anna Maria;Beispiel\n", true);

		$member = $this->inserted[0];
		$this->assertSame('Anna Maria', $member->getFirstName());
		$this->assertSame('Beispiel', $member->getLastName());
		$this->assertSame('anna', $member->getNcUserId());
		$this->assertSame('anna@example.org', $member->getEmail());
	}

	public function testNachnameMitRechtsformOhneVornameWarntVorFalscherPerson(): void {
		$result = $this->service()->preview("Vorname;Nachname\n;Musikhaus Beispiel GmbH\n");

		$row = $result['rows'][0];
		$this->assertSame([], $row['errors']);
		$this->assertSame(Member::TYPE_PERSON, $row['memberType']);
		$this->assertCount(1, $row['warnings']);
		$this->assertStringContainsString('Rechtsform', $row['warnings'][0]);
		$this->assertStringContainsString('Organisation', $row['warnings'][0]);
	}

	// --- Was der Prüflauf schon meldet ---

	public function testStartInDerVergangenheitIstSchonInDerVorschauEinFehler(): void {
		$this->groupMapper->method('findAll')->willReturn([$this->group(1, 'Chormitglieder')]);

		$csv = "Name;E-Mail;Betrag;Frequenz;Start\nKatrin Brunner;k@example.org;5,00;monatlich;31.12.2025\n";
		$result = $this->service('2026-01-01')->preview($csv);

		$this->assertSame(1, $result['summary']['failed']);
		$this->assertSame(0, $result['summary']['ok']);
		$this->assertStringContainsString('31.12.2025', $result['rows'][0]['errors'][0]);
		$this->assertStringContainsString('Vergangenheit', $result['rows'][0]['errors'][0]);
	}

	/** Heute ist zulässig (wie bei AssignmentService::assertNotInPast) – die Grenze ist „vor heute". */
	public function testStartHeuteIstZulaessig(): void {
		$this->groupMapper->method('findAll')->willReturn([$this->group(1, 'Chormitglieder')]);

		$csv = "Name;E-Mail;Betrag;Frequenz;Start\nKatrin Brunner;k@example.org;5,00;monatlich;01.01.2026\n";
		$result = $this->service('2026-01-01')->preview($csv);

		$this->assertSame(1, $result['summary']['ok']);
		$this->assertSame([], $result['rows'][0]['errors']);
	}

	/** Dieselbe Zeile, die die Vorschau beanstandet, legt auch beim Anlegen nichts an (keine halbe Zeile). */
	public function testVergangenerStartLegtBeimImportNichtsAn(): void {
		$this->groupMapper->method('findAll')->willReturn([$this->group(1, 'Chormitglieder')]);
		$this->assignments->expects($this->never())->method('create');

		$csv = "Name;E-Mail;Betrag;Frequenz;Start\nKatrin Brunner;k@example.org;5,00;monatlich;31.12.2025\n";
		$result = $this->service('2026-01-01')->import($csv, true);

		$this->assertSame(1, $result['summary']['failed']);
		$this->assertSame([], $this->inserted);
	}

	/** Ohne auflösbare Beitragsgruppe entsteht keine Zuweisung – dann beißt auch das Startdatum nicht (nur die bekannte Gruppen-Warnung). */
	public function testVergangenerStartOhneAufloesbareGruppeBleibtEineWarnung(): void {
		$this->groupMapper->method('findAll')->willReturn([$this->group(1, 'A'), $this->group(2, 'B')]);

		$csv = "Name;Beitragsgruppe;Betrag;Frequenz;Start\nKatrin Brunner;Unbekannt;5,00;monatlich;31.12.2025\n";
		$result = $this->service('2026-01-01')->preview($csv);

		$this->assertSame(1, $result['summary']['ok']);
		$this->assertNotEmpty($result['rows'][0]['warnings']);
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function ungueltigeIbans(): array {
		return [
			'zu kurz' => ['DE12'],
			'ohne Länderkürzel' => ['0212030000000020205112'],
			'Sonderzeichen' => ['DE02-1203-0000-0000-2020-51!'],
			'Text' => ['keine iban'],
		];
	}

	/**
	 * @dataProvider ungueltigeIbans
	 */
	public function testUngueltigeIbanFormIstSchonInDerVorschauEinFehler(string $iban): void {
		$this->mandates->expects($this->never())->method('createPaper');

		$csv = "Name;IBAN;Mandat am\nKatrin Brunner;{$iban};15.01.2026\n";
		$result = $this->service()->preview($csv);

		$this->assertSame(1, $result['summary']['failed']);
		$this->assertSame(0, $result['summary']['mandates']);
		$this->assertStringContainsString('nicht nach einer IBAN', $result['rows'][0]['errors'][0]);
	}

	/** Die Prüfsumme wird nirgends geprüft, nur die Form – eine formal gültige IBAN passiert. */
	public function testFormalGueltigeIbanMitFalscherPruefsummeBleibtZulaessig(): void {
		$result = $this->service()->preview("Name;IBAN;Mandat am\nKatrin Brunner;DE00123456780000000000;15.01.2026\n");

		$this->assertSame(1, $result['summary']['ok']);
		$this->assertSame(1, $result['summary']['mandates']);
	}

	/**
	 * Eine Datei, deren einzige IBAN-Zeile wegen der Form scheitert, legt kein
	 * Mandat an – dann darf der Import auch nicht die Bestätigung dafür
	 * verlangen (die Checkbox erscheint in der Vorschau nur bei Mandaten > 0).
	 */
	public function testBestaetigungWirdNurFuerTatsaechlichAngelegteMandateVerlangt(): void {
		$csv = "Name;IBAN;Mandat am\nKaputt;DE12;15.01.2026\nKatrin Brunner;;\n";
		$result = $this->service()->import($csv, false);

		$this->assertNull($result['error']);
		$this->assertSame(1, $result['summary']['ok']);
		$this->assertSame(1, $result['summary']['failed']);
	}

	public function testDoppelteMitgliedsnummerInDerDateiIstSchonInDerVorschauSichtbar(): void {
		$csv = "Name;Mitgliedsnummer\nAnna Beispiel;0815\nBen Muster;0816\nCara Test;0815\n";
		$result = $this->service()->preview($csv);

		$this->assertSame(2, $result['summary']['ok']);
		$this->assertSame(1, $result['summary']['skipped']);
		$this->assertFalse($result['rows'][0]['skipped']);
		$this->assertTrue($result['rows'][2]['skipped']);
		$this->assertStringContainsString('Zeile 2', (string)$result['rows'][2]['skipReason']);
		$this->assertStringContainsString('Mitgliedsnummer', (string)$result['rows'][2]['skipReason']);
	}

	/** Beim Anlegen ergibt sich dasselbe: die erste Zeile entsteht, die doppelte wird übersprungen. */
	public function testDoppelteMitgliedsnummerInDerDateiLegtNurDieErsteZeileAn(): void {
		$csv = "Name;Mitgliedsnummer\nAnna Beispiel;0815\nCara Test;0815\n";
		$result = $this->service()->import($csv, true);

		$this->assertCount(1, $this->inserted);
		$this->assertSame('Anna', $this->inserted[0]->getFirstName());
		$this->assertSame(1, $result['summary']['ok']);
		$this->assertSame(1, $result['summary']['skipped']);
	}

	/** Eine fehlerhafte erste Zeile belegt ihre Nummer nicht – die zweite mit derselben Nummer wird angelegt. */
	public function testFehlerhafteZeileBelegtKeineMitgliedsnummer(): void {
		$csv = "Name;Mitgliedsnummer;E-Mail\nAnna Beispiel;0815;keine-adresse\nCara Test;0815;\n";
		$result = $this->service()->preview($csv);

		$this->assertSame(1, $result['summary']['failed']);
		$this->assertSame(1, $result['summary']['ok']);
		$this->assertSame(0, $result['summary']['skipped']);
	}

	public function testDoppeltesNextcloudKontoInDerDateiWirdUebersprungen(): void {
		$result = $this->service()->preview("Konto\nanna\nben\nanna\n");

		$this->assertSame(2, $result['summary']['ok']);
		$this->assertSame(1, $result['summary']['skipped']);
		$this->assertStringContainsString('Zeile 2', (string)$result['rows'][2]['skipReason']);
	}

	public function testOhneMailAberMitIbanUndBeitragWarntDieVorschauVorUeberweisung(): void {
		$this->groupMapper->method('findAll')->willReturn([$this->group(1, 'Chormitglieder')]);

		$csv = "Name;IBAN;Mandat am;Betrag;Frequenz;Start\nKatrin Brunner;DE02120300000000202051;15.01.2026;5,00;monatlich;01.02.2026\n";
		$row = $this->service()->preview($csv)['rows'][0];

		$this->assertSame([], $row['errors'], 'nur eine Warnung, kein Fehler');
		$this->assertCount(1, $row['warnings']);
		$this->assertStringContainsString('Überweisung statt Lastschrift', $row['warnings'][0]);
	}

	public function testMitMailKeineUeberweisungsWarnung(): void {
		$this->groupMapper->method('findAll')->willReturn([$this->group(1, 'Chormitglieder')]);

		$csv = "Name;E-Mail;IBAN;Mandat am;Betrag;Frequenz;Start\nKatrin Brunner;k@example.org;DE02120300000000202051;15.01.2026;5,00;monatlich;01.02.2026\n";
		$this->assertSame([], $this->service()->preview($csv)['rows'][0]['warnings']);
	}

	/** Ohne Mail und ohne IBAN ist Überweisung der Normalfall (Barzahler) – dort wäre eine Warnung Rauschen. */
	public function testOhneMailUndOhneIbanKeineWarnung(): void {
		$this->groupMapper->method('findAll')->willReturn([$this->group(1, 'Chormitglieder')]);

		$csv = "Name;Betrag;Frequenz;Start\nKatrin Brunner;5,00;monatlich;01.02.2026\n";
		$this->assertSame([], $this->service()->preview($csv)['rows'][0]['warnings']);
	}

	/** Ohne Beitrag entsteht keine Zuweisung und damit keine Zahlungsart. */
	public function testMandatOhneBeitragUndOhneMailKeineUeberweisungsWarnung(): void {
		$csv = "Name;IBAN;Mandat am\nKatrin Brunner;DE02120300000000202051;15.01.2026\n";
		$this->assertSame([], $this->service()->preview($csv)['rows'][0]['warnings']);
	}

	/** Bei einem verknüpften Konto zählt die Mailadresse aus dessen Kontodaten (siehe createRow()). */
	public function testMailAusDemNextcloudKontoVermeidetDieUeberweisungsWarnung(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Katrin Brunner');
		$user->method('getEMailAddress')->willReturn('k@example.org');
		$this->userManager->method('get')->willReturn($user);
		$this->groupMapper->method('findAll')->willReturn([$this->group(1, 'Chormitglieder')]);

		$csv = "Konto;IBAN;Mandat am;Betrag;Frequenz;Start\nk.brunner;DE02120300000000202051;15.01.2026;5,00;monatlich;01.02.2026\n";
		$this->assertSame([], $this->service()->preview($csv)['rows'][0]['warnings']);
	}

	/** Die Vorschau sagt voraus, was beim Anlegen passiert: ohne Mail landet die Zuweisung auf Überweisung. */
	public function testDieVorschauWarnungStimmtMitDerTatsaechlichenZahlungsartUeberein(): void {
		$this->groupMapper->method('findAll')->willReturn([$this->group(1, 'Chormitglieder')]);
		$this->mandates->method('createPaper')->willReturn((function () {
			$m = new Mandate();
			$m->setId(1);
			return $m;
		})());
		$this->mandates->method('activatePaper')->willReturn((function () {
			$m = new Mandate();
			$m->setId(1);
			$m->setStatus(Mandate::STATUS_ACTIVE);
			return $m;
		})());
		$this->assignments->expects($this->once())->method('create')
			->with($this->anything(), 1, 1, 500, Assignment::PAYMENT_METHOD_TRANSFER, '2026-02-01', null, null, null, $this->anything(), $this->anything())
			->willReturn((function () {
				$a = new Assignment();
				$a->setId(3);
				return $a;
			})());

		$csv = "Name;IBAN;Mandat am;Betrag;Frequenz;Start\nKatrin Brunner;DE02120300000000202051;15.01.2026;5,00;monatlich;01.02.2026\n";
		$result = $this->service()->import($csv, true);

		$this->assertSame(3, $result['rows'][0]['assignmentId']);
		$this->assertStringContainsString('Überweisung', $result['rows'][0]['warnings'][0]);
	}

	public function testOrganisationMitVornameNachnameZeigtDenHinweisDesParsers(): void {
		$result = $this->service()->preview("Organisation;Vorname;Nachname\nMusikhaus;Anna;Beispiel\n");

		$this->assertSame(1, $result['summary']['ok']);
		$this->assertSame(1, $result['summary']['warnings']);
	}
}
