<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Controller\RuleController;
use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BookingCostCenter;
use OCA\Vereinsbuchhaltung\Db\BookingCostCenterMapper;
use OCA\Vereinsbuchhaltung\Db\Rule;
use OCA\Vereinsbuchhaltung\Db\RuleMapper;
use OCA\Vereinsbuchhaltung\Db\RulePart;
use OCA\Vereinsbuchhaltung\Db\RulePartMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Service\CostCenterSettings;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Feste Anteile an einer Importregel (Issue #163) über die API: in Euro
 * angenommen, je Anteil auf Cent gerundet, vollständig geprüft, bevor etwas
 * gespeichert wird. Fehlt `parts` im Request (alter Client), bleiben die
 * gespeicherten Anteile stehen.
 */
class RuleControllerPartsTest extends TestCase {

	private const SEN = 3;

	private bool $enabled = true;
	/** @var list<list<array{0:int, 1:int, 2:?int, 3:int}>> jeder replaceForRule()-Aufruf: [Konto, Cent, Kostenstelle, Reihenfolge] je Anteil */
	private array $replaced = [];

	/** @param array<string, mixed> $params Rohparameter des Requests */
	private function controller(array $params): RuleController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$stored = new Rule();
		$stored->setId(8);
		$stored->setMatchField('purpose');
		$stored->setMatchValue('Beitrag');
		$stored->setContraAccountId(5);
		$mapper = $this->createMock(RuleMapper::class);
		$mapper->method('insert')->willReturnCallback(static function (Rule $r): Rule {
			$r->setId(9);
			return $r;
		});
		$mapper->method('update')->willReturnArgument(0);
		$mapper->method('find')->willReturn($stored);
		$costCenters = $this->createMock(BookingCostCenterMapper::class);
		$costCenters->method('find')->willReturnCallback(static function (int $id): BookingCostCenter {
			if ($id !== self::SEN) {
				throw new DoesNotExistException('keine Kostenstelle');
			}
			return new BookingCostCenter();
		});
		$settings = $this->createMock(CostCenterSettings::class);
		$settings->method('isEnabled')->willReturnCallback(fn (): bool => $this->enabled);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$parts = $this->createMock(RulePartMapper::class);
		$parts->method('replaceForRule')->willReturnCallback(function (string $userId, int $ruleId, array $list): array {
			$this->replaced[] = array_map(static fn (RulePart $p): array => [
				$p->getAccountId(), $p->getAmountCents(), $p->getCostCenterId(), count($list),
			], $list);
			return $list;
		});
		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('find')->willReturnCallback(static function (int $id): Account {
			if (!in_array($id, [5, 10, 11], true)) {
				throw new DoesNotExistException('kein Konto');
			}
			return new Account();
		});
		$transaction = $this->createMock(TransactionRunner::class);
		$transaction->method('run')->willReturnCallback(static fn (callable $work) => $work());
		return new RuleController($request, $mapper, $l10n, $costCenters, $settings, $parts, $accounts, $transaction);
	}

	/** @return array<string, mixed> */
	private function json(DataResponse $response): array {
		$data = $response->getData();
		return $data instanceof Rule ? $data->jsonSerialize() : (array)$data;
	}

	public function testCreateMitTeilenSpeichertSie(): void {
		$parts = [['accountId' => 10, 'amount' => 10.1], ['accountId' => 11, 'amount' => 5, 'costCenterId' => self::SEN]];
		$response = $this->controller(['parts' => $parts])->create('purpose', 'Beitrag', 5, 0, null, $parts);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame([[[10, 1010, null, 2], [11, 500, self::SEN, 2]]], $this->replaced);
		$this->assertSame([
			['accountId' => 10, 'amount' => 10.1, 'costCenterId' => null],
			['accountId' => 11, 'amount' => 5.0, 'costCenterId' => self::SEN],
		], $this->json($response)['parts']);
	}

	public function testUpdateOhnePartsParamLaesstTeileStehen(): void {
		$response = $this->controller([])->update(8, 'purpose', 'Beitrag', 5);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $this->replaced);
	}

	public function testUpdateMitLeererListeEntferntTeile(): void {
		$response = $this->controller(['parts' => []])->update(8, 'purpose', 'Beitrag', 5, 0, null, []);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([[]], $this->replaced);
	}

	/** @return array<string, array{0: array<string, mixed>}> */
	public static function ungueltigeTeile(): array {
		return [
			'Betrag 0' => [['accountId' => 10, 'amount' => 0]],
			'Betrag negativ' => [['accountId' => 10, 'amount' => -5]],
			'Konto 0' => [['accountId' => 0, 'amount' => 5]],
			'Konto fehlt' => [['amount' => 5]],
		];
	}

	/**
	 * @dataProvider ungueltigeTeile
	 * @param array<string, mixed> $part
	 */
	public function testUngueltigerTeil(array $part): void {
		$parts = [['accountId' => 10, 'amount' => 1], $part];
		$response = $this->controller(['parts' => $parts])->create('purpose', 'Beitrag', 5, 0, null, $parts);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Ungültiger Anteil (Konto oder Betrag fehlt).', $this->json($response)['message']);
		$this->assertSame([], $this->replaced);
	}

	public function testKostenstelleImTeilBeiAusgeschaltetemModul(): void {
		$this->enabled = false;
		$parts = [['accountId' => 10, 'amount' => 5, 'costCenterId' => self::SEN]];
		$response = $this->controller(['parts' => $parts])->create('purpose', 'Beitrag', 5, 0, null, $parts);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Kostenstellen sind in den Einstellungen ausgeschaltet.', $this->json($response)['message']);
		$this->assertSame([], $this->replaced);
	}

	public function testUnbekanntesKontoImTeil(): void {
		$parts = [['accountId' => 99, 'amount' => 5]];
		$response = $this->controller(['parts' => $parts])->update(8, 'purpose', 'Beitrag', 5, 0, null, $parts);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Konto nicht gefunden', $this->json($response)['message']);
		$this->assertSame([], $this->replaced);
	}
}
