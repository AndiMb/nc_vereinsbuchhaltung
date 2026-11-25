<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Notification\UpdateNotificationFilter;
use OCA\Vereinsbuchhaltung\Service\UpdateHintSettings;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\AppConfigStub;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Nextclouds Benachrichtigung „Vereinsbuchhaltung wurde aktualisiert“ kommt
 * aus `updatenotification`. Ist der Schalter „Hinweise auf neue Versionen“
 * aus, verwirft der Filter sie (AlreadyProcessedException: der Manager löscht
 * sie und zeigt sie nicht an); sonst und für alle anderen Benachrichtigungen
 * hält er sich heraus (UnknownNotificationException).
 */
class UpdateNotificationFilterTest extends TestCase {

	/** @param array<string, string> $settings */
	private function filter(array $settings = [], ?IAppConfig $config = null): UpdateNotificationFilter {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		return new UpdateNotificationFilter(new UpdateHintSettings($config ?? AppConfigStub::create($this, $settings)), $factory);
	}

	/** @param list<string> $parameters */
	private function notification(string $app = 'updatenotification', string $subject = 'app_updated', array $parameters = ['vereinsbuchhaltung']): INotification&MockObject {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn($app);
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($parameters);
		return $notification;
	}

	public function testStandardmaessigBleibtDieUpdateBenachrichtigungStehen(): void {
		$this->expectException(UnknownNotificationException::class);
		$this->filter()->prepare($this->notification(), 'de');
	}

	public function testEingeschaltetBleibtDieUpdateBenachrichtigungStehen(): void {
		$this->expectException(UnknownNotificationException::class);
		$this->filter(['update_hints_enabled' => '1'])->prepare($this->notification(), 'de');
	}

	public function testAusgeschaltetWirdDieUpdateBenachrichtigungVerworfen(): void {
		$this->expectException(AlreadyProcessedException::class);
		$this->filter(['update_hints_enabled' => '0'])->prepare($this->notification(), 'de');
	}

	/** Updates anderer Apps meldet Nextcloud weiter – der Schalter gilt nur für diese App. */
	public function testUpdatesAndererAppsBleibenStehen(): void {
		$this->expectException(UnknownNotificationException::class);
		$this->filter(['update_hints_enabled' => '0'])->prepare($this->notification(parameters: ['deck']), 'de');
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: list<string>}>
	 */
	public static function fremdeBenachrichtigungen(): array {
		return [
			'andere App' => ['spreed', 'app_updated', ['vereinsbuchhaltung']],
			'anderer Betreff von updatenotification' => ['updatenotification', 'update_available', ['vereinsbuchhaltung']],
			'ohne Parameter' => ['updatenotification', 'app_updated', []],
		];
	}

	/**
	 * Der Filter wird für jede Benachrichtigung der Instanz gefragt: fremde
	 * lehnt er ab, ohne überhaupt die Einstellung zu lesen.
	 *
	 * @param list<string> $parameters
	 */
	#[DataProvider('fremdeBenachrichtigungen')]
	public function testFremdeBenachrichtigungenLehntErAbOhneDieEinstellungZuLesen(string $app, string $subject, array $parameters): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->never())->method('getValueString');

		$this->expectException(UnknownNotificationException::class);
		$this->filter(config: $config)->prepare($this->notification($app, $subject, $parameters), 'de');
	}

	public function testHatEineEigeneKennung(): void {
		$filter = $this->filter();
		$this->assertSame('vereinsbuchhaltung_update_hints', $filter->getID());
		$this->assertSame('Vereinsbuchhaltung', $filter->getName());
	}
}
