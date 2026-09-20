<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Activity\SelfServiceSetting;
use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\SelfServiceActivityPublisher;
use OCP\Activity\IEvent;
use OCP\Activity\IManager;
use PHPUnit\Framework\TestCase;

/**
 * Dünne Kapselung um {@see IManager} (Spec §3.4: "OCP\Activity-Feed (jede
 * Änderung)") - der Test prüft, dass alle für publish() Pflichtfelder
 * (App/Typ/AffectedUser/Subject/Object) tatsächlich gesetzt werden, bevor das
 * Event beim IManager landet.
 */
class SelfServiceActivityPublisherTest extends TestCase {

	public function testPublishSetztPflichtfelderUndVeroeffentlicht(): void {
		$event = $this->createMock(IEvent::class);
		$event->expects($this->once())->method('setApp')->with(Application::APP_ID)->willReturnSelf();
		$event->expects($this->once())->method('setType')->with(SelfServiceSetting::TYPE)->willReturnSelf();
		$event->expects($this->once())->method('setAffectedUser')->with('katrin.b')->willReturnSelf();
		$event->expects($this->once())->method('setAuthor')->with('katrin.b')->willReturnSelf();
		$event->expects($this->once())->method('setTimestamp')->willReturnSelf();
		$event->expects($this->once())->method('setSubject')->with('contact_updated', ['foo' => 'bar'])->willReturnSelf();
		$event->expects($this->once())->method('setObject')->with('member', 5)->willReturnSelf();

		$manager = $this->createMock(IManager::class);
		$manager->method('generateEvent')->willReturn($event);
		$manager->expects($this->once())->method('publish')->with($event);

		(new SelfServiceActivityPublisher($manager))->publish('katrin.b', 'contact_updated', ['foo' => 'bar'], 'member', 5);
	}
}
