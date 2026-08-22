<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Liefert der Frontend-Splash-Anzeige (WhatsNewDialog.vue) die aktuelle
 * App-Version und merkt sich pro Nutzer, welche Version zuletzt gesehen
 * wurde. Bewusst der erste Pro-Nutzer-Konfigurationswert dieser App
 * (IConfig::setUserValue statt setAppValue) - bei einem gemeinsamen
 * Vereinskonto mit mehreren Rollen soll der Hinweis pro Person erscheinen,
 * nicht pro Browser/Gerät.
 *
 * Beide Methoden brauchen ein ausdrückliches RequiresRole(ROLE_READ): ohne
 * das Attribut würde die Verb-Heuristik der PermissionMiddleware den
 * POST-Endpunkt als "schreibend" einstufen und Revisoren (nur Lesezugriff)
 * vom Wegklicken des Splash-Screens aussperren.
 */
class WhatsNewController extends Controller {

	private const CONFIG_KEY = 'whatsnew_last_seen_version';

	public function __construct(
		IRequest $request,
		private IConfig $config,
		private IAppManager $appManager,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function index(): DataResponse {
		$uid = $this->userSession->getUser()?->getUID();
		return new DataResponse([
			'currentVersion' => $this->appManager->getAppVersion(Application::APP_ID),
			'lastSeenVersion' => $uid !== null
				? $this->config->getUserValue($uid, Application::APP_ID, self::CONFIG_KEY, '')
				: '',
		]);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function markSeen(): DataResponse {
		$uid = $this->userSession->getUser()?->getUID();
		$version = trim((string)$this->request->getParam('version', ''));
		if ($uid !== null && preg_match('/^\d+\.\d+\.\d+$/', $version) === 1) {
			$this->config->setUserValue($uid, Application::APP_ID, self::CONFIG_KEY, $version);
		}
		return new DataResponse([]);
	}
}
