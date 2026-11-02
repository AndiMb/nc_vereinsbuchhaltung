<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Controller\MandateConsentController;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateActivationToken;
use OCA\Vereinsbuchhaltung\Db\MandateLegalTextVersion;
use OCA\Vereinsbuchhaltung\Exception\ConsumedActivationTokenException;
use OCA\Vereinsbuchhaltung\Exception\ExpiredActivationTokenException;
use OCA\Vereinsbuchhaltung\Exception\InvalidActivationTokenException;
use OCA\Vereinsbuchhaltung\Service\MandateActivationService;
use OCA\Vereinsbuchhaltung\Service\MandateFormRenderer;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Die öffentliche Zustimmungsseite muss Fehlversuche wirklich drosseln (#259):
 * `#[BruteForceProtection]` zählt nur, wenn die Antwort `throttle()` ruft.
 * Ein gültiger Link und das legitim beantwortete Formular (bereits
 * verbraucht) zählen nicht als Fehlversuch.
 */
class MandateConsentControllerTest extends TestCase {

	private MandateActivationService&MockObject $activation;
	private MandateFormRenderer&MockObject $formRenderer;
	private IRequest&MockObject $request;

	protected function setUp(): void {
		$this->activation = $this->createMock(MandateActivationService::class);
		$this->formRenderer = $this->createMock(MandateFormRenderer::class);
		$this->formRenderer->method('renderLegalText')->willReturn('');
		$this->formRenderer->method('renderDataBlock')->willReturn('');
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getRemoteAddress')->willReturn('127.0.0.1');
		$this->request->method('getHeader')->willReturn('PHPUnit');
	}

	private function controller(): MandateConsentController {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturn('/accept');
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnArgument(2);
		return new MandateConsentController($this->request, $this->activation, $this->formRenderer, $urlGenerator, $config);
	}

	/** @return array<string, array{\Throwable, int}> */
	public static function failures(): array {
		return [
			'ungueltig' => [new InvalidActivationTokenException('ungueltig'), 404],
			'abgelaufen' => [new ExpiredActivationTokenException('abgelaufen'), 410],
			'Mandat geloescht' => [new DoesNotExistException('weg'), 404],
		];
	}

	/** @return array{status: string, mandate: Mandate, legalText: MandateLegalTextVersion, token: MandateActivationToken} */
	private function view(string $status = 'pending'): array {
		return [
			'status' => $status,
			'mandate' => new Mandate(),
			'legalText' => new MandateLegalTextVersion(),
			'token' => new MandateActivationToken(),
		];
	}

	/** @dataProvider failures */
	public function testShowDrosseltFehlerhafteLinks(\Throwable $exception, int $status): void {
		$this->activation->method('view')->willThrowException($exception);

		$response = $this->controller()->show('abc');

		$this->assertTrue($response->isThrottled());
		$this->assertSame($status, $response->getStatus());
	}

	/** @dataProvider failures */
	public function testAcceptDrosseltFehlerhafteLinks(\Throwable $exception, int $status): void {
		$this->activation->method('consent')->willThrowException($exception);

		$response = $this->controller()->accept('abc');

		$this->assertTrue($response->isThrottled());
		$this->assertSame($status, $response->getStatus());
	}

	/** @dataProvider failures */
	public function testAcceptDrosseltFehlerBeimAnschliessendenAnzeigen(\Throwable $exception, int $status): void {
		$this->activation->method('view')->willThrowException($exception);

		$response = $this->controller()->accept('abc');

		$this->assertTrue($response->isThrottled());
		$this->assertSame(404, $response->getStatus());
	}

	public function testDrosselungTraegtDieBruteForceAktion(): void {
		$this->activation->method('view')->willThrowException(new InvalidActivationTokenException('x'));

		$response = $this->controller()->show('abc');

		$this->assertSame(['action' => 'vbh_mandate_consent'], $response->getThrottleMetadata());
	}

	public function testGueltigerLinkWirdNichtGedrosselt(): void {
		$this->activation->method('view')->willReturn($this->view());

		$response = $this->controller()->show('abc');

		$this->assertFalse($response->isThrottled());
		$this->assertSame(200, $response->getStatus());
	}

	public function testErfolgreicheZustimmungWirdNichtGedrosselt(): void {
		$this->activation->method('view')->willReturn($this->view('consumed'));

		$response = $this->controller()->accept('abc');

		$this->assertFalse($response->isThrottled());
	}

	public function testBereitsVerbrauchterLinkBeimAbsendenWirdNichtGedrosselt(): void {
		$this->activation->method('consent')->willThrowException(new ConsumedActivationTokenException('schon erledigt'));
		$this->activation->method('view')->willReturn($this->view('consumed'));

		$response = $this->controller()->accept('abc');

		$this->assertFalse($response->isThrottled());
	}

	public function testBeideMethodenHabenUserRateLimit(): void {
		foreach (['show' => 30, 'accept' => 10] as $method => $limit) {
			$attributes = (new \ReflectionMethod(MandateConsentController::class, $method))->getAttributes(UserRateLimit::class);
			$this->assertCount(1, $attributes, $method);
			$instance = $attributes[0]->newInstance();
			$this->assertSame($limit, $instance->getLimit(), $method);
			$this->assertSame(3600, $instance->getPeriod(), $method);
		}
	}
}
