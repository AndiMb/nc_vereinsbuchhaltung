<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Debtor;
use OCA\Vereinsbuchhaltung\Db\DebtorMapper;
use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Db\InvoiceMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Service\DebtorService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Rechnungsempfänger: Validierung der Stammdaten, Übernahme aus der
 * Mitgliederakte und Löschschutz bei Entwürfen.
 */
class DebtorServiceTest extends TestCase {

	private DebtorMapper&MockObject $mapper;
	private MemberMapper&MockObject $members;
	private InvoiceMapper&MockObject $invoices;

	protected function setUp(): void {
		$this->mapper = $this->createMock(DebtorMapper::class);
		$this->mapper->method('insert')->willReturnArgument(0);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->members = $this->createMock(MemberMapper::class);
		$this->invoices = $this->createMock(InvoiceMapper::class);
	}

	private function service(): DebtorService {
		return new DebtorService($this->mapper, $this->members, $this->invoices);
	}

	public function testAnlegenNormalisiertEingaben(): void {
		$debtor = $this->service()->create([
			'kind' => 'organisation',
			'name' => '  Stadt Beispiel  ',
			'country' => 'de',
			'vatId' => 'de 123456789',
			'leitwegId' => '991-01234-44',
			'email' => '',
			'street' => '',
		]);

		$this->assertSame('Stadt Beispiel', $debtor->getName());
		$this->assertSame('DE', $debtor->getCountry());
		$this->assertSame('DE123456789', $debtor->getVatId());
		$this->assertSame('991-01234-44', $debtor->getLeitwegId());
		$this->assertNull($debtor->getEmail());
		$this->assertNull($debtor->getStreet());
	}

	/** @dataProvider ungueltigeEingaben */
	public function testUngueltigeEingabenWerdenAbgelehnt(array $data): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->create($data + ['name' => 'Muster']);
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function ungueltigeEingaben(): array {
		return [
			'Art unbekannt' => [['kind' => 'firma']],
			'Name leer' => [['name' => ' ']],
			'Land dreistellig' => [['country' => 'DEU']],
			'E-Mail kaputt' => [['email' => 'kaputt']],
			'USt-IdNr. kaputt' => [['vatId' => '1']],
			'Leitweg-ID kaputt' => [['leitwegId' => 'a b']],
		];
	}

	public function testNameIstBeimAnlegenPflicht(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->create(['kind' => 'person']);
	}

	public function testUpdateAendertNurGesendeteFelder(): void {
		$existing = new Debtor();
		$existing->setName('Alt');
		$existing->setCity('Berlin');
		$this->mapper->method('find')->willReturn($existing);

		$updated = $this->service()->update(3, ['city' => 'Hamburg']);

		$this->assertSame('Alt', $updated->getName());
		$this->assertSame('Hamburg', $updated->getCity());
	}

	public function testMitgliedWirdMitAdresseUebernommen(): void {
		$member = new Member();
		$member->setMemberType(Member::TYPE_PERSON);
		$member->setFirstName('Erika');
		$member->setLastName('Muster');
		$member->setStreet('Weg 1');
		$member->setPostalCode('12345');
		$member->setCity('Ort');
		$member->setMemberNumber('M-7');
		$this->mapper->method('findByMember')->willReturn(null);
		$this->members->method('find')->willReturn($member);
		$this->members->method('findOrNull')->willReturn($member);

		$debtor = $this->service()->forMember(7);

		$this->assertSame('Erika Muster', $debtor->getName());
		$this->assertSame('Weg 1', $debtor->getStreet());
		$this->assertSame('M-7', $debtor->getCustomerNo());
		$this->assertSame(7, $debtor->getMemberId());
		$this->assertSame(Debtor::KIND_PERSON, $debtor->getKind());
	}

	public function testVorhandenerEmpfaengerZumMitgliedBleibtUnveraendert(): void {
		$existing = new Debtor();
		$existing->setName('Handkorrigiert');
		$this->mapper->method('findByMember')->willReturn($existing);
		$this->members->expects($this->never())->method('find');

		$this->assertSame($existing, $this->service()->forMember(7));
	}

	public function testAnonymisiertesMitgliedKannKeinEmpfaengerWerden(): void {
		$member = new Member();
		$member->setRedactedAt('2026-01-01T00:00:00+00:00');
		$this->mapper->method('findByMember')->willReturn(null);
		$this->members->method('find')->willReturn($member);

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->forMember(7);
	}

	public function testLoeschenIstMitEntwurfGesperrt(): void {
		$this->mapper->method('find')->willReturn(new Debtor());
		$this->invoices->method('findFiltered')->with(Invoice::STATUS_DRAFT, null, 5)->willReturn([new Invoice()]);
		$this->mapper->expects($this->never())->method('delete');

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->delete(5);
	}

	public function testLoeschenOhneEntwuerfe(): void {
		$debtor = new Debtor();
		$this->mapper->method('find')->willReturn($debtor);
		$this->invoices->method('findFiltered')->willReturn([]);
		$this->mapper->expects($this->once())->method('delete')->with($debtor);

		$this->service()->delete(5);
	}
}
