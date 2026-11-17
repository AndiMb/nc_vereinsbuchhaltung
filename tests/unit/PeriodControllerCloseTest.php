<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Controller\PeriodController;
use OCA\Vereinsbuchhaltung\Db\Period;
use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCA\Vereinsbuchhaltung\Service\Export\KassenberichtArchive;
use OCA\Vereinsbuchhaltung\Service\FrontendStateService;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Abschluss eines Geschäftsjahres mit Ablage des Kassenberichts (Issue #364):
 * die Ablage folgt dem Abschluss und hält ihn nie auf.
 */
class PeriodControllerCloseTest extends TestCase {

	private PeriodService&MockObject $periods;
	private KassenberichtArchive&MockObject $archive;

	protected function setUp(): void {
		$this->periods = $this->createMock(PeriodService::class);
		$this->archive = $this->createMock(KassenberichtArchive::class);
	}

	private function controller(): PeriodController {
		$permissions = $this->createMock(PermissionService::class);
		$permissions->method('isAdmin')->willReturn(true);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('vera');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $p = []): string => vsprintf($text, $p));
		return new PeriodController(
			$this->createMock(IRequest::class),
			$this->periods,
			$permissions,
			$session,
			$l10n,
			$this->createMock(FrontendStateService::class),
			$this->archive,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function period(bool $closed): Period {
		$period = new Period();
		$period->setId(3);
		$period->setLabel('2026');
		if ($closed) {
			$period->setClosedAt('2026-12-31 18:00:00');
			$period->setClosedBy('vera');
		}
		return $period;
	}

	public function testAbschlussMeldetAbgelegteDatei(): void {
		$this->periods->method('find')->willReturn($this->period(false));
		$this->periods->method('close')->willReturn($this->period(true));
		$this->archive->expects($this->once())->method('storeOnClose')->willReturn('Vereinsbuchhaltung/Berichte/Kassenbericht-2026.pdf');

		$response = $this->controller()->close(3);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('Vereinsbuchhaltung/Berichte/Kassenbericht-2026.pdf', $response->getData()['reportFile']);
	}

	public function testGescheiterteAblageHaeltDenAbschlussNichtAuf(): void {
		$this->periods->method('find')->willReturn($this->period(false));
		$this->periods->expects($this->once())->method('close')->willReturn($this->period(true));
		$this->archive->method('storeOnClose')->willThrowException(new \RuntimeException('Speicher voll'));

		$response = $this->controller()->close(3);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('Der Kassenbericht konnte nicht als PDF abgelegt werden.', $response->getData()['reportError']);
		$this->assertArrayNotHasKey('reportFile', $response->getData());
	}

	public function testNutzerfehlerWirdWoertlichGemeldet(): void {
		$this->periods->method('find')->willReturn($this->period(false));
		$this->periods->method('close')->willReturn($this->period(true));
		$this->archive->method('storeOnClose')->willThrowException(new UserFacingException("Pfadkomponente 'Berichte' ist kein Ordner"));

		$this->assertSame("Pfadkomponente 'Berichte' ist kein Ordner", $this->controller()->close(3)->getData()['reportError']);
	}

	/** Ein schon abgeschlossenes Jahr erneut „abschließen" legt keine zweite Datei an. */
	public function testBereitsAbgeschlossenLegtNichtsAb(): void {
		$this->periods->method('find')->willReturn($this->period(true));
		$this->periods->method('close')->willReturn($this->period(true));
		$this->archive->expects($this->never())->method('storeOnClose');

		$response = $this->controller()->close(3);

		$this->assertArrayNotHasKey('reportFile', $response->getData());
		$this->assertArrayNotHasKey('reportError', $response->getData());
	}

	public function testOhneAblageNurDerAbschluss(): void {
		$this->periods->method('find')->willReturn($this->period(false));
		$this->periods->method('close')->willReturn($this->period(true));
		$this->archive->method('storeOnClose')->willReturn(null);

		$data = $this->controller()->close(3)->getData();

		$this->assertSame('2026', $data['label']);
		$this->assertArrayNotHasKey('reportFile', $data);
	}
}
