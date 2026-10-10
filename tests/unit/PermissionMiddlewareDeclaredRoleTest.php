<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Controller\AssignmentController;
use OCA\Vereinsbuchhaltung\Controller\ClaimController;
use OCA\Vereinsbuchhaltung\Controller\ContributionGroupController;
use OCA\Vereinsbuchhaltung\Controller\DueDateScheduleController;
use OCA\Vereinsbuchhaltung\Controller\SettingsController;
use OCA\Vereinsbuchhaltung\Exception\ForbiddenException;
use OCA\Vereinsbuchhaltung\Middleware\PermissionMiddleware;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\ActorContextService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCA\Vereinsbuchhaltung\Service\SelfServiceService;
use OCP\AppFramework\Controller;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Rollen-Härtung (Issue #119, Spec §3.9): Nutzer mit zu geringer Rolle bekommen
 * von der {@see PermissionMiddleware} eine 403 (ForbiddenException) – geprüft mit
 * der echten Middleware gegen die echten Controller-Methoden, nicht gegen eine
 * Attribut-Abschrift im Test.
 *
 * Die Controller werden ohne Konstruktor angelegt (`newInstanceWithoutConstructor`):
 * ein PHPUnit-Mock überschriebe die Methoden OHNE ihre Attribute, die Middleware
 * läse dann nichts aus und fiele auf die Verb-Heuristik zurück – genau die Lücke,
 * die hier geschlossen wird.
 *
 * Der HTTP-Verb des Requests ist absichtlich der, bei dem die Verb-Heuristik die
 * Anfrage durchließe (POST → buchhalter, GET → revisor): wer hier eine Rolle
 * schärft, sieht, dass die ausdrückliche Angabe gewinnt.
 */
class PermissionMiddlewareDeclaredRoleTest extends TestCase {

	private const ROLES = [
		PermissionService::ROLE_NONE,
		PermissionService::ROLE_READ,
		PermissionService::ROLE_WRITE,
		PermissionService::ROLE_ADMIN,
	];

	private function middleware(string $role, string $verb): PermissionMiddleware {
		$permissions = $this->createMock(PermissionService::class);
		$permissions->method('getRole')->willReturn($role);
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')->willReturn($verb);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new PermissionMiddleware(
			$permissions,
			$this->createMock(SelfServiceService::class),
			new ActorContextService($this->createMock(IUserSession::class)),
			$request,
			$l10n,
		);
	}

	/**
	 * @param class-string<Controller> $class
	 */
	private function controller(string $class): Controller {
		return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
	}

	/**
	 * Geprüft wird für jede Rolle, ob sie durchkommt: genau dann, wenn ihr Rang
	 * mindestens dem verlangten entspricht.
	 *
	 * @param class-string<Controller> $class
	 */
	private function assertMinimumRole(string $class, string $method, string $verb, string $minimum): void {
		foreach (self::ROLES as $role) {
			$allowed = PermissionService::RANK[$role] >= PermissionService::RANK[$minimum];
			try {
				$this->middleware($role, $verb)->beforeController($this->controller($class), $method);
				$passed = true;
			} catch (ForbiddenException) {
				$passed = false;
			}
			$this->assertSame(
				$allowed,
				$passed,
				sprintf('%s::%s verlangt mindestens „%s“, Rolle „%s“ %s', (new \ReflectionClass($class))->getShortName(), $method, $minimum, $role, $allowed ? 'müsste durchkommen' : 'müsste eine 403 bekommen'),
			);
		}
	}

	/**
	 * Fail-closed: Ein Tippfehler im Rollennamen des Attributs (hier „verwalterr“) darf eine Methode nie öffnen.
	 * Ohne Rang im RANK-Array wäre der Vergleich mit null wahr gewesen, jede Rolle (auch „none“) wäre durchgekommen.
	 */
	public function testUnbekannteRolleImAttributLaesstNiemandsDurch(): void {
		$controller = new class('vereinsbuchhaltung', $this->createMock(IRequest::class)) extends Controller {
			#[RequiresRole('verwalterr')]
			public function oops(): void {
			}
		};
		foreach (self::ROLES as $role) {
			try {
				$this->middleware($role, 'POST')->beforeController($controller, 'oops');
				$this->fail(sprintf('Rolle „%s“ kam durch eine Methode mit unbekannter Rolle', $role));
			} catch (ForbiddenException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	/**
	 * Vorlaufzeiten (Vorwarnfenster, Vorabinfo-Vorlauf) sind Einstellungen: nur
	 * `verwalter`. Vorher fiel die Methode auf die Schreib-Heuristik zurück und
	 * stand damit auch dem Buchhalter offen.
	 */
	public function testVorlaufzeitenAendernDarfNurDerVerwalter(): void {
		$this->assertMinimumRole(DueDateScheduleController::class, 'setLeadDays', 'POST', PermissionService::ROLE_ADMIN);
	}

	public function testBuchhalterBekommtBeiDenVorlaufzeitenEine403MitDemVerwalterHinweis(): void {
		try {
			$this->middleware(PermissionService::ROLE_WRITE, 'POST')->beforeController($this->controller(DueDateScheduleController::class), 'setLeadDays');
			$this->fail('Ein Buchhalter darf die Vorlaufzeiten nicht ändern.');
		} catch (ForbiddenException $e) {
			$this->assertSame('Diese Aktion ist Verwaltern vorbehalten.', $e->getMessage());
		}
	}

	/** Die Terminverschiebung (Spec §3.9) bleibt operativ: `buchhalter`. */
	public function testTerminverschiebungBleibtBeimBuchhalter(): void {
		$this->assertMinimumRole(DueDateScheduleController::class, 'setDefaultDay', 'POST', PermissionService::ROLE_WRITE);
		$this->assertMinimumRole(DueDateScheduleController::class, 'setOverride', 'POST', PermissionService::ROLE_WRITE);
	}

	public function testTerminplanLesenAbRevisor(): void {
		$this->assertMinimumRole(DueDateScheduleController::class, 'index', 'GET', PermissionService::ROLE_READ);
	}

	/** Bisher ohne Angabe (Verb-Heuristik), jetzt ausdrücklich – gleiche Wirkung, aber festgeschrieben. */
	public function testBeitragsgruppenLesenAbRevisorAendernAbBuchhalter(): void {
		$this->assertMinimumRole(ContributionGroupController::class, 'index', 'GET', PermissionService::ROLE_READ);
		foreach (['create', 'update', 'destroy'] as $method) {
			$this->assertMinimumRole(ContributionGroupController::class, $method, 'POST', PermissionService::ROLE_WRITE);
		}
	}

	/**
	 * Zuweisungen tragen die Begründung der individuellen Untergrenze und die
	 * Freitext-Vermerke der Ereignisse – Personenakte, deshalb auch Lesen erst ab
	 * `buchhalter` (Spec §3.9, Mitglieder-Unterreiter „nicht revisor"). Vorher
	 * ließ die Verb-Heuristik (GET = revisor) sie durch.
	 */
	public function testZuweisungenSindPersonenakteAlsoAuchLesenErstAbBuchhalter(): void {
		foreach (['index', 'events'] as $method) {
			$this->assertMinimumRole(AssignmentController::class, $method, 'GET', PermissionService::ROLE_WRITE);
		}
		foreach (['create', 'previewNew', 'update', 'end'] as $method) {
			$this->assertMinimumRole(AssignmentController::class, $method, 'POST', PermissionService::ROLE_WRITE);
		}
	}

	public function testOffenePostenSichtDerForderungenAbRevisor(): void {
		$this->assertMinimumRole(ClaimController::class, 'index', 'GET', PermissionService::ROLE_READ);
	}

	public function testEinstellungenLesenAbRevisorSchreibenNurAlsVerwalter(): void {
		$this->assertMinimumRole(SettingsController::class, 'index', 'GET', PermissionService::ROLE_READ);
		$this->assertMinimumRole(SettingsController::class, 'update', 'POST', PermissionService::ROLE_ADMIN);
	}

	/**
	 * Die Gegenprobe zur Konstruktion des Tests: ohne ausdrückliche Rolle ließe die
	 * Verb-Heuristik einen Buchhalter an `setLeadDays` heran. Fände dieser Test
	 * das nicht mehr, prüfte er nichts.
	 */
	public function testOhneAttributFaelltDieMiddlewareAufDieVerbHeuristikZurueck(): void {
		$controller = new class extends Controller {
			public function __construct() {
			}

			public function einstellungAendern(): void {
			}
		};

		// Kommt der Buchhalter bei POST ohne Attribut durch, ist die Heuristik das Einzige, was ihn hier gehalten hätte.
		$this->middleware(PermissionService::ROLE_WRITE, 'POST')->beforeController($controller, 'einstellungAendern');
		$this->expectException(ForbiddenException::class);
		$this->middleware(PermissionService::ROLE_READ, 'POST')->beforeController($controller, 'einstellungAendern');
	}
}
