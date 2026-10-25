<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\Rule;
use OCA\Vereinsbuchhaltung\Db\RuleMapper;
use OCA\Vereinsbuchhaltung\Service\FieldCipher;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Was die Mapper mit verschlüsselten Spalten schreiben und lesen: Chiffrat in
 * der Datenbank, Klartext in der Entity.
 */
class EncryptingMapperTest extends TestCase {

	private const IBAN = 'DE89370400440532013000';

	private IDBConnection&MockObject $db;
	private FieldCipher $cipher;

	/** @var list<array{op: ?string, table: ?string, values: array<string, mixed>}> */
	private array $writes = [];
	/** @var list<array<string, mixed>> Zeilen, die die nächste Abfrage liefert */
	private array $rows = [];

	protected function setUp(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('instanz-secret');
		$this->cipher = new FieldCipher($config);
		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->queryBuilder());
	}

	private function assertEncrypted(string $expected, mixed $written): void {
		$this->assertIsString($written);
		$this->assertTrue(FieldCipher::isEncrypted($written), 'Klartext statt Chiffrat geschrieben');
		$this->assertSame($expected, $this->cipher->decrypt($written));
	}

	private function queryBuilder(): IQueryBuilder {
		$state = ['op' => null, 'table' => null, 'values' => []];
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$qb->method('createNamedParameter')->willReturnArgument(0);
		foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'addOrderBy'] as $fluent) {
			$qb->method($fluent)->willReturnSelf();
		}
		foreach (['insert', 'update'] as $op) {
			$qb->method($op)->willReturnCallback(function (string $table) use ($qb, $op, &$state): IQueryBuilder {
				$state['op'] = $op;
				$state['table'] = $table;
				return $qb;
			});
		}
		foreach (['setValue', 'set'] as $setter) {
			$qb->method($setter)->willReturnCallback(function (string $column, mixed $value) use ($qb, &$state): IQueryBuilder {
				$state['values'][$column] = $value;
				return $qb;
			});
		}
		$qb->method('executeStatement')->willReturnCallback(function () use (&$state): int {
			$this->writes[] = $state;
			return 1;
		});
		$qb->method('getLastInsertId')->willReturn(7);
		$qb->method('executeQuery')->willReturnCallback(function (): IResult {
			$rows = $this->rows;
			$result = $this->createMock(IResult::class);
			$result->method('fetch')->willReturnCallback(static function () use (&$rows): array|false {
				return array_shift($rows) ?? false;
			});
			return $result;
		});
		return $qb;
	}

	public function testInsertSchreibtChiffratUndLaesstDerEntityDenKlartext(): void {
		$mandate = new Mandate();
		$mandate->setIban(self::IBAN);
		$mandate->setAccountHolder('Erika Mustermann');

		(new MandateMapper($this->db, $this->cipher))->insert($mandate);

		$written = $this->writes[0]['values'];
		$this->assertEncrypted(self::IBAN, $written['iban']);
		$this->assertSame('Erika Mustermann', $written['account_holder']);
		$this->assertSame(self::IBAN, $mandate->getIban());
	}

	public function testLesenEntschluesseltUndNimmtBestandsklartextMit(): void {
		$this->rows = [
			['id' => 1, 'iban' => $this->cipher->encrypt(self::IBAN)],
			['id' => 2, 'iban' => 'AT611904300234573201'],
			['id' => 3, 'iban' => null],
		];

		$mandates = (new MandateMapper($this->db, $this->cipher))->findAll();

		$this->assertSame([self::IBAN, 'AT611904300234573201', null], array_map(static fn (Mandate $m): ?string => $m->getIban(), $mandates));
		// Das Entschlüsseln ist keine Änderung, die beim nächsten update() mitginge.
		$this->assertSame([], $mandates[0]->getUpdatedFields());
	}

	public function testUpdateOhneIbanAenderungLaesstDieIbanInRuhe(): void {
		$this->rows = [['id' => 3, 'iban' => $this->cipher->encrypt(self::IBAN), 'status' => 'active']];
		$mapper = new MandateMapper($this->db, $this->cipher);
		$mandate = $mapper->find(3);

		$mandate->setStatus('ended');
		$mapper->update($mandate);

		$this->assertSame(['status' => 'ended'], $this->writes[0]['values']);
	}

	public function testGeaenderteIbanWirdVerschluesseltGeschrieben(): void {
		$this->rows = [['id' => 3, 'iban' => $this->cipher->encrypt(self::IBAN)]];
		$mapper = new MandateMapper($this->db, $this->cipher);
		$mandate = $mapper->find(3);

		$mandate->setIban('AT611904300234573201');
		$mapper->update($mandate);

		$this->assertEncrypted('AT611904300234573201', $this->writes[0]['values']['iban']);
		$this->assertSame('AT611904300234573201', $mandate->getIban());
	}

	/** Die DSGVO-Löschung leert die IBAN – das muss ein echtes NULL bleiben. */
	public function testGeleerteIbanBleibtNull(): void {
		$this->rows = [['id' => 3, 'iban' => $this->cipher->encrypt(self::IBAN)]];
		$mapper = new MandateMapper($this->db, $this->cipher);
		$mandate = $mapper->find(3);

		$mandate->setIban(null);
		$mapper->update($mandate);

		$this->assertSame(['iban' => null], $this->writes[0]['values']);
	}

	public function testRuecklastschriftFindetPostenUeberDieEntschluesselteIban(): void {
		$this->rows = [
			['id' => 9, 'amount_cents' => 1200, 'iban' => $this->cipher->encrypt('AT611904300234573201')],
			['id' => 8, 'amount_cents' => 1200, 'iban' => $this->cipher->encrypt(self::IBAN)],
			['id' => 5, 'amount_cents' => 1200, 'iban' => self::IBAN],
		];

		$items = (new DebitItemMapper($this->db, $this->cipher))->findByAmountAndIban(1200, self::IBAN);

		$this->assertSame([8, 5], array_map(static fn ($item): int => $item->getId(), $items));
	}

	public function testRegelSuchwerteWerdenVerschluesselt(): void {
		$mapper = new RuleMapper($this->db, $this->cipher);
		$byName = new Rule();
		$byName->setMatchField('counterparty');
		$byName->setMatchValue('Stadtwerke');
		$byIban = new Rule();
		$byIban->setMatchField('iban');
		$byIban->setMatchValue(self::IBAN);

		$mapper->insert($byName);
		$mapper->insert($byIban);

		$this->assertEncrypted('Stadtwerke', $this->writes[0]['values']['match_value']);
		$this->assertEncrypted(self::IBAN, $this->writes[1]['values']['match_value']);
	}

	/** Wird eine Namensregel zur IBAN-Regel, liegt ihr Suchwert schon verschlüsselt – nichts muss nachgeschrieben werden. */
	public function testRegelWechseltDasSuchfeld(): void {
		$this->rows = [['id' => 4, 'match_field' => 'purpose', 'match_value' => $this->cipher->encrypt(self::IBAN)]];
		$mapper = new RuleMapper($this->db, $this->cipher);
		$rule = $mapper->find(4, 'admin');
		$this->assertSame(self::IBAN, $rule->getMatchValue());

		$rule->setMatchField('iban');
		$mapper->update($rule);

		$this->assertSame(['match_field' => 'iban'], $this->writes[0]['values']);
	}
}
