<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\DebitItem;
use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\Journal;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\ReturnedDebit;
use OCA\Vereinsbuchhaltung\Db\ReturnedDebitMapper;
use OCA\Vereinsbuchhaltung\Service\AnonymizationCandidateService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * „Wer ist anonymisierungsreif?" (Spec §3.8/§7 „Anonymisierungs-Vorschlag",
 * Issue #78, T30) – siehe {@see AnonymizationCandidateService}-Klassendoc für
 * die Abgrenzung von „letzter zugehöriger Buchung" und die beiden
 * zusätzlichen Schranken (nicht mehr aktiv, kein lebendes Mandat).
 */
class AnonymizationCandidateServiceTest extends TestCase {

	private MemberMapper&MockObject $members;
	private OpenItemMapper&MockObject $openItems;
	private MandateMapper&MockObject $mandates;
	private DebitItemMapper&MockObject $debitItems;
	private ReturnedDebitMapper&MockObject $returnedDebits;
	private JournalMapper&MockObject $journals;

	protected function setUp(): void {
		$this->members = $this->createMock(MemberMapper::class);
		$this->openItems = $this->createMock(OpenItemMapper::class);
		$this->mandates = $this->createMock(MandateMapper::class);
		$this->debitItems = $this->createMock(DebitItemMapper::class);
		$this->returnedDebits = $this->createMock(ReturnedDebitMapper::class);
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
			$this->openItems,
			$this->mandates,
			$this->debitItems,
			$this->returnedDebits,
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

	private function journal(int $id, string $date): Journal {
		$j = new Journal();
		$j->setId($id);
		$j->setDate($date);
		return $j;
	}

	// --- lastBookingDate() ----------------------------------------------------

	public function testLastBookingDateNullOhneJedeBuchung(): void {
		$this->assertNull($this->service()->lastBookingDate(42));
	}

	public function testLastBookingDateAusBezahlterForderung(): void {
		$item = new OpenItem();
		$item->setId(1);
		$item->setMemberId(42);
		$item->setPaidJournalId(100);
		$this->openItems->method('findByMember')->willReturn([$item]);
		$this->journals->method('find')->with(100, $this->anything())->willReturn($this->journal(100, '2026-05-15'));

		$this->assertSame('2026-05-15', $this->service()->lastBookingDate(42));
	}

	public function testLastBookingDateAusRuecklastschriftgebuehr(): void {
		$mandate = new Mandate();
		$mandate->setId(1);
		$mandate->setMemberId(42);
		$this->mandates->method('findByMember')->willReturn([$mandate]);

		$debitItem = new DebitItem();
		$debitItem->setId(5);
		$this->debitItems->method('findByMandate')->willReturn([$debitItem]);

		$returnedDebit = new ReturnedDebit();
		$returnedDebit->setId(9);
		$returnedDebit->setDebitItemId(5);
		$returnedDebit->setJournalId(200);
		$this->returnedDebits->method('findByDebitItem')->willReturn($returnedDebit);
		$this->journals->method('find')->with(200, $this->anything())->willReturn($this->journal(200, '2027-02-10'));

		$this->assertSame('2027-02-10', $this->service()->lastBookingDate(42));
	}

	public function testLastBookingDateIstDasSpaetesteDatum(): void {
		$item = new OpenItem();
		$item->setId(1);
		$item->setMemberId(42);
		$item->setPaidJournalId(100);
		$this->openItems->method('findByMember')->willReturn([$item]);

		$mandate = new Mandate();
		$mandate->setId(1);
		$this->mandates->method('findByMember')->willReturn([$mandate]);
		$debitItem = new DebitItem();
		$debitItem->setId(5);
		$this->debitItems->method('findByMandate')->willReturn([$debitItem]);
		$returnedDebit = new ReturnedDebit();
		$returnedDebit->setDebitItemId(5);
		$returnedDebit->setJournalId(200);
		$this->returnedDebits->method('findByDebitItem')->willReturn($returnedDebit);

		$this->journals->method('find')->willReturnMap([
			[100, Application::BOOK, $this->journal(100, '2020-01-01')],
			[200, Application::BOOK, $this->journal(200, '2027-02-10')],
		]);

		$this->assertSame('2027-02-10', $this->service()->lastBookingDate(42));
	}

	public function testIgnoriertGeloeschteBuchung(): void {
		$item = new OpenItem();
		$item->setId(1);
		$item->setMemberId(42);
		$item->setPaidJournalId(100);
		$this->openItems->method('findByMember')->willReturn([$item]);
		$this->journals->method('find')->willThrowException(new DoesNotExistException('weg'));

		$this->assertNull($this->service()->lastBookingDate(42));
	}

	// --- isEligible()/statusFor() -----------------------------------------------

	public function testNichtReifOhneBuchung(): void {
		$this->members->method('find')->willReturn($this->inactiveMember());
		$this->assertFalse($this->service()->isEligible(42));
	}

	public function testNichtReifVorDemZehnJahresStichtag(): void {
		$this->members->method('find')->willReturn($this->inactiveMember());
		$item = new OpenItem();
		$item->setMemberId(42);
		$item->setPaidJournalId(100);
		$this->openItems->method('findByMember')->willReturn([$item]);
		$this->journals->method('find')->willReturn($this->journal(100, '2026-05-15'));

		$this->assertFalse($this->service('2036-12-31')->isEligible(42));
	}

	public function testReifNachDemZehnJahresStichtag(): void {
		$this->members->method('find')->willReturn($this->inactiveMember());
		$item = new OpenItem();
		$item->setMemberId(42);
		$item->setPaidJournalId(100);
		$this->openItems->method('findByMember')->willReturn([$item]);
		$this->journals->method('find')->willReturn($this->journal(100, '2026-05-15'));

		$this->assertTrue($this->service('2037-01-01')->isEligible(42));
	}

	public function testNichtReifWennBereitsAnonymisiert(): void {
		$member = $this->inactiveMember();
		$member->setRedactedAt('2037-01-01 00:00:00');
		$this->members->method('find')->willReturn($member);
		$item = new OpenItem();
		$item->setMemberId(42);
		$item->setPaidJournalId(100);
		$this->openItems->method('findByMember')->willReturn([$item]);
		$this->journals->method('find')->willReturn($this->journal(100, '2026-05-15'));

		$this->assertFalse($this->service('2037-06-01')->isEligible(42));
	}

	public function testNichtReifSolangeMitgliedNochAktivIst(): void {
		$member = new Member();
		$member->setId(42);
		$member->setJoinedAt('2010-01-01');
		// Kein Austritt - Member::isActive() ist true.
		$this->members->method('find')->willReturn($member);
		$item = new OpenItem();
		$item->setMemberId(42);
		$item->setPaidJournalId(100);
		$this->openItems->method('findByMember')->willReturn([$item]);
		$this->journals->method('find')->willReturn($this->journal(100, '2026-05-15'));

		$this->assertFalse($this->service('2037-06-01')->isEligible(42));
	}

	public function testNichtReifSolangeEinMandatNochLebt(): void {
		$this->members->method('find')->willReturn($this->inactiveMember());
		$item = new OpenItem();
		$item->setMemberId(42);
		$item->setPaidJournalId(100);
		$this->openItems->method('findByMember')->willReturn([$item]);
		$this->journals->method('find')->willReturn($this->journal(100, '2026-05-15'));

		$liveMandate = new Mandate();
		$liveMandate->setId(1);
		$liveMandate->setMemberId(42);
		$liveMandate->setStatus(Mandate::STATUS_SUSPENDED); // "lebt" noch (isLive() true), nur ended ist es nicht
		$this->mandates->method('findByMember')->willReturn([$liveMandate]);

		$this->assertFalse($this->service('2037-06-01')->isEligible(42));
	}

	public function testReifTrotzBeendetenMandats(): void {
		$this->members->method('find')->willReturn($this->inactiveMember());
		$item = new OpenItem();
		$item->setMemberId(42);
		$item->setPaidJournalId(100);
		$this->openItems->method('findByMember')->willReturn([$item]);
		$this->journals->method('find')->willReturn($this->journal(100, '2026-05-15'));

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
		$this->members->method('find')->willReturnMap([
			[1, $reif],
			[2, $nichtReif],
		]);

		$item1 = new OpenItem();
		$item1->setMemberId(1);
		$item1->setPaidJournalId(100);
		$item2 = new OpenItem();
		$item2->setMemberId(2);
		$item2->setPaidJournalId(200);
		$this->openItems->method('findByMember')->willReturnMap([
			[1, [$item1]],
			[2, [$item2]],
		]);
		$this->journals->method('find')->willReturnMap([
			[100, Application::BOOK, $this->journal(100, '2026-05-15')], // laengst reif
			[200, Application::BOOK, $this->journal(200, '2036-01-01')], // noch nicht
		]);

		$tasks = $this->service('2037-06-01')->findTasks();

		$this->assertCount(1, $tasks);
		$this->assertSame('member', $tasks[0]['objectType']);
		$this->assertSame(1, $tasks[0]['objectId']);
	}
}
