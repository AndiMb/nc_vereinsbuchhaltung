<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Notification\UpdateNotificationFilter;
use OCA\Vereinsbuchhaltung\Service\UpdateHintSettings;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\AppConfigStub;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\TestCase;

/** Nextclouds Update-Benachrichtigung zur App: bleibt bei „an“, verschwindet bei „aus“. */
class UpdateNotificationFilterTest extends TestCase {

	/** @param array<string, string> $settings */
	private function prepare(array $settings, string $app = 'updatenotification', string $updatedApp = 'vereinsbuchhaltung'): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn($app);
		$notification->method('getSubject')->willReturn('app_updated');
		$notification->method('getSubjectParameters')->willReturn([$updatedApp]);
		$filter = new UpdateNotificationFilter(new UpdateHintSettings(AppConfigStub::create($this, $settings)), $this->createMock(IFactory::class));
		$filter->prepare($notification, 'de');
	}

	public function testStandardmaessigBleibtDieBenachrichtigungStehen(): void {
		$this->expectException(UnknownNotificationException::class);
		$this->prepare([]);
	}

	public function testAusgeschaltetWirdSieVerworfen(): void {
		$this->expectException(AlreadyProcessedException::class);
		$this->prepare(['update_hints_enabled' => '0']);
	}

	public function testUpdatesAndererAppsBleibenStehen(): void {
		$this->expectException(UnknownNotificationException::class);
		$this->prepare(['update_hints_enabled' => '0'], updatedApp: 'deck');
	}

	public function testFremdeBenachrichtigungenBleibenStehen(): void {
		$this->expectException(UnknownNotificationException::class);
		$this->prepare(['update_hints_enabled' => '0'], app: 'spreed');
	}
}
