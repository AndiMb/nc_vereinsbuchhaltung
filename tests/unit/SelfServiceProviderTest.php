<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Activity\SelfServiceProvider;
use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\SelfServiceMandateService;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Formatiert die Self-Service-Activity-Einträge (Spec §3.4) - EIN Provider
 * für beide Aktionskataloge (Mandat #75, Beitrag/Kontaktdaten #76), siehe
 * Klassendoc von {@see SelfServiceProvider}. Unbekannte Ereignisse/fremde
 * Apps müssen {@see UnknownActivityException} werfen - der korrekte Weg, dem
 * Activity-Manager "nicht meins" zu sagen.
 */
class SelfServiceProviderTest extends TestCase {

	private function provider(): SelfServiceProvider {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters),
		);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->with(Application::APP_ID, 'de')->willReturn($l10n);
		return new SelfServiceProvider($factory);
	}

	private function event(string $subject, array $params, string $app = Application::APP_ID): IEvent&MockObject {
		$event = $this->createMock(IEvent::class);
		$event->method('getApp')->willReturn($app);
		$event->method('getSubject')->willReturn($subject);
		$event->method('getSubjectParameters')->willReturn($params);
		return $event;
	}

	public function testWirftBeiFremderAppUnknownActivityException(): void {
		$event = $this->event(SelfServiceProvider::SUBJECT_CONTACT_UPDATED, [], app: 'andere_app');
		$this->expectException(UnknownActivityException::class);
		$this->provider()->parse('de', $event);
	}

	public function testWirftBeiUnbekanntemSubjectUnknownActivityException(): void {
		$event = $this->event('irgendwas_unbekanntes', []);
		$this->expectException(UnknownActivityException::class);
		$this->provider()->parse('de', $event);
	}

	public function testFormatiertBetragsAenderung(): void {
		$event = $this->event(SelfServiceProvider::SUBJECT_CONTRIBUTION_AMOUNT_CHANGED, [
			'from' => 1000, 'to' => 1500, 'effectiveFrom' => '2026-07-01',
		]);
		$event->expects($this->once())->method('setParsedSubject')
			->with('Monatsbeitrag geändert: 10,00 € → 15,00 € (wirkt ab 2026-07-01)')
			->willReturnSelf();

		$this->provider()->parse('de', $event);
	}

	public function testFormatiertTurnusAenderung(): void {
		$event = $this->event(SelfServiceProvider::SUBJECT_CONTRIBUTION_INTERVAL_CHANGED, [
			'from' => 1, 'to' => 12, 'effectiveFrom' => '2027-01-01',
		]);
		$event->expects($this->once())->method('setParsedSubject')
			->with('Turnus geändert: alle 1 Monate → alle 12 Monate (wirkt ab 2027-01-01)')
			->willReturnSelf();

		$this->provider()->parse('de', $event);
	}

	public function testFormatiertKontaktdatenAktualisiert(): void {
		$event = $this->event(SelfServiceProvider::SUBJECT_CONTACT_UPDATED, []);
		$event->expects($this->once())->method('setParsedSubject')->with('Kontaktdaten aktualisiert')->willReturnSelf();

		$this->provider()->parse('de', $event);
	}

	/** Ein Provider für beide Aktionskataloge (Issue #75/#76) - der Mandats-Teil muss weiterhin funktionieren. */
	public function testFormatiertMandatsWiderrufAusIssue75(): void {
		$event = $this->event(SelfServiceMandateService::SUBJECT_REVOKED, []);
		$event->expects($this->once())->method('setParsedSubject')->with('SEPA-Lastschriftmandat widerrufen')->willReturnSelf();

		$this->provider()->parse('de', $event);
	}
}
