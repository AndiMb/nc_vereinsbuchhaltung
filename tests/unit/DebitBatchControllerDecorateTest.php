<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Controller\DebitBatchController;
use OCA\Vereinsbuchhaltung\Db\DebitBatch;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Service\ContributionCycleSettings;
use OCA\Vereinsbuchhaltung\Service\ContributionCycleTaskService;
use OCA\Vereinsbuchhaltung\Service\DebitBatchService;
use OCA\Vereinsbuchhaltung\Service\DebitBatchXmlStorageService;
use OCA\Vereinsbuchhaltung\Service\DebitTimelineService;
use OCA\Vereinsbuchhaltung\Service\FolderPathValidator;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Die Lauf-Antwort des Controllers (Issue #103): `xmlStoragePath` sagt dem
 * Buchhalter, in welchem Nextcloud-Ordner die optionale XML-Kopie liegt. Die
 * Einstellung selbst ist `verwalter`-only, die Oberfläche der Freigabe braucht
 * den Ordnernamen aber ab `buchhalter`.
 */
class DebitBatchControllerDecorateTest extends TestCase {

	private DebitBatchService&MockObject $service;
	private DebitBatchXmlStorageService&MockObject $xmlStorage;

	protected function setUp(): void {
		$this->service = $this->createMock(DebitBatchService::class);
		$this->service->method('findItems')->willReturn([['amountCents' => 1250], ['amountCents' => 500]]);
		$this->service->method('driftWarning')->willReturn(null);
		$this->xmlStorage = $this->createMock(DebitBatchXmlStorageService::class);
	}

	private function controller(): DebitBatchController {
		$config = $this->createMock(IConfig::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []) => vsprintf($text, $params));

		return new DebitBatchController(
			$this->createMock(IRequest::class),
			$this->service,
			$this->xmlStorage,
			new ContributionCycleSettings($config),
			new FolderPathValidator($l10n),
			$l10n,
			$this->createMock(DebitTimelineService::class),
			$this->createMock(ContributionCycleTaskService::class),
			$this->createMock(MemberMapper::class),
			$this->createMock(IUserManager::class),
		);
	}

	private function batch(): DebitBatch {
		$batch = new DebitBatch();
		$batch->setId(7);
		$batch->setDueDate('2026-11-01');
		$batch->setStatus(DebitBatch::STATUS_RELEASED);
		return $batch;
	}

	public function testFreigabeNenntDenAblageordnerBeiEingeschalteterAblage(): void {
		$this->xmlStorage->method('isEnabled')->willReturn(true);
		$this->xmlStorage->method('folderPath')->willReturn('SEPA-Einreichungen');
		$this->service->method('release')->with('2026-11-01')->willReturn($this->batch());

		$response = $this->controller()->release('2026-11-01');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('SEPA-Einreichungen', $data['xmlStoragePath']);
		$this->assertSame(2, $data['itemCount']);
		$this->assertSame(1750, $data['sumCents']);
	}

	public function testOhneAblageKeinOrdner(): void {
		$this->xmlStorage->method('isEnabled')->willReturn(false);
		$this->xmlStorage->expects($this->never())->method('folderPath');
		$this->service->method('release')->willReturn($this->batch());

		$this->assertNull($this->controller()->release('2026-11-01')->getData()['xmlStoragePath']);
	}

	public function testDieTerminverschiebungTraegtDenOrdnerEbenfalls(): void {
		// Auch die Verschiebung legt eine neue Kopie ab (DebitBatchService::rescheduleDueDate()).
		$this->xmlStorage->method('isEnabled')->willReturn(true);
		$this->xmlStorage->method('folderPath')->willReturn('Einreichungen/SEPA');
		$this->service->method('rescheduleDueDate')->with(7, '2026-11-15')->willReturn($this->batch());

		$this->assertSame('Einreichungen/SEPA', $this->controller()->reschedule(7, '2026-11-15')->getData()['xmlStoragePath']);
	}
}
