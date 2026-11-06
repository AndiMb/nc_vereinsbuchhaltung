<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\ReimbursementMapper;
use OCA\Vereinsbuchhaltung\Service\AnonymizationCandidateService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * „Wer ist anonymisierungsreif?" (Spec §3.8/§7 „Anonymisierungs-Vorschlag",
 * Issue #78, T30) – siehe {@see AnonymizationCandidateService}-Klassendoc für
 * die Abgrenzung von „letzter zugehöriger Buchung" und die beiden
 * zusätzlichen Schranken (nicht mehr aktiv, kein lebendes Mandat).
 *
 * Welche Buchungen zu einem Mitglied gehören (Zahlung einer Forderung,
 * Auszahlung einer Auslage, Rücklastschrift auf einem seiner Mandate), legt
 * seit Issue #252 eine Sammelabfrage fest
 * ({@see JournalMapper::lastMemberBookingDates()}) – hier gemockt; geprüft
 * wird die Regel, die auf dem Datum aufsetzt.
 */
class AnonymizationCandidateServiceTest extends TestCase {

	private MemberMapper&MockObject $members;
	private MandateMapper&MockObject $mandates;
	private ReimbursementMapper&MockObject $reimbursements;
	private JournalMapper&MockObject $journals;

	protected function setUp(): void {
		$this->members = $this->createMock(MemberMapper::class);
		$this->mandates = $this->createMock(MandateMapper::class);
		$this->reimbursements = $this->createMock(ReimbursementMapper::class);
		$this->journals = $this->createMock(JournalMapper::class);

		// Bewusst KEIN blanket-Default per method()->willReturn() hier: ein
		// zweiter method()-Aufruf in einem einzelnen Test wuerde diesen NICHT
		// ueberschreiben (PHPUnit wertet mehrere unqualifizierte
		// method()-Stubs auf demselben Mock in Konfigurationsreihenfolge aus,
		// der erste passende gewinnt) - jeder Test konfiguriert deshalb
		// selbst, was er braucht; alles andere bleibt beim
		// PHPUnit-Standardwert fuer den deklarierten Rueckgabetyp (leeres
		// Array bzw. null).
	}

	private function service(string $today = '2037-06-01'): AnonymizationCandidateService {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime($today . ' 12:00:00'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		return new AnonymizationCandidateService(
			$this->members,
			$this->mandates,
			$this->reimbursements,
			$this->journals,
			$time,
			$l10n,
		);
	}

	/** Ausgetretenes Mitglied ohne lebendes Mandat - erfüllt beide Zusatzschranken (siehe Klassendoc). */
	private function inactiveMember(int $id = 42): Member {
		$m = new Member();
		$m->setId($id);
		$m->setMemberType(Member::TYPE_PERSON);
		$m->setLastName('Brunner');
		$m->setJoinedAt('2010-01-01');
		$m->setLeftAt('2020-01-01');
		return $m;
	}

	/**
	 * Letzte Buchung je Mitglied, wie sie die Sammelabfrage liefern würde.
	 *
	 * @param array<int,string> $dates Mitglieds-ID => Datum
	 */
	private function lastBookings(array $dates): void {
		$this->journals->method('lastMemberBookingDates')
			->willReturnCallback(static fn (array $ids, string $userId): array => $userId === Application::BOOK
				? array_intersect_key($dates, array_flip($ids))
				: []);
	}

	// --- lastBookingDate() ----------------------------------------------------

	public function testLastBookingDateNullOhneJedeBuchung(): void {
		$this->assertNull($this->service()->lastBookingDate(42));
	}

	public function testLastBookingDateAusDerSammelabfrageImVereinsbuch(): void {
		$this->lastBookings([42 => '2027-02-10', 7 => '2030-01-01']);

		$this->assertSame('2027-02-10', $this->service()->lastBookingDate(42));
	}

	// --- isEligible()/statusFor() -----------------------------------------------

	public function testNichtReifOhneBuchung(): void {
		$this->members->method('find')->willReturn($this->inactiveMember());
		$this->assertFalse($this->service()->isEligible(42));
	}

	public function testNichtReifVorDemZehnJahresStichtag(): void {
		$this->members->method('find')->willReturn($this->inactiveMember());
		$this->lastBookings([42 => '2026-05-15']);

		$this->assertFalse($this->service('2036-12-31')->isEligible(42));
	}

	public function testReifNachDemZehnJahresStichtag(): void {
		$this->members->method('find')->willReturn($this->inactiveMember());
		$this->lastBookings([42 => '2026-05-15']);

		$this->assertTrue($this->service('2037-01-01')->isEligible(42));
	}

	public function testStatusNenntDatumUndStichtag(): void {
		$this->members->method('find')->willReturn($this->inactiveMember());
		$this->lastBookings([42 => '2026-05-15']);

		$status = $this->service('2037-06-01')->statusFor(42);

		$this->assertSame('2026-05-15', $status['lastBookingDate']);
		$this->assertSame('2037-01-01', $status['cutoffDate']);
		$this->assertTrue($status['eligible']);
	}

	public function testNichtReifSolangeAuslagenantraegeOffenSind(): void {
		$this->members->method('find')->willReturn($this->inactiveMember());
		$this->lastBookings([42 => '2026-05-15']);
		$this->reimbursements->method('countPendingByMember')->with(42)->willReturn(1);

		$this->assertFalse($this->service('2037-06-01')->isEligible(42));
	}

	public function testNichtReifWennBereitsAnonymisiert(): void {
		$member = $this->inactiveMember();
		$member->setRedactedAt('2037-01-01 00:00:00');
		$this->members->method('find')->willReturn($member);
		$this->lastBookings([42 => '2026-05-15']);

		$this->assertFalse($this->service('2037-06-01')->isEligible(42));
	}

	public function testNichtReifSolangeMitgliedNochAktivIst(): void {
		$member = new Member();
		$member->setId(42);
		$member->setJoinedAt('2010-01-01');
		// Kein Austritt - Member::isActive() ist true.
		$this->members->method('find')->willReturn($member);
		$this->lastBookings([42 => '2026-05-15']);

		$this->assertFalse($this->service('2037-06-01')->isEligible(42));
	}

	public function testNichtReifSolangeEinMandatNochLebt(): void {
		$this->members->method('find')->willReturn($this->inactiveMember());
		$this->lastBookings([42 => '2026-05-15']);

		$liveMandate = new Mandate();
		$liveMandate->setId(1);
		$liveMandate->setMemberId(42);
		$liveMandate->setStatus(Mandate::STATUS_SUSPENDED); // "lebt" noch (isLive() true), nur ended ist es nicht
		$this->mandates->method('findByMember')->willReturn([$liveMandate]);

		$this->assertFalse($this->service('2037-06-01')->isEligible(42));
	}

	public function testReifTrotzBeendetenMandats(): void {
		$this->members->method('find')->willReturn($this->inactiveMember());
		$this->lastBookings([42 => '2026-05-15']);

		$endedMandate = new Mandate();
		$endedMandate->setId(1);
		$endedMandate->setMemberId(42);
		$endedMandate->setStatus(Mandate::STATUS_ENDED);
		$this->mandates->method('findByMember')->willReturn([$endedMandate]);

		$this->assertTrue($this->service('2037-06-01')->isEligible(42));
	}

	// --- findTasks() -----------------------------------------------------------

	public function testFindTasksMeldetNurReifeMitglieder(): void {
		$reif = $this->inactiveMember(1);
		$nichtReif = $this->inactiveMember(2);
		$this->members->method('findAll')->willReturn([$reif, $nichtReif]);
		$this->lastBookings([
			1 => '2026-05-15', // laengst reif
			2 => '2036-01-01', // noch nicht
		]);

		$tasks = $this->service('2037-06-01')->findTasks();

		$this->assertCount(1, $tasks);
		$this->assertSame('member', $tasks[0]['objectType']);
		$this->assertSame(1, $tasks[0]['objectId']);
	}

	public function testFindTasksFragtDieLetztenBuchungenGesammeltAb(): void {
		// Eine Sammelabfrage für alle Ausgetretenen, nicht eine je Mitglied (Issue #252).
		$this->members->method('findAll')->willReturn([$this->inactiveMember(1), $this->inactiveMember(2), $this->inactiveMember(3)]);
		$this->journals->expects($this->once())->method('lastMemberBookingDates')
			->with([1, 2, 3], Application::BOOK)
			->willReturn([1 => '2026-05-15']);
		$this->members->expects($this->never())->method('find');

		$this->assertCount(1, $this->service('2037-06-01')->findTasks());
	}

	public function testAufgabentextNenntKeinenSpecAbschnittUndStehtNameUnmaskiertDavor(): void {
		// Der Text geht an Endnutzer: kein Verweis auf Spec-Abschnitte, und der Name steht
		// vor dem übersetzten Text statt als t()-Platzhalter (t() maskiert HTML-Zeichen).
		$member = $this->inactiveMember(1);
		$member->setMemberType(Member::TYPE_ORGANIZATION);
		$member->setOrganizationName('Müller & Söhne');
		$member->setLastName(null);
		$this->members->method('findAll')->willReturn([$member]);
		$this->lastBookings([1 => '2026-05-15']);

		$tasks = $this->service('2037-06-01')->findTasks();

		$this->assertCount(1, $tasks);
		$this->assertSame(\OCA\Vereinsbuchhaltung\Db\Task::SEVERITY_HINT, $tasks[0]['severity']);
		$this->assertStringStartsWith('Müller & Söhne: anonymisierungsreif', $tasks[0]['message']);
		$this->assertStringNotContainsString('Spec', $tasks[0]['message']);
		$this->assertStringNotContainsString('§', $tasks[0]['message']);
	}

	public function testFindTasksPrueftAktiveMitgliederGarNichtErst(): void {
		// Ein aktives Mitglied kann nie reif sein: die Prüfung läuft bei jedem Öffnen der
		// Aufgabenliste - nur für Ausgetretene.
		$active = $this->inactiveMember(1);
		$active->setLeftAt(null);
		$this->members->method('findAll')->willReturn([$active]);
		$this->journals->expects($this->never())->method('lastMemberBookingDates');
		$this->mandates->expects($this->never())->method('findByMember');

		$this->assertSame([], $this->service('2037-06-01')->findTasks());
	}
}
