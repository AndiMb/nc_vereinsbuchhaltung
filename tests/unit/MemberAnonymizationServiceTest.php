<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\AssignmentEvent;
use OCA\Vereinsbuchhaltung\Db\AssignmentEventMapper;
use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\DebitItem;
use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateAmendment;
use OCA\Vereinsbuchhaltung\Db\MandateAmendmentMapper;
use OCA\Vereinsbuchhaltung\Db\MandateEvent;
use OCA\Vereinsbuchhaltung\Db\MandateEventMapper;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\ReturnedDebit;
use OCA\Vereinsbuchhaltung\Db\ReturnedDebitMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Service\AnonymizationCandidateService;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\MandateDocumentService;
use OCA\Vereinsbuchhaltung\Service\MemberAnonymizationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Der Anonymisierungs-Vorgang (Spec §3.8, Issue #78, T30) – siehe
 * {@see MemberAnonymizationService}-Klassendoc für die vollständige Liste
 * der betroffenen Felder. Schwerpunkt hier: Freitexte/Namen/Bankdaten werden
 * geschwärzt, strukturierte Felder (Reason-Codes, Beträge, Daten, Status)
 * bleiben unangetastet stehen.
 */
class MemberAnonymizationServiceTest extends TestCase {

	private MemberMapper&MockObject $members;
	private MandateMapper&MockObject $mandates;
	private MandateAmendmentMapper&MockObject $amendments;
	private MandateEventMapper&MockObject $mandateEvents;
	private AssignmentMapper&MockObject $assignments;
	private AssignmentEventMapper&MockObject $assignmentEvents;
	private OpenItemMapper&MockObject $openItems;
	private DebitItemMapper&MockObject $debitItems;
	private ReturnedDebitMapper&MockObject $returnedDebits;
	private MandateDocumentService&MockObject $mandateDocuments;
	private AnonymizationCandidateService&MockObject $eligibility;

	protected function setUp(): void {
		$this->members = $this->createMock(MemberMapper::class);
		$this->mandates = $this->createMock(MandateMapper::class);
		$this->amendments = $this->createMock(MandateAmendmentMapper::class);
		$this->mandateEvents = $this->createMock(MandateEventMapper::class);
		$this->assignments = $this->createMock(AssignmentMapper::class);
		$this->assignmentEvents = $this->createMock(AssignmentEventMapper::class);
		$this->openItems = $this->createMock(OpenItemMapper::class);
		$this->debitItems = $this->createMock(DebitItemMapper::class);
		$this->returnedDebits = $this->createMock(ReturnedDebitMapper::class);
		$this->mandateDocuments = $this->createMock(MandateDocumentService::class);
		$this->eligibility = $this->createMock(AnonymizationCandidateService::class);

		// Bewusst KEIN blanket-Default per method()->willReturn() fuer die
		// findBy*-Aufrufe hier: ein zweiter method()-Aufruf in einem
		// einzelnen Test wuerde den hier gesetzten NICHT ueberschreiben
		// (PHPUnit wertet mehrere unqualifizierte method()-Stubs auf
		// demselben Mock in Konfigurationsreihenfolge aus, der erste
		// passende gewinnt) - jeder Test konfiguriert deshalb selbst, was er
		// braucht; alles andere bleibt beim PHPUnit-Standardwert fuer den
		// deklarierten Rueckgabetyp (leeres Array bzw. null).

		// update()/insert() geben in der echten QBMapper-Implementierung das
		// Entity zurueck - hier reicht "gibt weiter, was reinkam".
		foreach ([$this->members, $this->mandates, $this->amendments, $this->mandateEvents, $this->assignments, $this->assignmentEvents, $this->openItems, $this->debitItems, $this->returnedDebits] as $mapper) {
			$mapper->method('update')->willReturnArgument(0);
		}

		$this->eligibility->method('isEligible')->willReturn(true);
	}

	private function service(): MemberAnonymizationService {
		$transaction = $this->createMock(TransactionRunner::class);
		$transaction->method('run')->willReturnCallback(static fn (callable $fn) => $fn());
		// Keine Transaktion aktiv (Testkontext) - dasselbe Verhalten wie das
		// echte TransactionRunner::afterCommit() bei depth===0: sofort ausfuehren.
		$transaction->method('afterCommit')->willReturnCallback(static fn (callable $fn) => $fn());

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2037-01-02 10:00:00'));

		return new MemberAnonymizationService(
			$this->members,
			$this->mandates,
			$this->amendments,
			$this->mandateEvents,
			$this->assignments,
			$this->assignmentEvents,
			$this->openItems,
			$this->debitItems,
			$this->returnedDebits,
			$this->mandateDocuments,
			$this->eligibility,
			$transaction,
			$this->createMock(AuditService::class),
			$time,
			$l10n,
		);
	}

	private function member(int $id = 42): Member {
		$m = new Member();
		$m->setId($id);
		$m->setMemberType(Member::TYPE_PERSON);
		$m->setFirstName('Katrin');
		$m->setLastName('Brunner');
		$m->setEmail('katrin@example.org');
		$m->setPhone('0123456789');
		$m->setStreet('Musterstraße 1');
		$m->setPostalCode('12345');
		$m->setCity('Musterstadt');
		$m->setCountry('DE');
		$m->setInternalNote('Zahlt immer verspätet.');
		$m->setMemberNumber('M-42');
		$m->setJoinedAt('2010-01-01');
		return $m;
	}

	// --- Schutzschranken -----------------------------------------------------

	public function testWirftWennBereitsAnonymisiert(): void {
		$member = $this->member();
		$member->setRedactedAt('2037-01-01 00:00:00');
		$this->members->method('find')->willReturn($member);

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->anonymize(42);
	}

	public function testWirftWennNichtAnonymisierungsreif(): void {
		$this->members->method('find')->willReturn($this->member());
		$this->eligibility = $this->createMock(AnonymizationCandidateService::class);
		$this->eligibility->method('isEligible')->willReturn(false);

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->anonymize(42);
	}

	// --- Mitglied --------------------------------------------------------------

	public function testSchwaertNameUndKontaktUndSetztRedactedAt(): void {
		$member = $this->member();
		$this->members->method('find')->willReturn($member);
		$this->members->expects($this->once())->method('update')->willReturnArgument(0);

		$result = $this->service()->anonymize(42);

		$this->assertNull($result->getFirstName());
		$this->assertNull($result->getLastName());
		$this->assertNull($result->getOrganizationName());
		$this->assertNull($result->getEmail());
		$this->assertNull($result->getPhone());
		$this->assertNull($result->getStreet());
		$this->assertNull($result->getPostalCode());
		$this->assertNull($result->getCity());
		$this->assertNull($result->getCountry());
		$this->assertNull($result->getInternalNote());
		$this->assertNotNull($result->getRedactedAt());
		$this->assertTrue($result->isRedacted());
	}

	public function testBehaeltStrukturierteMitgliedsFelder(): void {
		$member = $this->member();
		$this->members->method('find')->willReturn($member);

		$result = $this->service()->anonymize(42);

		$this->assertSame(42, $result->getId());
		$this->assertSame(Member::TYPE_PERSON, $result->getMemberType());
		$this->assertSame('M-42', $result->getMemberNumber());
		$this->assertSame('2010-01-01', $result->getJoinedAt());
	}

	public function testAnzeigenameNachAnonymisierungIstPlatzhalter(): void {
		$member = $this->member();
		$this->members->method('find')->willReturn($member);

		$result = $this->service()->anonymize(42);

		$this->assertSame(Member::REDACTED_DISPLAY_NAME, $result->displayName());
	}

	// --- Mandate -----------------------------------------------------------

	private function mandate(int $id = 1, int $memberId = 42): Mandate {
		$m = new Mandate();
		$m->setId($id);
		$m->setMemberId($memberId);
		$m->setMandateReference('M-' . $id);
		$m->setIban('DE12500105170648489890');
		$m->setBic('INGDDEFFXXX');
		$m->setAccountHolder('Katrin Brunner');
		$m->setSignatureType(Mandate::SIGNATURE_ELECTRONIC);
		$m->setSignedAt('2015-01-01');
		$m->setStatus(Mandate::STATUS_ENDED);
		$m->setActivatedAt('2015-01-02T00:00:00+00:00');
		$m->setEndedAt('2020-01-01T00:00:00+00:00');
		$m->setEndReason(Mandate::END_REASON_TERMINATED);
		$m->setSuspensionNote('Rückfrage beim Mitglied ausstehend.');
		$m->setConsentIp('203.0.113.5');
		$m->setConsentUserAgent('Mozilla/5.0');
		$m->setConsentActor('katrin@example.org');
		$m->setConsentAt('2015-01-01T00:00:00+00:00');
		$m->setMandateTextVersion(3);
		return $m;
	}

	public function testSchwaertMandatsBankdatenUndBeweispaket(): void {
		$this->members->method('find')->willReturn($this->member());
		$mandate = $this->mandate();
		$this->mandates->method('findByMember')->willReturn([$mandate]);
		$this->mandates->expects($this->once())->method('update')->willReturnArgument(0);

		$this->service()->anonymize(42);

		$this->assertNull($mandate->getIban());
		$this->assertNull($mandate->getBic());
		$this->assertSame(Mandate::REDACTED_ACCOUNT_HOLDER, $mandate->getAccountHolder());
		$this->assertNull($mandate->getSuspensionNote());
		$this->assertNull($mandate->getConsentIp());
		$this->assertNull($mandate->getConsentUserAgent());
		$this->assertNull($mandate->getConsentActor());
		$this->assertNotNull($mandate->getRedactedAt());
	}

	public function testBehaeltStrukturierteMandatsFelder(): void {
		$this->members->method('find')->willReturn($this->member());
		$mandate = $this->mandate();
		$this->mandates->method('findByMember')->willReturn([$mandate]);

		$this->service()->anonymize(42);

		$this->assertSame('M-1', $mandate->getMandateReference());
		$this->assertSame(Mandate::STATUS_ENDED, $mandate->getStatus());
		$this->assertSame('2020-01-01T00:00:00+00:00', $mandate->getEndedAt());
		$this->assertSame(Mandate::END_REASON_TERMINATED, $mandate->getEndReason());
		$this->assertSame('2015-01-01T00:00:00+00:00', $mandate->getConsentAt());
		$this->assertSame(3, $mandate->getMandateTextVersion());
	}

	public function testSchwaertAmendmentBankdaten(): void {
		$this->members->method('find')->willReturn($this->member());
		$mandate = $this->mandate();
		$this->mandates->method('findByMember')->willReturn([$mandate]);

		$amendment = new MandateAmendment();
		$amendment->setId(9);
		$amendment->setMandateId(1);
		$amendment->setType(MandateAmendment::TYPE_ACCOUNT);
		$amendment->setOldIban('DE00000000000000000000');
		$amendment->setOldBic('OLDBICXXX');
		$amendment->setOldAccountHolder('Alter Name');
		$amendment->setStatus(MandateAmendment::STATUS_TRANSMITTED);
		$this->amendments->method('findByMandate')->willReturn([$amendment]);
		$this->amendments->expects($this->once())->method('update')->willReturnArgument(0);

		$this->service()->anonymize(42);

		$this->assertNull($amendment->getOldIban());
		$this->assertNull($amendment->getOldBic());
		$this->assertNull($amendment->getOldAccountHolder());
		// Strukturiert: bleibt stehen.
		$this->assertSame(MandateAmendment::TYPE_ACCOUNT, $amendment->getType());
		$this->assertSame(MandateAmendment::STATUS_TRANSMITTED, $amendment->getStatus());
	}

	public function testSchwaertMandateEventOnBehalfNoteAberNichtMessageOderActorUid(): void {
		$this->members->method('find')->willReturn($this->member());
		$mandate = $this->mandate();
		$this->mandates->method('findByMember')->willReturn([$mandate]);

		$event = new MandateEvent();
		$event->setId(5);
		$event->setMandateId(1);
		$event->setActorType(MandateEvent::ACTOR_STAFF);
		$event->setActorUid('kassenwart');
		$event->setOnBehalfNote('Im Auftrag der Tochter, die krank im Krankenhaus liegt.');
		$event->setMessage('Mandat gesperrt: Rückfrage offen');
		$event->setCreatedAt('2018-01-01T00:00:00+00:00');
		$this->mandateEvents->method('findByMandate')->willReturn([$event]);
		$this->mandateEvents->expects($this->once())->method('update')->willReturnArgument(0);

		$this->service()->anonymize(42);

		$this->assertNull($event->getOnBehalfNote());
		// Bekannte Lücke (siehe Klassendoc MemberAnonymizationService): message
		// selbst wird bewusst NICHT angefasst.
		$this->assertSame('Mandat gesperrt: Rückfrage offen', $event->getMessage());
		$this->assertSame('kassenwart', $event->getActorUid());
	}

	// --- Einzugsposten & Rücklastschriften -----------------------------------

	public function testSchwaertEinzugspostenBankdatenUndRuecklastschriftFreitext(): void {
		$this->members->method('find')->willReturn($this->member());
		$mandate = $this->mandate();
		$this->mandates->method('findByMember')->willReturn([$mandate]);

		$debitItem = new DebitItem();
		$debitItem->setId(77);
		$debitItem->setBatchId(1);
		$debitItem->setOpenItemId(1);
		$debitItem->setMandateId(1);
		$debitItem->setAmountCents(4500);
		$debitItem->setIban('DE12500105170648489890');
		$debitItem->setBic('INGDDEFFXXX');
		$debitItem->setAccountHolder('Katrin Brunner');
		$debitItem->setMandateReference('M-1');
		$debitItem->setEndToEndId('E2E-1');
		$debitItem->setRemittanceInfo('Beitrag 2018');
		$this->debitItems->method('findByMandate')->willReturn([$debitItem]);
		$this->debitItems->expects($this->once())->method('update')->willReturnArgument(0);

		$returnedDebit = new ReturnedDebit();
		$returnedDebit->setId(3);
		$returnedDebit->setDebitItemId(77);
		$returnedDebit->setReasonCode('AC04');
		$returnedDebit->setReasonText('Konto wegen Erbschaftsstreit gesperrt, siehe Telefonat.');
		$returnedDebit->setReceivedAt('2018-02-01T00:00:00+00:00');
		$returnedDebit->setChargesCents(500);
		$this->returnedDebits->method('findByDebitItem')->willReturn($returnedDebit);
		$this->returnedDebits->expects($this->once())->method('update')->willReturnArgument(0);

		$this->service()->anonymize(42);

		$this->assertNull($debitItem->getIban());
		$this->assertNull($debitItem->getBic());
		$this->assertSame(Mandate::REDACTED_ACCOUNT_HOLDER, $debitItem->getAccountHolder());
		// Strukturiert: bleibt stehen.
		$this->assertSame(4500, $debitItem->getAmountCents());
		$this->assertSame('E2E-1', $debitItem->getEndToEndId());

		$this->assertNull($returnedDebit->getReasonText());
		// Strukturiert: bleibt stehen.
		$this->assertSame('AC04', $returnedDebit->getReasonCode());
		$this->assertSame(500, $returnedDebit->getChargesCents());
	}

	// --- Forderungen (Claims) ------------------------------------------------

	private function claim(int $id, int $memberId): OpenItem {
		$item = new OpenItem();
		$item->setId($id);
		$item->setMemberId($memberId);
		$item->setType(OpenItem::TYPE_CONTRIBUTION);
		$item->setDebtor('Katrin Brunner');
		$item->setDescription('Beitrag 2018');
		$item->setAmountCents(4500);
		$item->setDueDate('2018-01-31');
		$item->setStatus('paid');
		$item->setSettlementNote('Bar in der Vereinsheim-Kasse übergeben.');
		$item->setDeferredReason('Wegen Kurzarbeit gestundet.');
		$item->setCancelledReason('Doppelt angelegt.');
		return $item;
	}

	public function testSchwaertForderungsFreitexteUndDebtorNameBehaeltStrukturierteFelder(): void {
		$this->members->method('find')->willReturn($this->member());
		$item = $this->claim(11, 42);
		$this->openItems->method('findByMember')->willReturn([$item]);
		$this->openItems->expects($this->once())->method('update')->willReturnArgument(0);

		$this->service()->anonymize(42);

		$this->assertSame(Member::REDACTED_DISPLAY_NAME, $item->getDebtor());
		$this->assertNull($item->getSettlementNote());
		$this->assertNull($item->getDeferredReason());
		$this->assertNull($item->getCancelledReason());
		// Strukturiert: bleibt stehen.
		$this->assertSame(4500, $item->getAmountCents());
		$this->assertSame('2018-01-31', $item->getDueDate());
		$this->assertSame('paid', $item->getStatus());
		$this->assertSame('Beitrag 2018', $item->getDescription());
	}

	public function testUeberspringtForderungOhnePersonenbezogeneFreitexte(): void {
		$this->members->method('find')->willReturn($this->member());
		$item = $this->claim(11, 42);
		$item->setDebtor(Member::REDACTED_DISPLAY_NAME);
		$item->setSettlementNote(null);
		$item->setDeferredReason(null);
		$item->setCancelledReason(null);
		$this->openItems->method('findByMember')->willReturn([$item]);
		$this->openItems->expects($this->never())->method('update');

		$this->service()->anonymize(42);
	}

	// --- Zuweisungen ---------------------------------------------------------

	public function testSchwaertAssignmentOverrideReasonUndEventOnBehalfNote(): void {
		$this->members->method('find')->willReturn($this->member());
		$assignment = new Assignment();
		$assignment->setId(4);
		$assignment->setMemberId(42);
		$assignment->setGroupId(1);
		$assignment->setIntervalMonths(12);
		$assignment->setMonthlyAmountCents(1000);
		$assignment->setOverrideReason('Sozialermäßigung wegen Pflegebedürftigkeit.');
		$assignment->setPaymentMethod(Assignment::PAYMENT_METHOD_DIRECT_DEBIT);
		$assignment->setValidFrom('2015-01-01');
		$this->assignments->method('findByMember')->willReturn([$assignment]);
		$this->assignments->expects($this->once())->method('update')->willReturnArgument(0);

		$event = new AssignmentEvent();
		$event->setId(6);
		$event->setAssignmentId(4);
		$event->setType(AssignmentEvent::TYPE_ASSIGNMENT_STARTED);
		$event->setActorType(AssignmentEvent::ACTOR_STAFF);
		$event->setOnBehalfNote('Im Auftrag des Betreuers.');
		$event->setCreatedAt('2015-01-01T00:00:00+00:00');
		$this->assignmentEvents->method('findByAssignment')->willReturn([$event]);
		$this->assignmentEvents->expects($this->once())->method('update')->willReturnArgument(0);

		$this->service()->anonymize(42);

		$this->assertNull($assignment->getOverrideReason());
		$this->assertSame(1000, $assignment->getMonthlyAmountCents());
		$this->assertNull($event->getOnBehalfNote());
		$this->assertSame(AssignmentEvent::TYPE_ASSIGNMENT_STARTED, $event->getType());
	}

	// --- Nachweis-Datei --------------------------------------------------------

	public function testLoeschtVerwieseneNachweisDateiBestEffortUndLeertDieReferenz(): void {
		$this->members->method('find')->willReturn($this->member());
		$mandate = $this->mandate();
		$mandate->setDocumentFileId(123);
		$this->mandates->method('findByMember')->willReturn([$mandate]);

		$file = $this->createMock(File::class);
		$file->expects($this->once())->method('delete');
		$this->mandateDocuments->method('nodeOrNull')->willReturn($file);

		$this->service()->anonymize(42);

		$this->assertNull($mandate->getDocumentFileId());
	}

	public function testFehlendeNachweisDateiBlockiertDieAnonymisierungNicht(): void {
		$this->members->method('find')->willReturn($this->member());
		$mandate = $this->mandate();
		$mandate->setDocumentFileId(123);
		$this->mandates->method('findByMember')->willReturn([$mandate]);
		$this->mandateDocuments->method('nodeOrNull')->willReturn(null);

		$result = $this->service()->anonymize(42);

		$this->assertNotNull($result->getRedactedAt());
		$this->assertNull($mandate->getDocumentFileId());
	}
}
