<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Controller\RuleController;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BookingCostCenter;
use OCA\Vereinsbuchhaltung\Db\BookingCostCenterMapper;
use OCA\Vereinsbuchhaltung\Db\Rule;
use OCA\Vereinsbuchhaltung\Db\RuleMapper;
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
 * Kostenstelle an einer Importregel (Issue #331): optional, nur bei
 * eingeschaltetem Modul änderbar, und beim Bearbeiten ohne Angabe bleibt die
 * gespeicherte Vorgabe stehen (alter Client, ausgeschaltetes Modul).
 */
class RuleControllerCostCenterTest extends TestCase {

	private const SEN = 3;

	private bool $enabled = true;
	private Rule $stored;

	protected function setUp(): void {
		$this->enabled = true;
		$this->stored = new Rule();
		$this->stored->setId(8);
		$this->stored->setMatchField('purpose');
		$this->stored->setMatchValue('Miete');
		$this->stored->setContraAccountId(5);
		$this->stored->setCostCenterId(self::SEN);
	}

	/** @param array<string, mixed> $params Rohparameter des Requests */
	private function controller(array $params): RuleController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$mapper = $this->createMock(RuleMapper::class);
		$mapper->method('insert')->willReturnArgument(0);
		$mapper->method('update')->willReturnArgument(0);
		$mapper->method('find')->willReturn($this->stored);
		$costCenters = $this->createMock(BookingCostCenterMapper::class);
		$costCenters->method('find')->willReturnCallback(static function (int $id): BookingCostCenter {
			if ($id !== self::SEN) {
				throw new DoesNotExistException('keine Kostenstelle');
			}
			$cc = new BookingCostCenter();
			$cc->setId($id);
			return $cc;
		});
		$settings = $this->createMock(CostCenterSettings::class);
		$settings->method('isEnabled')->willReturnCallback(fn (): bool => $this->enabled);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$transaction = $this->createMock(TransactionRunner::class);
		$transaction->method('run')->willReturnCallback(static fn (callable $work) => $work());
		return new RuleController($request, $mapper, $l10n, $costCenters, $settings, $this->createMock(RulePartMapper::class), $this->createMock(AccountMapper::class), $transaction);
	}

	/** Die Regel aus der Antwort – bei einem Fehler stünde dort eine Meldung. */
	private function rule(DataResponse $response): Rule {
		$data = $response->getData();
		$this->assertInstanceOf(Rule::class, $data);
		return $data;
	}

	public function testKostenstelleWirdBeimAnlegenGespeichert(): void {
		$response = $this->controller(['costCenterId' => self::SEN])->create('purpose', 'Miete', 5, 0, self::SEN);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(self::SEN, $this->rule($response)->getCostCenterId());
	}

	public function testAnlegenOhneKostenstelleBleibtOhne(): void {
		$response = $this->controller([])->create('purpose', 'Miete', 5);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertNull($this->rule($response)->getCostCenterId());
	}

	public function testNullEntferntDieVorgabe(): void {
		$response = $this->controller(['costCenterId' => null])->update(8, 'purpose', 'Miete', 5, 0, null);

		$this->assertNull($this->rule($response)->getCostCenterId());
	}

	public function testNullwertEntferntDieVorgabe(): void {
		$response = $this->controller(['costCenterId' => 0])->update(8, 'purpose', 'Miete', 5, 0, 0);

		$this->assertNull($this->rule($response)->getCostCenterId());
	}

	public function testUnbekannteKostenstelleWirdAbgelehnt(): void {
		$response = $this->controller(['costCenterId' => 77])->update(8, 'purpose', 'Miete', 5, 0, 77);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testBeiAusgeschaltetemModulWirdEinWertAbgelehnt(): void {
		$this->enabled = false;
		$response = $this->controller(['costCenterId' => self::SEN])->create('purpose', 'Miete', 5, 0, self::SEN);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testOhneAngabeBleibtDieVorgabeBeimAendernStehen(): void {
		// Auch – und gerade – bei ausgeschaltetem Modul: das Formular kennt das Feld dann nicht.
		$this->enabled = false;
		$response = $this->controller([])->update(8, 'purpose', 'Nebenkosten', 5);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(self::SEN, $this->rule($response)->getCostCenterId());
		$this->assertSame('Nebenkosten', $this->rule($response)->getMatchValue());
	}

	public function testRegelNenntDieKostenstelleInDerAusgabe(): void {
		$this->assertSame(self::SEN, $this->stored->jsonSerialize()['costCenterId']);
		$this->assertArrayHasKey('costCenterId', (new Rule())->jsonSerialize());
		$this->assertNull((new Rule())->jsonSerialize()['costCenterId']);
	}
}
