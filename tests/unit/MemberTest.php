<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Member;
use PHPUnit\Framework\TestCase;

/**
 * Die reine Entity-Logik der Mitglied-Entity (Spec §2.2): Anzeigename je
 * Mitgliedstyp und die Ableitung "der Zeitraum ist der Status" für left_at.
 */
class MemberTest extends TestCase {

	public function testAnzeigenamePerson(): void {
		$member = new Member();
		$member->setMemberType(Member::TYPE_PERSON);
		$member->setFirstName('Katrin');
		$member->setLastName('Brunner');

		$this->assertSame('Katrin Brunner', $member->displayName());
	}

	public function testAnzeigenamePersonOhneVorname(): void {
		$member = new Member();
		$member->setMemberType(Member::TYPE_PERSON);
		$member->setLastName('Brunner');

		$this->assertSame('Brunner', $member->displayName());
	}

	public function testAnzeigenameOrganisation(): void {
		$member = new Member();
		$member->setMemberType(Member::TYPE_ORGANIZATION);
		$member->setOrganizationName('Musikverein Talheim e.V.');

		$this->assertSame('Musikverein Talheim e.V.', $member->displayName());
	}

	public function testOhneAustrittsdatumAktiv(): void {
		$member = new Member();
		$member->setLeftAt(null);

		$this->assertTrue($member->isActive('2026-09-16'));
	}

	public function testAustrittsdatumInDerVergangenheitIstAusgetreten(): void {
		$member = new Member();
		$member->setLeftAt('2026-01-01');

		$this->assertFalse($member->isActive('2026-09-16'));
	}

	public function testAustrittsdatumInDerZukunftIstNochAktiv(): void {
		$member = new Member();
		$member->setLeftAt('2027-01-01');

		$this->assertTrue($member->isActive('2026-09-16'));
	}

	public function testAustrittsdatumHeuteIstNochAktiv(): void {
		// left_at ist inklusiv - erst der Tag danach ist ausgetreten (Spec
		// §2.2: "left_at in der Vergangenheit ist der Status").
		$member = new Member();
		$member->setLeftAt('2026-09-16');

		$this->assertTrue($member->isActive('2026-09-16'));
	}

	public function testJsonEnthaeltAbgeleitetenAnzeigenamenUndAktivstatus(): void {
		$member = new Member();
		$member->setMemberType(Member::TYPE_PERSON);
		$member->setFirstName('Hans');
		$member->setLastName('Mertens');
		$member->setLeftAt('2020-01-01');

		$data = $member->jsonSerialize();

		$this->assertSame('Hans Mertens', $data['displayName']);
		$this->assertFalse($data['active']);
	}
}
