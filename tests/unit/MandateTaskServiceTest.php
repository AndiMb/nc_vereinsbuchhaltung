<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\ReturnedDebit;
use OCA\Vereinsbuchhaltung\Db\ReturnedDebitMapper;
use OCA\Vereinsbuchhaltung\Db\Task;
use OCA\Vereinsbuchhaltung\Service\MandateDocumentService;
use OCA\Vereinsbuchhaltung\Service\MandateExpiryCalculator;
use OCA\Vereinsbuchhaltung\Service\MandateService;
use OCA\Vereinsbuchhaltung\Service\MandateTaskService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Mandat-Aufgaben des Aufgaben-Katalogs (Spec §3.2/§7, Issue #117): je
 * Aufgabentyp mindestens „erscheint“ und „verschwindet, sobald die Ursache
 * behoben ist“ – die Aufgaben sind abgeleitet, nichts wird quittiert.
 */
class MandateTaskServiceTest extends TestCase {

	private const TODAY = '2026-10-04';

	private MandateMapper&MockObject $mandates;
	private MandateService&MockObject $mandateService;
	private MandateDocumentService&MockObject $documents;
	private MemberMapper&MockObject $members;
	private AssignmentMapper&MockObject $assignments;
	private OpenItemMapper&MockObject $openItems;
	private ReturnedDebitMapper&MockObject $returnedDebits;

	protected function setUp(): void {
		$this->mandates = $this->createMock(MandateMapper::class);
		$this->mandateService = $this->createMock(MandateService::class);
		$this->documents = $this->createMock(MandateDocumentService::class);
		$this->members = $this->createMock(MemberMapper::class);
		$this->assignments = $this->createMock(AssignmentMapper::class);
		$this->openItems = $this->createMock(OpenItemMapper::class);
		$this->returnedDebits = $this->createMock(ReturnedDebitMapper::class);
	}

	/**
	 * @param list<Member> $members
	 * @return array<int,Member> wie MemberMapper::findAllById()
	 */
	private static function byId(array $members): array {
		$map = [];
		foreach ($members as $member) {
			$map[(int)$member->getId()] = $member;
		}
		return $map;
	}

	private function service(): MandateTaskService {
		// Wie die echte Übersetzung HTML-Zeichen in Variablen maskiert: ein Name als
		// t()-Platzhalter käme als „Müller &amp; Söhne“ an (siehe Klassendoc des Dienstes).
		$escape = static fn (array $params): array => array_map(static fn ($p) => is_string($p) ? htmlspecialchars($p) : $p, $params);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $escape($params)));
		$l10n->method('n')->willReturnCallback(static function (string $singular, string $plural, int $count, array $params = []) use ($escape): string {
			return vsprintf(str_replace('%n', (string)$count, $count === 1 ? $singular : $plural), $escape($params));
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime(self::TODAY . ' 12:00:00'));

		return new MandateTaskService(
			$this->mandates,
			$this->mandateService,
			new MandateExpiryCalculator(),
			$this->documents,
			$this->members,
			$this->assignments,
			$this->openItems,
			$this->returnedDebits,
			$time,
			$l10n,
		);
	}

	private function member(int $id, string $firstName = 'Max', string $lastName = 'Mustermann'): Member {
		$member = new Member();
		$member->setId($id);
		$member->setMemberType(Member::TYPE_PERSON);
		$member->setFirstName($firstName);
		$member->setLastName($lastName);
		$member->setJoinedAt('2020-01-01');
		return $member;
	}

	/** @param array<string,mixed> $fields zusätzliche Felder, je als setXyz() */
	private function mandate(int $id, int $memberId, string $status, array $fields = []): Mandate {
		$mandate = new Mandate();
		$mandate->setId($id);
		$mandate->setMemberId($memberId);
		$mandate->setMandateReference('M-' . $id);
		$mandate->setStatus($status);
		$mandate->setSignatureType(Mandate::SIGNATURE_PAPER);
		foreach ($fields as $field => $value) {
			$mandate->{'set' . ucfirst($field)}($value);
		}
		return $mandate;
	}

	private function directDebitAssignment(int $memberId, string $method = Assignment::PAYMENT_METHOD_DIRECT_DEBIT): Assignment {
		$assignment = new Assignment();
		$assignment->setMemberId($memberId);
		$assignment->setPaymentMethod($method);
		$assignment->setValidFrom('2026-01-01');
		return $assignment;
	}

	private function claim(int $id, int $memberId, int $amountCents = 1000, string $status = 'open'): OpenItem {
		$claim = new OpenItem();
		$claim->setId($id);
		$claim->setMemberId($memberId);
		$claim->setType(OpenItem::TYPE_CONTRIBUTION);
		$claim->setStatus($status);
		$claim->setAmountCents($amountCents);
		return $claim;
	}

	/**
	 * @param list<Mandate> $mandates
	 * @param list<Member> $members
	 */
	private function givenBestand(array $mandates, array $members): void {
		$this->mandates->method('findAll')->willReturn($mandates);
		$this->members->method('findAllById')->willReturn(self::byId($members));
	}

	/** @param list<array<string,mixed>> $tasks */
	private function only(array $tasks): array {
		$this->assertCount(1, $tasks);
		return $tasks[0];
	}

	// --- Allgemeines --------------------------------------------------------------------

	public function testOhneMandateKeineAufgabenUndKeineWeiterenAbfragen(): void {
		$this->mandates->method('findAll')->willReturn([]);
		$this->members->expects($this->never())->method('findAllById');
		$this->mandateService->expects($this->never())->method('findDueForExpiryWarning');

		$this->assertSame([], $this->service()->findTasks(self::TODAY));
	}

	public function testEinzugsfaehigesMandatMitNachweisErzeugtNichts(): void {
		$this->documents->method('showMissingDocumentWarning')->willReturn(true);
		$this->givenBestand(
			[$this->mandate(1, 7, Mandate::STATUS_ACTIVE, ['documentFileId' => 55])],
			[$this->member(7)],
		);

		$this->assertSame([], $this->service()->findTasks(self::TODAY));
	}

	public function testMandatenImBestandKostenKeineAbfrageJeMandat(): void {
		// Viele Entwürfe: Mitglieder werden einmal geladen, Zuweisungen, Forderungen und
		// Rücklastschriften gar nicht - die Liste wird bei jedem Öffnen des Flyouts gerechnet.
		$mandates = [];
		$members = [];
		for ($i = 1; $i <= 40; $i++) {
			$mandates[] = $this->mandate($i, $i, Mandate::STATUS_DRAFT);
			$members[] = $this->member($i);
		}
		$this->mandates->expects($this->once())->method('findAll')->willReturn($mandates);
		$this->members->expects($this->once())->method('findAllById')->willReturn(self::byId($members));
		$this->mandates->expects($this->never())->method('find');
		$this->members->expects($this->never())->method('find');
		$this->assignments->expects($this->never())->method('findActiveAsOf');
		$this->openItems->expects($this->never())->method('findClaims');
		$this->returnedDebits->expects($this->never())->method('findAllByDebitItem');

		$this->assertCount(40, $this->service()->findTasks(self::TODAY));
	}

	public function testNameStehtUnmaskiertImText(): void {
		// Ein Name als t()-Platzhalter würde „&“ zu „&amp;“ machen.
		$organisation = new Member();
		$organisation->setId(7);
		$organisation->setMemberType(Member::TYPE_ORGANIZATION);
		$organisation->setOrganizationName('Müller & Söhne');
		$organisation->setJoinedAt('2020-01-01');
		$this->givenBestand([$this->mandate(1, 7, Mandate::STATUS_DRAFT)], [$organisation]);

		$task = $this->only($this->service()->findTasks(self::TODAY));

		$this->assertStringStartsWith('Müller & Söhne: ', $task['message']);
		$this->assertStringNotContainsString('&amp;', $task['message']);
	}

	public function testUnbekanntesMitgliedBekommtEinenErsatzNamenUndKeinSprungziel(): void {
		$this->givenBestand([$this->mandate(1, 99, Mandate::STATUS_DRAFT)], []);

		$task = $this->only($this->service()->findTasks(self::TODAY));

		$this->assertStringStartsWith('unbekanntes Mitglied: ', $task['message']);
		$this->assertNull($task['memberId']);
	}

	public function testAnonymisiertesMitgliedBekommtKeineAufgabe(): void {
		$redacted = $this->member(7);
		$redacted->setRedactedAt('2026-01-01 00:00:00');
		$this->givenBestand([$this->mandate(1, 7, Mandate::STATUS_DRAFT)], [$redacted]);

		$this->assertSame([], $this->service()->findTasks(self::TODAY));
	}

	// --- Mandat-Entwurf Papier: Unterschrift fehlt --------------------------------------

	public function testPapierEntwurfIstHandlungsbedarfMitSprungzielInDieAkte(): void {
		$this->givenBestand([$this->mandate(3, 7, Mandate::STATUS_DRAFT)], [$this->member(7)]);

		$task = $this->only($this->service()->findTasks(self::TODAY));

		$this->assertSame(Task::SEVERITY_ACTION_REQUIRED, $task['severity']);
		$this->assertStringStartsWith('Max Mustermann: ', $task['message']);
		$this->assertStringContainsString('Unterschrift fehlt', $task['message']);
		$this->assertSame('mandate', $task['objectType']);
		$this->assertSame(3, $task['objectId']);
		$this->assertSame(7, $task['memberId']);
	}

	public function testPapierEntwurfMitUnterschriftsdatumSagtDassNurDieAktivierungFehlt(): void {
		$this->givenBestand([$this->mandate(3, 7, Mandate::STATUS_DRAFT, ['signedAt' => '2026-09-01'])], [$this->member(7)]);

		$task = $this->only($this->service()->findTasks(self::TODAY));

		$this->assertSame(Task::SEVERITY_ACTION_REQUIRED, $task['severity']);
		$this->assertStringContainsString('Unterschriftsdatum ist eingetragen', $task['message']);
		$this->assertStringContainsString('nicht aktiviert', $task['message']);
		$this->assertStringNotContainsString('Unterschrift fehlt', $task['message']);
	}

	public function testElektronischerEntwurfGehoertNichtHierher(): void {
		// Dafür gibt es MandateActivationService::findStaleElectronicDraftTasks().
		$this->givenBestand(
			[$this->mandate(3, 7, Mandate::STATUS_DRAFT, ['signatureType' => Mandate::SIGNATURE_ELECTRONIC])],
			[$this->member(7)],
		);

		$this->assertSame([], $this->service()->findTasks(self::TODAY));
	}

	public function testPapierEntwurfVerschwindetMitDerAktivierung(): void {
		$this->documents->method('showMissingDocumentWarning')->willReturn(false);
		$this->mandates->method('findAll')->willReturnOnConsecutiveCalls(
			[$this->mandate(3, 7, Mandate::STATUS_DRAFT)],
			[$this->mandate(3, 7, Mandate::STATUS_ACTIVE, ['signedAt' => '2026-09-01'])],
		);
		$this->members->method('findAllById')->willReturn(self::byId([$this->member(7)]));
		$service = $this->service();

		$this->assertCount(1, $service->findTasks(self::TODAY));
		$this->assertSame([], $service->findTasks(self::TODAY));
	}

	// --- Mandat gesperrt, Klärung offen -------------------------------------------------

	public function testManuelleSperreIstHandlungsbedarfMitNotiz(): void {
		$this->givenBestand([
			$this->mandate(3, 7, Mandate::STATUS_SUSPENDED, ['suspensionOrigin' => Mandate::SUSPENSION_MANUAL, 'suspensionNote' => 'Konto wird gewechselt']),
		], [$this->member(7)]);

		$task = $this->only($this->service()->findTasks(self::TODAY));

		$this->assertSame(Task::SEVERITY_ACTION_REQUIRED, $task['severity']);
		$this->assertStringContainsString('Mandat gesperrt, Klärung offen', $task['message']);
		$this->assertStringContainsString('Konto wird gewechselt', $task['message']);
		$this->assertSame(7, $task['memberId']);
	}

	public function testLangeSperrNotizWirdGekuerzt(): void {
		$this->givenBestand([
			$this->mandate(3, 7, Mandate::STATUS_SUSPENDED, ['suspensionOrigin' => Mandate::SUSPENSION_MANUAL, 'suspensionNote' => str_repeat('Lang ', 100)]),
		], [$this->member(7)]);

		$task = $this->only($this->service()->findTasks(self::TODAY));

		$this->assertLessThan(260, mb_strlen($task['message']));
		$this->assertStringEndsWith('…', $task['message']);
	}

	/** @return array<string, array{0:string,1:string}> ISO-Code => erwarteter Klartext */
	public static function returnReasonProvider(): array {
		return [
			'AM04 Deckung fehlt' => ['AM04', 'Deckung fehlt'],
			'AC04 Konto erloschen' => ['AC04', 'Konto nicht nutzbar'],
			'MD06 Widerspruch' => ['MD06', 'Widerspruch oder kein gültiges Mandat'],
			'MD07 verstorben' => ['MD07', 'Zahler verstorben'],
			'AM05 technisch' => ['AM05', 'technischer Fehler bei der Bank'],
			'unbekannter Code' => ['XX99', 'Grund unbekannt'],
		];
	}

	/** @dataProvider returnReasonProvider */
	public function testSperreAusRuecklastschriftNenntDenGrundInKlartext(string $reasonCode, string $expectedText): void {
		$returned = new ReturnedDebit();
		$returned->setId(40);
		$returned->setReasonCode($reasonCode);
		$this->returnedDebits->method('findAllByDebitItem')->willReturn([5 => $returned]);
		$this->givenBestand([
			$this->mandate(3, 7, Mandate::STATUS_SUSPENDED, ['suspensionOrigin' => Mandate::SUSPENSION_RETURNED_DEBIT, 'returnedDebitId' => 40]),
		], [$this->member(7)]);

		$task = $this->only($this->service()->findTasks(self::TODAY));

		$this->assertSame(Task::SEVERITY_ACTION_REQUIRED, $task['severity']);
		$this->assertStringContainsString('nach einer Rücklastschrift gesperrt (' . $expectedText . ')', $task['message']);
		// Den rohen Bankcode bekommt die Aufgabenliste nicht zu sehen.
		$this->assertStringNotContainsString($reasonCode, $task['message']);
	}

	public function testSperreAusRuecklastschriftOhneAuffindbareRuecklastschriftBleibtLesbar(): void {
		$this->givenBestand([
			$this->mandate(3, 7, Mandate::STATUS_SUSPENDED, ['suspensionOrigin' => Mandate::SUSPENSION_RETURNED_DEBIT]),
		], [$this->member(7)]);

		$task = $this->only($this->service()->findTasks(self::TODAY));

		$this->assertStringContainsString('nach einer Rücklastschrift gesperrt, Klärung offen', $task['message']);
	}

	public function testSperreVerschwindetMitDemEntsperren(): void {
		$this->documents->method('showMissingDocumentWarning')->willReturn(false);
		$this->mandates->method('findAll')->willReturnOnConsecutiveCalls(
			[$this->mandate(3, 7, Mandate::STATUS_SUSPENDED, ['suspensionOrigin' => Mandate::SUSPENSION_MANUAL, 'suspensionNote' => 'x'])],
			[$this->mandate(3, 7, Mandate::STATUS_ACTIVE, ['signedAt' => '2026-09-01'])],
		);
		$this->members->method('findAllById')->willReturn(self::byId([$this->member(7)]));
		$service = $this->service();

		$this->assertCount(1, $service->findTasks(self::TODAY));
		$this->assertSame([], $service->findTasks(self::TODAY));
	}

	// --- Mandat erloschen, Lastschrift weiter gewollt -----------------------------------

	public function testErloschenesMandatBeiWeiterGewolltemLastschriftIstHandlungsbedarf(): void {
		$this->givenBestand([
			$this->mandate(3, 7, Mandate::STATUS_ENDED, ['endReason' => Mandate::END_REASON_REVOKED]),
		], [$this->member(7)]);
		$this->assignments->method('findActiveAsOf')->with(self::TODAY)->willReturn([$this->directDebitAssignment(7)]);

		$task = $this->only($this->service()->findTasks(self::TODAY));

		$this->assertSame(Task::SEVERITY_ACTION_REQUIRED, $task['severity']);
		$this->assertStringContainsString('erloschen (widerrufen)', $task['message']);
		$this->assertStringContainsString('Neues Mandat einholen', $task['message']);
		$this->assertSame(3, $task['objectId']);
		$this->assertSame(7, $task['memberId']);
	}

	public function testErloschenNenntDenGrundDesJuengstenMandats(): void {
		$this->givenBestand([
			$this->mandate(3, 7, Mandate::STATUS_ENDED, ['endReason' => Mandate::END_REASON_REPLACED]),
			$this->mandate(8, 7, Mandate::STATUS_ENDED, ['endReason' => Mandate::END_REASON_EXPIRED]),
		], [$this->member(7)]);
		$this->assignments->method('findActiveAsOf')->willReturn([$this->directDebitAssignment(7)]);

		$task = $this->only($this->service()->findTasks(self::TODAY));

		$this->assertSame(8, $task['objectId']);
		$this->assertStringContainsString('nach 36 Monaten verfallen', $task['message']);
	}

	public function testVerworfenerEntwurfWirdAlsSolcherBenannt(): void {
		// Ein verworfener Entwurf (#118) ist kein „beendetes“ Mandat: der Text nennt den echten Grund.
		$this->givenBestand([
			$this->mandate(3, 7, Mandate::STATUS_ENDED, ['endReason' => Mandate::END_REASON_DISCARDED]),
		], [$this->member(7)]);
		$this->assignments->method('findActiveAsOf')->willReturn([$this->directDebitAssignment(7)]);

		$task = $this->only($this->service()->findTasks(self::TODAY));

		$this->assertStringContainsString('erloschen (Entwurf verworfen)', $task['message']);
	}

	public function testErloschenesMandatOhneLastschriftWunschIstKeineAufgabe(): void {
		$this->givenBestand([$this->mandate(3, 7, Mandate::STATUS_ENDED, ['endReason' => Mandate::END_REASON_REVOKED])], [$this->member(7)]);
		// Die Zuweisung läuft über Überweisung: nichts mehr zu tun.
		$this->assignments->method('findActiveAsOf')->willReturn([$this->directDebitAssignment(7, Assignment::PAYMENT_METHOD_TRANSFER)]);

		$this->assertSame([], $this->service()->findTasks(self::TODAY));
	}

	public function testErloschenesMandatOhneLaufendeZuweisungIstKeineAufgabe(): void {
		// Austritt/Zuweisung beendet: findActiveAsOf liefert sie nicht mehr.
		$this->givenBestand([$this->mandate(3, 7, Mandate::STATUS_ENDED, ['endReason' => Mandate::END_REASON_REVOKED])], [$this->member(7)]);
		$this->assignments->method('findActiveAsOf')->willReturn([]);

		$this->assertSame([], $this->service()->findTasks(self::TODAY));
	}

	public function testNeuesMandatBehebtDenErloschenFall(): void {
		// Das Mitglied hat inzwischen ein neues, aktives Mandat: kein Fall „nur erloschene“.
		$this->documents->method('showMissingDocumentWarning')->willReturn(false);
		$this->givenBestand([
			$this->mandate(3, 7, Mandate::STATUS_ENDED, ['endReason' => Mandate::END_REASON_REVOKED]),
			$this->mandate(4, 7, Mandate::STATUS_ACTIVE, ['signedAt' => '2026-09-01']),
		], [$this->member(7)]);
		$this->assignments->expects($this->never())->method('findActiveAsOf');

		$this->assertSame([], $this->service()->findTasks(self::TODAY));
	}

	// --- Mandat ohne Nachweis -----------------------------------------------------------

	public function testAktivesPapierMandatOhneNachweisIstHinweis(): void {
		$this->documents->method('showMissingDocumentWarning')->willReturn(true);
		$this->givenBestand([$this->mandate(3, 7, Mandate::STATUS_ACTIVE, ['signedAt' => '2026-09-01'])], [$this->member(7)]);

		$task = $this->only($this->service()->findTasks(self::TODAY));

		$this->assertSame(Task::SEVERITY_HINT, $task['severity']);
		$this->assertStringContainsString('kein Nachweis hinterlegt', $task['message']);
		$this->assertSame(7, $task['memberId']);
	}

	public function testOhneNachweisVerschwindetMitDemUpload(): void {
		$this->documents->method('showMissingDocumentWarning')->willReturn(true);
		$this->mandates->method('findAll')->willReturnOnConsecutiveCalls(
			[$this->mandate(3, 7, Mandate::STATUS_ACTIVE, ['signedAt' => '2026-09-01'])],
			[$this->mandate(3, 7, Mandate::STATUS_ACTIVE, ['signedAt' => '2026-09-01', 'documentFileId' => 55])],
		);
		$this->members->method('findAllById')->willReturn(self::byId([$this->member(7)]));
		$service = $this->service();

		$this->assertCount(1, $service->findTasks(self::TODAY));
		$this->assertSame([], $service->findTasks(self::TODAY));
	}

	public function testOhneNachweisEntfaelltBeiAbgeschalteterEinstellung(): void {
		$this->documents->method('showMissingDocumentWarning')->willReturn(false);
		$this->givenBestand([$this->mandate(3, 7, Mandate::STATUS_ACTIVE, ['signedAt' => '2026-09-01'])], [$this->member(7)]);

		$this->assertSame([], $this->service()->findTasks(self::TODAY));
	}

	public function testElektronischesMandatBrauchtKeinenPapierNachweis(): void {
		// Das Beweispaket der Zustimmung ist hier der Nachweis (Spec §3.2: Dauer-Mangel nur im Papier-Weg).
		$this->documents->method('showMissingDocumentWarning')->willReturn(true);
		$this->givenBestand([
			$this->mandate(3, 7, Mandate::STATUS_ACTIVE, ['signatureType' => Mandate::SIGNATURE_ELECTRONIC, 'signedAt' => '2026-09-01']),
		], [$this->member(7)]);

		$this->assertSame([], $this->service()->findTasks(self::TODAY));
	}

	public function testEntwurfUndSperreBekommenNebenIhremHandlungsbedarfKeinenZweitenNachweisHinweis(): void {
		$this->documents->method('showMissingDocumentWarning')->willReturn(true);
		$this->givenBestand([
			$this->mandate(3, 7, Mandate::STATUS_DRAFT),
			$this->mandate(4, 8, Mandate::STATUS_SUSPENDED, ['suspensionOrigin' => Mandate::SUSPENSION_MANUAL, 'suspensionNote' => 'x']),
		], [$this->member(7), $this->member(8)]);

		$tasks = $this->service()->findTasks(self::TODAY);

		$this->assertCount(2, $tasks);
		$this->assertSame([Task::SEVERITY_ACTION_REQUIRED, Task::SEVERITY_ACTION_REQUIRED], array_column($tasks, 'severity'));
	}

	// --- Mandat verfällt in N Tagen -----------------------------------------------------

	public function testVerfallendesMandatIstHinweisMitDatumUndRestlaufzeit(): void {
		// Unterschrieben am 01.12.2023 → 36 Monate → verfällt am 01.12.2026, 58 Tage nach dem Stichtag.
		$expiring = $this->mandate(3, 7, Mandate::STATUS_ACTIVE, ['signedAt' => '2023-12-01', 'documentFileId' => 55]);
		$this->givenBestand([$expiring], [$this->member(7)]);
		$this->mandateService->method('findDueForExpiryWarning')->with(self::TODAY)->willReturn([$expiring]);

		$task = $this->only($this->service()->findTasks(self::TODAY));

		$this->assertSame(Task::SEVERITY_HINT, $task['severity']);
		$this->assertStringContainsString('verfällt am 01.12.2026 (in 58 Tagen)', $task['message']);
		$this->assertSame(3, $task['objectId']);
		$this->assertSame(7, $task['memberId']);
	}

	public function testVerfallMorgenSagtEinenTag(): void {
		$expiring = $this->mandate(3, 7, Mandate::STATUS_ACTIVE, ['signedAt' => '2023-10-05', 'documentFileId' => 55]);
		$this->givenBestand([$expiring], [$this->member(7)]);
		$this->mandateService->method('findDueForExpiryWarning')->willReturn([$expiring]);

		$task = $this->only($this->service()->findTasks(self::TODAY));

		$this->assertStringContainsString('verfällt am 05.10.2026 (in 1 Tag)', $task['message']);
	}

	public function testMandatAusserhalbDesWarnfenstersErzeugtNichts(): void {
		// findDueForExpiryWarning() (Einstellung expiry_warning_days) ist die einzige Quelle.
		$this->givenBestand([$this->mandate(3, 7, Mandate::STATUS_ACTIVE, ['signedAt' => '2026-01-01', 'documentFileId' => 55])], [$this->member(7)]);
		$this->mandateService->method('findDueForExpiryWarning')->willReturn([]);

		$this->assertSame([], $this->service()->findTasks(self::TODAY));
	}

	public function testVerfallHinweisVerschwindetNachEinemEinzug(): void {
		// Ein eingereichter Einzug setzt die Frist neu: findDueForExpiryWarning() liefert das Mandat nicht mehr.
		$expiring = $this->mandate(3, 7, Mandate::STATUS_ACTIVE, ['signedAt' => '2023-12-01', 'documentFileId' => 55]);
		$this->givenBestand([$expiring], [$this->member(7)]);
		$this->mandateService->method('findDueForExpiryWarning')->willReturnOnConsecutiveCalls([$expiring], []);
		$service = $this->service();

		$this->assertCount(1, $service->findTasks(self::TODAY));
		$this->assertSame([], $service->findTasks(self::TODAY));
	}

	// --- Ausgetreten mit offenen Forderungen, Mandat noch aktiv ---------------------------

	private function departedMember(int $id = 7, string $leftAt = '2026-09-01'): Member {
		$member = $this->member($id);
		$member->setLeftAt($leftAt);
		return $member;
	}

	public function testAusgetretenMitOffenenForderungenUndAktivemMandatIstHinweis(): void {
		$this->documents->method('showMissingDocumentWarning')->willReturn(false);
		$this->givenBestand([$this->mandate(3, 7, Mandate::STATUS_ACTIVE, ['signedAt' => '2026-01-01'])], [$this->departedMember()]);
		$this->openItems->method('findClaims')->willReturn([
			$this->claim(1, 7, 1500),
			$this->claim(2, 7, 2500),
			$this->claim(3, 7, 9900, 'paid'), // erledigt: zählt nicht
			$this->claim(4, 8, 7700),          // anderes Mitglied
		]);

		$task = $this->only($this->service()->findTasks(self::TODAY));

		$this->assertSame(Task::SEVERITY_HINT, $task['severity']);
		$this->assertStringContainsString('Ausgetreten, aber noch 2 offene Forderungen (zusammen 40,00 €)', $task['message']);
		$this->assertStringContainsString('Mandat bleibt aktiv', $task['message']);
		$this->assertSame(7, $task['memberId']);
		$this->assertSame(3, $task['objectId']);
	}

	public function testAusgetretenMitEinerForderungSagtEinzahl(): void {
		$this->documents->method('showMissingDocumentWarning')->willReturn(false);
		$this->givenBestand([$this->mandate(3, 7, Mandate::STATUS_ACTIVE, ['signedAt' => '2026-01-01'])], [$this->departedMember()]);
		$this->openItems->method('findClaims')->willReturn([$this->claim(1, 7, 1500)]);

		$task = $this->only($this->service()->findTasks(self::TODAY));

		$this->assertStringContainsString('noch 1 offene Forderung (15,00 €)', $task['message']);
	}

	public function testAusgetretenOhneOffeneForderungenIstKeineAufgabe(): void {
		// Verschwindet von selbst: alles beglichen (und der Cron beendet das Mandat).
		$this->documents->method('showMissingDocumentWarning')->willReturn(false);
		$this->givenBestand([$this->mandate(3, 7, Mandate::STATUS_ACTIVE, ['signedAt' => '2026-01-01'])], [$this->departedMember()]);
		$this->openItems->method('findClaims')->willReturn([$this->claim(1, 7, 1500, 'paid'), $this->claim(2, 7, 1500, 'cancelled')]);

		$this->assertSame([], $this->service()->findTasks(self::TODAY));
	}

	public function testNochNichtAusgetretenesMitgliedIstKeineAufgabe(): void {
		// Austritt in der Zukunft: das Mitglied ist noch aktiv, die Forderungen werden normal eingezogen.
		$this->documents->method('showMissingDocumentWarning')->willReturn(false);
		$this->givenBestand([$this->mandate(3, 7, Mandate::STATUS_ACTIVE, ['signedAt' => '2026-01-01'])], [$this->departedMember(7, '2026-12-31')]);
		$this->openItems->expects($this->never())->method('findClaims');

		$this->assertSame([], $this->service()->findTasks(self::TODAY));
	}

	public function testAusgetretenMitBeendetemMandatIstKeineAufgabe(): void {
		$this->givenBestand([$this->mandate(3, 7, Mandate::STATUS_ENDED, ['endReason' => Mandate::END_REASON_TERMINATED])], [$this->departedMember()]);
		$this->assignments->method('findActiveAsOf')->willReturn([]);
		$this->openItems->expects($this->never())->method('findClaims');

		$this->assertSame([], $this->service()->findTasks(self::TODAY));
	}
}
