<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\MembershipFee;
use OCA\Vereinsbuchhaltung\Db\MembershipFeeMapper;
use OCA\Vereinsbuchhaltung\Db\SepaMandate;
use OCA\Vereinsbuchhaltung\Db\SepaMandateMapper;
use OCA\Vereinsbuchhaltung\Service\MemberService;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Stammdaten-CRUD und Störfall-Regeln der Mitglied-Entity (Spec §2.2/§3.1).
 * DB-Zugriffe sind gemockt (MemberMapper/SepaMandateMapper/MembershipFeeMapper) –
 * echte Datenbankfragen bleiben wie im übrigen Bestand den E2E-Tests
 * vorbehalten (siehe tests/e2e/11-contributions.spec.mjs).
 */
class MemberServiceTest extends TestCase {

	private MemberMapper&MockObject $mapper;
	private SepaMandateMapper&MockObject $mandateMapper;
	private MembershipFeeMapper&MockObject $feeMapper;
	private IUserManager&MockObject $userManager;

	protected function setUp(): void {
		$this->mapper = $this->createMock(MemberMapper::class);
		$this->mandateMapper = $this->createMock(SepaMandateMapper::class);
		$this->feeMapper = $this->createMock(MembershipFeeMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
	}

	private function service(): MemberService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf(str_replace('%s', '%1$s', $text), $parameters),
		);
		return new MemberService($this->mapper, $this->mandateMapper, $this->feeMapper, $this->userManager, $l10n);
	}

	// --- splitLabel(): reine Split-Heuristik (Spec §3.1 "Umbaupfad") ---

	public function testSplitLabelMitLeerzeichenIstPerson(): void {
		$split = MemberService::splitLabel('Katrin Brunner');

		$this->assertSame(Member::TYPE_PERSON, $split['type']);
		$this->assertSame('Katrin', $split['firstName']);
		$this->assertSame('Brunner', $split['lastName']);
		$this->assertNull($split['organizationName']);
	}

	public function testSplitLabelSplittetAmErstenLeerzeichen(): void {
		// "Anna Maria Muster" -> Vorname "Anna", Nachname "Maria Muster":
		// Split am *ersten* Leerzeichen, nicht am letzten (Spec §3.1).
		$split = MemberService::splitLabel('Anna Maria Muster');

		$this->assertSame('Anna', $split['firstName']);
		$this->assertSame('Maria Muster', $split['lastName']);
	}

	public function testSplitLabelOhneLeerzeichenIstOrganisation(): void {
		$split = MemberService::splitLabel('Musikverein');

		$this->assertSame(Member::TYPE_ORGANIZATION, $split['type']);
		$this->assertSame('Musikverein', $split['organizationName']);
		$this->assertNull($split['firstName']);
		$this->assertNull($split['lastName']);
	}

	// --- create(): Validierung ---

	public function testCreatePersonOhneNachnameSchlaegtFehl(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->create(['memberType' => Member::TYPE_PERSON, 'firstName' => 'Katrin']);
	}

	public function testCreateOrganisationOhneNamenSchlaegtFehl(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->create(['memberType' => Member::TYPE_ORGANIZATION]);
	}

	public function testCreateUngueltigerTypSchlaegtFehl(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->create(['memberType' => 'verein']);
	}

	public function testCreateUngueltigeEmailSchlaegtFehl(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->create(['memberType' => Member::TYPE_PERSON, 'lastName' => 'Brunner', 'email' => 'keine-email']);
	}

	public function testCreateDoppelteMitgliedsnummerSchlaegtFehl(): void {
		$other = new Member();
		$other->setId(9);
		$this->mapper->method('findByMemberNumber')->with('M-1')->willReturn($other);

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->create(['memberType' => Member::TYPE_PERSON, 'lastName' => 'Brunner', 'memberNumber' => 'M-1']);
	}

	// --- create(): Erfolgsfall ---

	public function testCreatePersonSetztFelderUndDefaultJoinedAt(): void {
		$this->mapper->method('insert')->willReturnArgument(0);

		$member = $this->service()->create([
			'memberType' => Member::TYPE_PERSON,
			'firstName' => 'Katrin',
			'lastName' => ' Brunner ',
			'email' => 'k.brunner@example.org',
		]);

		$this->assertSame(Member::TYPE_PERSON, $member->getMemberType());
		$this->assertSame('Katrin', $member->getFirstName());
		$this->assertSame('Brunner', $member->getLastName());
		$this->assertSame('k.brunner@example.org', $member->getEmail());
		$this->assertSame((new \DateTime())->format('Y-m-d'), $member->getJoinedAt());
		$this->assertNotNull($member->getCreatedAt());
	}

	public function testCreateOrganisationSetztOrganisationsnamen(): void {
		$this->mapper->method('insert')->willReturnArgument(0);

		$member = $this->service()->create([
			'memberType' => Member::TYPE_ORGANIZATION,
			'organizationName' => 'Musikverein Talheim e.V.',
		]);

		$this->assertSame(Member::TYPE_ORGANIZATION, $member->getMemberType());
		$this->assertSame('Musikverein Talheim e.V.', $member->getOrganizationName());
		$this->assertNull($member->getFirstName());
		$this->assertNull($member->getLastName());
	}

	public function testCreateOhneAngabeIstPersonPerDefault(): void {
		$this->mapper->method('insert')->willReturnArgument(0);

		$member = $this->service()->create(['lastName' => 'Brunner']);

		$this->assertSame(Member::TYPE_PERSON, $member->getMemberType());
	}

	public function testUpdateAendertNichtDieId(): void {
		$existing = new Member();
		$existing->setId(5);
		$existing->setMemberType(Member::TYPE_PERSON);
		$existing->setLastName('Alt');
		$this->mapper->method('find')->with(5)->willReturn($existing);
		$this->mapper->method('update')->willReturnArgument(0);

		$member = $this->service()->update(5, ['memberType' => Member::TYPE_PERSON, 'lastName' => 'Neu']);

		$this->assertSame(5, $member->getId());
		$this->assertSame('Neu', $member->getLastName());
	}

	// --- blockingReasons()/delete(): Löschsperre (Spec §3.1) ---

	private function mandate(int $memberId, string $status = 'active'): SepaMandate {
		$mandate = new SepaMandate();
		$mandate->setMemberId($memberId);
		$mandate->setStatus($status);
		return $mandate;
	}

	private function fee(int $memberId): MembershipFee {
		$fee = new MembershipFee();
		$fee->setMemberId($memberId);
		return $fee;
	}

	public function testBlockingReasonsLeerWennNichtsVerweist(): void {
		$this->mandateMapper->method('findAll')->willReturn([]);
		$this->feeMapper->method('findAll')->willReturn([]);

		$this->assertSame([], $this->service()->blockingReasons(1));
	}

	public function testBlockingReasonsAktivesMandatBlockiert(): void {
		$this->mandateMapper->method('findAll')->willReturn([$this->mandate(1, 'active')]);
		$this->feeMapper->method('findAll')->willReturn([]);

		$reasons = $this->service()->blockingReasons(1);

		$this->assertNotEmpty($reasons);
		$this->assertStringContainsString('aktives SEPA-Mandat', $reasons[0]);
	}

	public function testBlockingReasonsWiderrufenesMandatBlockiertAuch(): void {
		$this->mandateMapper->method('findAll')->willReturn([$this->mandate(1, 'revoked')]);
		$this->feeMapper->method('findAll')->willReturn([]);

		$reasons = $this->service()->blockingReasons(1);

		$this->assertNotEmpty($reasons);
	}

	public function testBlockingReasonsBeitragBlockiert(): void {
		$this->mandateMapper->method('findAll')->willReturn([]);
		$this->feeMapper->method('findAll')->willReturn([$this->fee(1)]);

		$reasons = $this->service()->blockingReasons(1);

		$this->assertNotEmpty($reasons);
	}

	public function testBlockingReasonsForIdsBerechnetMehrereMitgliederInZweiAbfragen(): void {
		// Batch-Fall (MemberController::index()): genau eine findAll()-Abfrage
		// je Mapper bedient beliebig viele Mitglieder, nicht eine je Mitglied.
		$this->mandateMapper->expects($this->once())->method('findAll')->willReturn([$this->mandate(1, 'active')]);
		$this->feeMapper->expects($this->once())->method('findAll')->willReturn([$this->fee(2)]);

		$reasons = $this->service()->blockingReasonsForIds([1, 2, 3]);

		$this->assertNotEmpty($reasons[1]);
		$this->assertNotEmpty($reasons[2]);
		$this->assertSame([], $reasons[3]);
	}

	public function testDeleteWirftBeiBlockierendemGrund(): void {
		$member = new Member();
		$member->setId(3);
		$this->mapper->method('find')->with(3)->willReturn($member);
		$this->mandateMapper->method('findAll')->willReturn([$this->mandate(3, 'active')]);
		$this->feeMapper->method('findAll')->willReturn([]);
		$this->mapper->expects($this->never())->method('delete');

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->delete(3);
	}

	public function testDeleteLoeschtWennNichtsBlockiert(): void {
		$member = new Member();
		$member->setId(3);
		$this->mapper->method('find')->with(3)->willReturn($member);
		$this->mandateMapper->method('findAll')->willReturn([]);
		$this->feeMapper->method('findAll')->willReturn([]);
		$this->mapper->expects($this->once())->method('delete')->with($member);

		$this->service()->delete(3);
	}

	// --- Austritt (Spec §2.2/§3.1) ---

	public function testLeaveSetztLeftAtAuchInDerZukunft(): void {
		$member = new Member();
		$member->setId(4);
		$this->mapper->method('find')->with(4)->willReturn($member);
		$this->mapper->method('update')->willReturnArgument(0);

		$result = $this->service()->leave(4, '2030-01-01');

		$this->assertSame('2030-01-01', $result->getLeftAt());
	}

	public function testLeaveMitUngueltigemDatumSchlaegtFehl(): void {
		$member = new Member();
		$this->mapper->method('find')->willReturn($member);

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->leave(4, 'nicht-datum');
	}

	public function testReactivateLeertLeftAt(): void {
		$member = new Member();
		$member->setLeftAt('2026-01-01');
		$this->mapper->method('find')->willReturn($member);
		$this->mapper->method('update')->willReturnArgument(0);

		$result = $this->service()->reactivate(4);

		$this->assertNull($result->getLeftAt());
	}

	// --- NC-Kontoverknüpfung (Spec §3.1: Vorschlag, nie Vollzug) ---

	public function testFindLinkSuggestionsOhneEmailIstLeer(): void {
		$member = new Member();
		$this->mapper->method('find')->willReturn($member);

		$this->assertSame([], $this->service()->findLinkSuggestions(1));
	}

	public function testFindLinkSuggestionsZeigtAlleTrefferOhneAuswahl(): void {
		$member = new Member();
		$member->setEmail('familie@example.org');
		$this->mapper->method('find')->willReturn($member);
		$this->userManager->method('getByEmail')->with('familie@example.org')->willReturn([
			$this->user('vater', 'Vater Muster', 'familie@example.org'),
			$this->user('mutter', 'Mutter Muster', 'familie@example.org'),
		]);

		$suggestions = $this->service()->findLinkSuggestions(1);

		$this->assertCount(2, $suggestions);
		$this->assertSame(['vater', 'mutter'], array_column($suggestions, 'uid'));
	}

	public function testLinkOhneExistierendesKontoSchlaegtFehl(): void {
		$this->mapper->method('find')->willReturn(new Member());
		$this->userManager->method('userExists')->willReturn(false);

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->link(1, 'unbekannt');
	}

	public function testLinkAnderesMitgliedSchonVerknuepftSchlaegtFehl(): void {
		$member = new Member();
		$member->setId(1);
		$this->mapper->method('find')->willReturn($member);
		$this->userManager->method('userExists')->willReturn(true);
		$other = new Member();
		$other->setId(2);
		$this->mapper->method('findByNcUserId')->willReturn($other);

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->link(1, 'katrin');
	}

	public function testLinkSetztNcUserIdNachBestaetigung(): void {
		$member = new Member();
		$member->setId(1);
		$this->mapper->method('find')->willReturn($member);
		$this->userManager->method('userExists')->willReturn(true);
		$this->mapper->method('findByNcUserId')->willReturn(null);
		$this->mapper->method('update')->willReturnArgument(0);

		$result = $this->service()->link(1, 'katrin');

		$this->assertSame('katrin', $result->getNcUserId());
	}

	public function testUnlinkLeertNcUserId(): void {
		$member = new Member();
		$member->setNcUserId('katrin');
		$this->mapper->method('find')->willReturn($member);
		$this->mapper->method('update')->willReturnArgument(0);

		$result = $this->service()->unlink(1);

		$this->assertNull($result->getNcUserId());
	}

	// --- findOrCreateByNcUserId()/createFromLabel(): Migration & CSV-Import ---

	public function testFindOrCreateByNcUserIdFindetVorhandenes(): void {
		$existing = new Member();
		$existing->setId(7);
		$this->mapper->method('findByNcUserId')->with('katrin')->willReturn($existing);
		$this->mapper->expects($this->never())->method('insert');

		$this->assertSame($existing, $this->service()->findOrCreateByNcUserId('katrin'));
	}

	public function testFindOrCreateByNcUserIdLegtNeuAnAusDisplayname(): void {
		$this->mapper->method('findByNcUserId')->willReturn(null);
		$this->userManager->method('get')->with('katrin')->willReturn($this->user('katrin', 'Katrin Brunner', 'k@example.org'));
		$this->mapper->method('insert')->willReturnArgument(0);

		$member = $this->service()->findOrCreateByNcUserId('katrin');

		$this->assertSame(Member::TYPE_PERSON, $member->getMemberType());
		$this->assertSame('Katrin', $member->getFirstName());
		$this->assertSame('Brunner', $member->getLastName());
		$this->assertSame('k@example.org', $member->getEmail());
		$this->assertSame('katrin', $member->getNcUserId());
	}

	public function testCreateFromLabelLegtImmerNeuAn(): void {
		$this->mapper->method('insert')->willReturnArgument(0);
		$this->mapper->expects($this->never())->method('findByNcUserId');

		$member = $this->service()->createFromLabel('Musikverein');

		$this->assertSame(Member::TYPE_ORGANIZATION, $member->getMemberType());
		$this->assertSame('Musikverein', $member->getOrganizationName());
	}

	private function user(string $uid, string $displayName, ?string $email): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($displayName);
		$user->method('getEMailAddress')->willReturn($email);
		return $user;
	}
}
