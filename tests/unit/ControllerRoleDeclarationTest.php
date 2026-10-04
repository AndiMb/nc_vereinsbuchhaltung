<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use PHPUnit\Framework\TestCase;

/**
 * Strukturtest zu Spec §3.9 (Issue #119): „jede neue Controller-Methode trägt
 * explizit `#[RequiresRole]` – die Fail-open-Verb-Heuristik des Gefäßes wird im
 * Modul nicht genutzt."
 *
 * Warum ein Test statt Disziplin: ohne Attribut leitet die PermissionMiddleware
 * die Rolle aus dem HTTP-Verb ab (GET = lesen, alles andere = schreiben). Das
 * stimmt, bis jemand eine Einstellung als POST ergänzt und sie damit auf
 * `buchhalter` statt `verwalter` landet – nichts schlägt fehl, niemand merkt es
 * (so geschehen bei `DueDateScheduleController::setLeadDays`). Dieser Test liest
 * die Routen aus `appinfo/routes.php` und macht das Fehlen sichtbar.
 *
 * Die Grenze zwischen „Modul" und „Kern" steht unten in den Listen und nirgends
 * sonst: Der Kern der Buchhaltung (Konten, Buchungen, Berichte, Perioden …)
 * bleibt bewusst bei der Verb-Heuristik und wird hier NICHT geprüft – aber jeder
 * Controller muss eingeordnet sein, damit ein neuer nicht unbemerkt an der Prüfung
 * vorbeikommt. Wer einen Controller oder eine Methode ergänzt, bekommt bei der
 * Einordnung eine Fehlermeldung, die sagt, was zu tun ist.
 */
class ControllerRoleDeclarationTest extends TestCase {

	private const CONTROLLER_NAMESPACE = 'OCA\\Vereinsbuchhaltung\\Controller\\';

	/**
	 * Modul-Controller (Beiträge/SEPA, Epic #63): JEDE geroutete Methode trägt
	 * `#[RequiresRole]`.
	 *
	 * SettingsController ist ein Mischfall und steht hier, weil das Modul seine
	 * Einstellungen (Spec §4 „Neue Einstellungen") über ihn lesen und schreiben
	 * lässt; seine drei Methoden waren bis auf `index` ohnehin ausdrücklich
	 * eingestuft.
	 */
	private const MODULE_CONTROLLERS = [
		'AssignmentController',
		'BankReconciliationController',
		'ClaimController',
		'ContributionGroupController',
		'DebitBatchController',
		'DueDateScheduleController',
		'MandateController',
		'MandateLegalTextController',
		'MemberController',
		'MemberImportController',
		'SepaImportController',
		'SettingsController',
		'TaskController',
	];

	/**
	 * Mischcontroller: Kern-Methoden und Modul-Methoden im selben Controller.
	 * Hier wird je Methode eingeordnet – eine neue Methode steht in keiner der
	 * beiden Listen und lässt den Test scheitern, bis jemand entschieden hat.
	 *
	 * ExportController: die CSV-/Bericht-Ausgaben sind Kern (Verb-Heuristik,
	 * unverändert), die beiden Mitglieder-Auskünfte (Spec §3.7/§3.8) gehören zum
	 * Modul.
	 */
	private const MIXED_MODULE_METHODS = [
		'ExportController' => ['beitragsbescheinigung', 'beitragsbescheinigungYears', 'datenuebersicht'],
	];

	private const MIXED_CORE_METHODS = [
		'ExportController' => ['attachments', 'balances', 'budget', 'journal', 'kassenbericht', 'kurzbericht', 'multiyear', 'report'],
	];

	/**
	 * Ausnahmen mit Begründung. Ein `#[RequiresRole]` an diesen Controllern wäre
	 * wirkungslos (die Middleware überspringt die Rollenprüfung für sie) und
	 * würde Sicherheit nur vortäuschen – deshalb prüft der Test zusätzlich, dass
	 * sie KEINES tragen.
	 */
	private const EXEMPT_CONTROLLERS = [
		'SelfController' => 'Self-Service unter /api/self/* (Spec §3.4): Zugang über Kontoverknüpfung und `self_service_enabled`, keine Rolle – zentral in PermissionMiddleware::authorizeSelfService().',
		'MandateConsentController' => 'Öffentliche, login-lose Zustimmungsseite des Einmal-Links (Spec §2.2, Issue #67): die Absicherung ist der kryptographisch geprüfte Token; die Middleware überspringt den Controller.',
	];

	/**
	 * Alt-Modul (SEPA-Export/Mandate/Mitgliedsbeiträge vor der Spec), wird mit
	 * Issue #107 samt Routen entfernt. Die Einträge wirken nur, solange die
	 * Datei existiert; sind Datei und Routen weg, bleibt der Eintrag folgenlos
	 * und kann bei Gelegenheit gestrichen werden.
	 */
	private const LEGACY_CONTROLLERS = [
		'MembershipFeeController',
		'SepaBatchController',
		'SepaMandateController',
	];

	/**
	 * Kern der Buchhaltung: bleibt unverändert bei der Verb-Heuristik (gleiche
	 * Grenze wie im Issue #119). PageController, L10nController, HelpController
	 * und BrandingController::view liefern Seiten/Assets des Gefäßes.
	 *
	 * OpenItemController gehört trotz der Forderungen des Moduls (liegen in
	 * derselben Tabelle `vbh_open_items`) zum Kern: er stammt aus der
	 * Offene-Posten-Verwaltung vor dem Modul und kennt keine Modul-Methode.
	 * PermissionController: die Rolle `verwalter` setzt die Middleware per
	 * instanceof-Sonderfall durch, `me` ist für jeden angemeldeten Nutzer offen.
	 */
	private const CORE_CONTROLLERS = [
		'AccountController',
		'AttachmentController',
		'AuditController',
		'BrandingController',
		'BudgetController',
		'CostCenterController',
		'DemoController',
		'HelpController',
		'ImportController',
		'JournalController',
		'L10nController',
		'OpenItemController',
		'PageController',
		'PeriodController',
		'PermissionController',
		'ReportController',
		'RuleController',
		'SyncController',
		'TransactionController',
		'WhatsNewController',
	];

	/** Die einzigen Werte, die die Middleware kennt; alles andere schlüge dort fail-open durch (undefinierter Rang). */
	private const VALID_ROLES = [
		PermissionService::ROLE_READ,
		PermissionService::ROLE_WRITE,
		PermissionService::ROLE_ADMIN,
	];

	/**
	 * @return list<array{controller:string, method:string, verb:string, url:string}>
	 */
	private static function routes(): array {
		$config = require dirname(__DIR__, 2) . '/appinfo/routes.php';
		$routes = [];
		foreach ($config['routes'] as $route) {
			[$controller, $method] = explode('#', $route['name'], 2);
			$routes[] = [
				'controller' => ucfirst($controller) . 'Controller',
				'method' => $method,
				'verb' => $route['verb'],
				'url' => $route['url'],
			];
		}
		return $routes;
	}

	/** @return list<string> Controller-Dateien in lib/Controller/ (ohne Endung), sortiert */
	private static function controllerFiles(): array {
		$names = [];
		foreach (glob(dirname(__DIR__, 2) . '/lib/Controller/*Controller.php') ?: [] as $file) {
			$names[] = basename($file, '.php');
		}
		sort($names);
		return $names;
	}

	/** @return array<string, list<string>> */
	private static function mixedModuleMethods(): array {
		return self::MIXED_MODULE_METHODS;
	}

	/** @return array<string, list<string>> */
	private static function mixedCoreMethods(): array {
		return self::MIXED_CORE_METHODS;
	}

	private static function describe(array $route): string {
		return sprintf('%s::%s (%s %s)', $route['controller'], $route['method'], $route['verb'], $route['url']);
	}

	/** Die ausdrücklich angegebene Rolle einer Methode, null ohne Attribut. */
	private static function declaredRole(string $controller, string $method): ?string {
		$attributes = (new \ReflectionMethod(self::CONTROLLER_NAMESPACE . $controller, $method))->getAttributes(RequiresRole::class);
		return $attributes === [] ? null : $attributes[0]->newInstance()->role;
	}

	/** @return list<string> */
	private static function allKnownControllers(): array {
		$known = array_merge(
			self::MODULE_CONTROLLERS,
			array_keys(self::MIXED_MODULE_METHODS),
			array_keys(self::EXEMPT_CONTROLLERS),
			self::LEGACY_CONTROLLERS,
			self::CORE_CONTROLLERS,
		);
		sort($known);
		return $known;
	}

	public function testJederControllerIstEingeordnet(): void {
		$present = array_unique(array_merge(
			self::controllerFiles(),
			array_column(self::routes(), 'controller'),
		));
		sort($present);

		$unclassified = array_values(array_diff($present, self::allKnownControllers()));

		$this->assertSame([], $unclassified, sprintf(
			"Neuer Controller ohne Einordnung: %s.\n"
			. 'Gehört er zum Beiträge/SEPA-Modul, trage ihn in MODULE_CONTROLLERS ein (dann trägt jede Methode #[RequiresRole], Spec §3.9). '
			. 'Gehört er zum Kern der Buchhaltung, trage ihn in CORE_CONTROLLERS ein. '
			. 'Braucht er bewusst keine Rolle (Self-Service, öffentliche Seite), trage ihn mit Begründung in EXEMPT_CONTROLLERS ein.',
			implode(', ', $unclassified),
		));
	}

	public function testKeinControllerStehtInZweiListen(): void {
		$lists = [
			'MODULE_CONTROLLERS' => self::MODULE_CONTROLLERS,
			'MIXED' => array_keys(self::MIXED_MODULE_METHODS),
			'EXEMPT_CONTROLLERS' => array_keys(self::EXEMPT_CONTROLLERS),
			'LEGACY_CONTROLLERS' => self::LEGACY_CONTROLLERS,
			'CORE_CONTROLLERS' => self::CORE_CONTROLLERS,
		];
		$seen = [];
		$duplicates = [];
		foreach ($lists as $listName => $controllers) {
			foreach ($controllers as $controller) {
				if (isset($seen[$controller])) {
					$duplicates[] = sprintf('%s (%s und %s)', $controller, $seen[$controller], $listName);
				}
				$seen[$controller] = $listName;
			}
		}

		$this->assertSame([], $duplicates, 'Ein Controller gehört genau einer Liste an – Modul, Kern, Ausnahme oder Alt-Bestand.');
	}

	public function testJedeRouteZeigtAufEineVorhandeneOeffentlicheMethode(): void {
		$broken = [];
		foreach (self::routes() as $route) {
			$class = self::CONTROLLER_NAMESPACE . $route['controller'];
			if (!class_exists($class)) {
				$broken[] = self::describe($route) . ': Controller-Klasse fehlt';
				continue;
			}
			if (!method_exists($class, $route['method']) || !(new \ReflectionMethod($class, $route['method']))->isPublic()) {
				$broken[] = self::describe($route) . ': keine öffentliche Methode dieses Namens';
			}
		}

		$this->assertSame([], $broken, 'Routen in appinfo/routes.php, die ins Leere zeigen.');
	}

	public function testJedeMethodeDesModulsTraegtEineAusdruecklicheRolle(): void {
		$missing = [];
		foreach (self::routes() as $route) {
			if (!$this->belongsToModule($route['controller'], $route['method'])) {
				continue;
			}
			if (self::declaredRole($route['controller'], $route['method']) === null) {
				$missing[] = self::describe($route);
			}
		}

		$this->assertSame([], $missing, sprintf(
			"Diese Methoden des Beiträge/SEPA-Moduls tragen kein #[RequiresRole]:\n  %s\n"
			. 'Ergänze am Methodenkopf #[RequiresRole(PermissionService::ROLE_READ|ROLE_WRITE|ROLE_ADMIN)] nach Spec §3.9: '
			. 'lesen = ROLE_READ (revisor), operatives Handeln = ROLE_WRITE (buchhalter), Einstellungen und Rechtevergabe = ROLE_ADMIN (verwalter). '
			. 'Die Verb-Heuristik der PermissionMiddleware ist fail-open (ein ändernder POST landet stillschweigend bei buchhalter) und wird im Modul nicht genutzt. '
			. 'Lese-Endpunkte, die IBAN oder Rücklastschrift-Codes ausliefern, brauchen ROLE_WRITE oder eine Maskierung nach Rolle.',
			implode("\n  ", $missing),
		));
	}

	public function testMischControllerSindMethodenweiseEingeordnet(): void {
		$unclassified = [];
		foreach (self::routes() as $route) {
			$controller = $route['controller'];
			if (!isset(self::mixedModuleMethods()[$controller])) {
				continue;
			}
			$method = $route['method'];
			if (!in_array($method, self::mixedModuleMethods()[$controller], true)
				&& !in_array($method, self::mixedCoreMethods()[$controller] ?? [], true)) {
				$unclassified[] = self::describe($route);
			}
		}

		$this->assertSame([], $unclassified, sprintf(
			"Neue Methode in einem gemischten Controller:\n  %s\n"
			. 'Gehört sie zum Beiträge/SEPA-Modul, trage sie in MIXED_MODULE_METHODS ein und versieh sie mit #[RequiresRole]. '
			. 'Gehört sie zum Kern der Buchhaltung, trage sie in MIXED_CORE_METHODS ein.',
			implode("\n  ", $unclassified),
		));
	}

	public function testDieListenDerMischControllerEnthaltenKeineToten(): void {
		$routed = [];
		foreach (self::routes() as $route) {
			$routed[$route['controller']][] = $route['method'];
		}
		$dead = [];
		foreach ([self::MIXED_MODULE_METHODS, self::MIXED_CORE_METHODS] as $list) {
			foreach ($list as $controller => $methods) {
				foreach ($methods as $method) {
					if (!in_array($method, $routed[$controller] ?? [], true)) {
						$dead[] = $controller . '::' . $method;
					}
				}
			}
		}

		$this->assertSame([], $dead, 'Eingetragene Methoden ohne Route – Eintrag entfernen oder Route prüfen.');
	}

	public function testJederRollenwertIstEineBekannteRolle(): void {
		$invalid = [];
		foreach (self::routes() as $route) {
			$class = self::CONTROLLER_NAMESPACE . $route['controller'];
			if (!class_exists($class) || !method_exists($class, $route['method'])) {
				continue;
			}
			$attributes = (new \ReflectionMethod($class, $route['method']))->getAttributes(RequiresRole::class);
			if (count($attributes) > 1) {
				$invalid[] = self::describe($route) . ': mehr als ein #[RequiresRole]';
			}
			foreach ($attributes as $attribute) {
				$role = $attribute->newInstance()->role;
				if (!in_array($role, self::VALID_ROLES, true)) {
					$invalid[] = self::describe($route) . ': unbekannte Rolle „' . $role . '“';
				}
			}
		}

		$this->assertSame([], $invalid, sprintf(
			'Erlaubt sind PermissionService::ROLE_READ, ROLE_WRITE und ROLE_ADMIN. Ein anderer Wert (auch ROLE_NONE oder ein Tippfehler) hat in PermissionMiddleware keinen Rang und ließe jeden durch (%s).',
			implode(', ', self::VALID_ROLES),
		));
	}

	public function testAusgenommeneControllerTragenKeineWirkungsloseRolle(): void {
		$misleading = [];
		foreach (self::routes() as $route) {
			if (!isset(self::EXEMPT_CONTROLLERS[$route['controller']])) {
				continue;
			}
			if (self::declaredRole($route['controller'], $route['method']) !== null) {
				$misleading[] = self::describe($route);
			}
		}

		$this->assertSame([], $misleading, 'Die PermissionMiddleware überspringt diese Controller bzw. prüft dort etwas anderes als eine Rolle – ein #[RequiresRole] würde Schutz nur vortäuschen.');
	}

	public function testDieAusnahmenPassenZuIhrerBegruendung(): void {
		// Die Ausnahmen sind nur dann „namentlich und begründet", wenn die Begründung zum Code passt:
		// der Self-Service-Controller hängt ausschließlich an /api/self/*, die Zustimmungsseite liegt
		// bewusst außerhalb von /api/.
		foreach (self::routes() as $route) {
			if ($route['controller'] === 'SelfController') {
				$this->assertStringStartsWith('/api/self/', $route['url'], self::describe($route));
			}
			if ($route['controller'] === 'MandateConsentController') {
				$this->assertStringStartsWith('/mandate-consent/', $route['url'], self::describe($route));
			}
		}
		foreach (array_keys(self::EXEMPT_CONTROLLERS) as $controller) {
			$this->assertNotSame('', self::EXEMPT_CONTROLLERS[$controller], $controller . ' braucht eine Begründung');
		}
	}

	/**
	 * Gilt für Methoden, die diese Prüfung betrifft: Modul-Controller, Modul-Methoden
	 * gemischter Controller. Der Alt-Bestand (Issue #107) und alles andere nicht.
	 */
	private function belongsToModule(string $controller, string $method): bool {
		if (in_array($controller, self::MODULE_CONTROLLERS, true)) {
			return true;
		}
		return in_array($method, self::mixedModuleMethods()[$controller] ?? [], true);
	}
}
