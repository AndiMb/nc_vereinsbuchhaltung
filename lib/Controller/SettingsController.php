<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\MembershipFeeMapper;
use OCA\Vereinsbuchhaltung\Db\SepaMandateMapper;
use OCA\Vereinsbuchhaltung\Service\DemoDataService;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCA\Vereinsbuchhaltung\Service\ReportService;
use OCA\Vereinsbuchhaltung\Service\WatchFolderService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;

class SettingsController extends Controller {

	use BookContext;

	public function __construct(
		IRequest $request,
		private IConfig $config,
		private PermissionService $permissionService,
		private DemoDataService $demoService,
		private AccountMapper $accountMapper,
		private SepaMandateMapper $sepaMandateMapper,
		private MembershipFeeMapper $membershipFeeMapper,
		private IUserManager $userManager,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Prüft den Zielort der Belegablage.
	 *
	 * Beides muss eng geführt werden: die App legt in dem konfigurierten Ordner
	 * Dateien im Home eines echten Nextcloud-Nutzers an und räumt dort beim
	 * Zurücksetzen wieder auf. Ohne Prüfung könnte ein Verwalter der App – der
	 * kein Nextcloud-Administrator sein muss – einen beliebigen fremden Nutzer
	 * und einen beliebigen Pfad eintragen und so in dessen Dateien schreiben.
	 * (Gelöscht werden beim Reset nur noch die bekannten Beleg-Dateien, siehe
	 * AttachmentStorageService::deleteAllFiles() – die Pfadprüfung bleibt
	 * trotzdem die erste Verteidigungslinie.)
	 *
	 * @return string|null Fehlermeldung oder null, wenn alles in Ordnung ist
	 */
	private function validateStorage(string $storageUser, string $storagePath): ?string {
		return $this->validateUserPath($storageUser, $storagePath, $this->l10n->t('Belegablage'), $this->l10n->t('Ablagepfad'));
	}

	/**
	 * Dieselbe Prüfung für den überwachten Ordner der Kontoauszüge.
	 *
	 * Hier wiegt sie sogar schwerer als bei der Belegablage: die App liest aus
	 * diesem Ordner und verschiebt die Dateien anschließend. Ein beliebiger
	 * fremder Pfad wäre also nicht nur beschreibbar, sondern auch auslesbar.
	 */
	private function validateWatchFolder(string $user, string $path): ?string {
		if ($user === '' || trim($path) === '') {
			return null; // beides leer bzw. unvollständig -> Wachordner ist aus
		}
		return $this->validateUserPath($user, $path, $this->l10n->t('überwachten Ordner'), $this->l10n->t('Ordnerpfad'));
	}

	/**
	 * @param string $subject wofür der Nutzer gebraucht wird (für die Meldung)
	 * @param string $pathLabel wie der Pfad in Meldungen heißen soll
	 * @return string|null Fehlermeldung oder null, wenn alles in Ordnung ist
	 */
	private function validateUserPath(string $user, string $path, string $subject, string $pathLabel): ?string {
		if ($user !== '' && !$this->userManager->userExists($user)) {
			return $this->l10n->t('Der angegebene Nextcloud-Nutzer für die %s existiert nicht.', [$subject]);
		}

		$normalized = trim(str_replace('\\', '/', $path), '/');
		if ($normalized === '') {
			return null; // leer -> Standardpfad, wird vom Aufrufer gesetzt
		}
		foreach (explode('/', $normalized) as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..') {
				return $this->l10n->t('Ungültiger %s: "." und ".." sind nicht erlaubt.', [$pathLabel]);
			}
		}
		// Nextcloud verbietet diese Zeichen in Dateinamen; ein Pfad damit wäre
		// nicht anlegbar und der Fehler erst beim ersten Beleg-Upload sichtbar.
		if (preg_match('/[\\\\:*?"<>|]/', $normalized) === 1) {
			return $this->l10n->t('Ungültiger %s: enthält unzulässige Zeichen.', [$pathLabel]);
		}
		if (mb_strlen($normalized) > 200) {
			return $this->l10n->t('Der %s ist zu lang (max. 200 Zeichen).', [$pathLabel]);
		}
		return null;
	}

	#[NoAdminRequired]
	public function index(): DataResponse {
		$membershipEnabled = $this->config->getAppValue(Application::APP_ID, 'membership_enabled', '0') === '1';
		return new DataResponse([
			'storage_user' => $this->config->getAppValue(Application::APP_ID, 'storage_user', ''),
			'storage_path' => $this->config->getAppValue(Application::APP_ID, 'storage_path', 'Vereinsbuchhaltung/Belege'),
			'cost_center_mode' => $this->config->getAppValue(Application::APP_ID, 'cost_center_mode', 'group'),
			'club_name' => $this->config->getAppValue(Application::APP_ID, 'club_name', ''),
			'brand_color' => $this->config->getAppValue(Application::APP_ID, 'brand_color', ''),
			'has_logo' => $this->config->getAppValue(Application::APP_ID, 'brand_logo_mime', '') !== '',
			'demo_active' => $this->demoService->isActive(),
			'statement_watch_user' => $this->config->getAppValue(Application::APP_ID, WatchFolderService::SETTING_USER, ''),
			'statement_watch_path' => $this->config->getAppValue(Application::APP_ID, WatchFolderService::SETTING_PATH, ''),
			'sepa_creditor_id' => $this->config->getAppValue(Application::APP_ID, 'sepa_creditor_id', ''),
			'sepa_debtor_account_id' => (int)$this->config->getAppValue(Application::APP_ID, 'sepa_debtor_account_id', '0') ?: null,
			'membership_enabled' => $membershipEnabled,
			// Steuert den Reiter „Beiträge": auch ohne den Schalter sichtbar,
			// sobald bereits Mandate oder Beiträge bestehen – siehe
			// NAVIGATION-KONZEPT.md Abschnitt 4. Keine Migration noetig, die
			// bestehende Installationen zeigen den Reiter dadurch sofort.
			'membership_active' => $membershipEnabled
				|| $this->sepaMandateMapper->count() > 0
				|| $this->membershipFeeMapper->count() > 0,
		]);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function update(): DataResponse {
		if (PermissionService::RANK[$this->permissionService->getRole()] < PermissionService::RANK[PermissionService::ROLE_ADMIN]) {
			return new DataResponse(['message' => $this->l10n->t('Zugriff verweigert')], Http::STATUS_FORBIDDEN);
		}

		$storageUser = trim((string)($this->request->getParam('storage_user') ?? ''));
		$storagePath = trim((string)($this->request->getParam('storage_path') ?? ''));
		$storageError = $this->validateStorage($storageUser, $storagePath);
		if ($storageError !== null) {
			return new DataResponse(['message' => $storageError], Http::STATUS_BAD_REQUEST);
		}
		$storagePath = trim(str_replace('\\', '/', $storagePath), '/');
		if ($storagePath === '') {
			$storagePath = 'Vereinsbuchhaltung/Belege';
		}
		$ccMode = (string)($this->request->getParam('cost_center_mode') ?? 'group');
		if (!in_array($ccMode, ReportService::MODES, true)) {
			$ccMode = 'group';
		}
		$clubName = mb_substr(trim((string)($this->request->getParam('club_name') ?? '')), 0, 128);
		$brandColor = trim((string)($this->request->getParam('brand_color') ?? ''));
		if ($brandColor !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $brandColor)) {
			return new DataResponse(['message' => $this->l10n->t('Ungültige Akzentfarbe (Format #RRGGBB erwartet)')], Http::STATUS_BAD_REQUEST);
		}

		$watchUser = trim((string)($this->request->getParam('statement_watch_user') ?? ''));
		$watchPath = trim(str_replace('\\', '/', (string)($this->request->getParam('statement_watch_path') ?? '')), '/');
		$watchError = $this->validateWatchFolder($watchUser, $watchPath);
		if ($watchError !== null) {
			return new DataResponse(['message' => $watchError], Http::STATUS_BAD_REQUEST);
		}
		// Nur beides zusammen ergibt einen Wachordner; halb ausgefüllt wäre er
		// eingeschaltet, fände aber nie etwas.
		if ($watchUser === '' || $watchPath === '') {
			$watchUser = '';
			$watchPath = '';
		}

		$creditorId = strtoupper(trim((string)($this->request->getParam('sepa_creditor_id') ?? '')));
		if ($creditorId !== '' && !preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{1,28}$/', $creditorId)) {
			return new DataResponse(['message' => $this->l10n->t('Das sieht nicht nach einer SEPA-Gläubiger-ID aus (erwartet wird z. B. DE98ZZZ09999999999).')], Http::STATUS_BAD_REQUEST);
		}

		$debtorAccountParam = $this->request->getParam('sepa_debtor_account_id');
		$debtorAccountId = $debtorAccountParam !== null && $debtorAccountParam !== '' ? (int)$debtorAccountParam : null;
		if ($debtorAccountId !== null) {
			try {
				$account = $this->accountMapper->find($debtorAccountId, $this->userId());
				if (!$account->getIsBank()) {
					return new DataResponse(['message' => $this->l10n->t('Das einziehende Konto muss ein Geldkonto sein.')], Http::STATUS_BAD_REQUEST);
				}
				// Gleich hier prüfen und nicht erst beim Erzeugen des Einzugs: die
				// fehlende IBAN fiele sonst erst auf, wenn es eilig ist.
				if ($account->getIban() === null) {
					return new DataResponse(['message' => $this->l10n->t('Für das einziehende Konto ist keine IBAN hinterlegt. Bitte tragen Sie sie zuerst am Konto ein.')], Http::STATUS_BAD_REQUEST);
				}
			} catch (DoesNotExistException) {
				return new DataResponse(['message' => $this->l10n->t('Das gewählte einziehende Konto wurde nicht gefunden.')], Http::STATUS_BAD_REQUEST);
			}
		}

		$this->config->setAppValue(Application::APP_ID, 'storage_user', $storageUser);
		$this->config->setAppValue(Application::APP_ID, 'storage_path', $storagePath);
		$this->config->setAppValue(Application::APP_ID, 'cost_center_mode', $ccMode);
		$this->config->setAppValue(Application::APP_ID, 'club_name', $clubName);
		$this->config->setAppValue(Application::APP_ID, 'brand_color', $brandColor);
		$this->config->setAppValue(Application::APP_ID, WatchFolderService::SETTING_USER, $watchUser);
		$this->config->setAppValue(Application::APP_ID, WatchFolderService::SETTING_PATH, $watchPath);
		$this->config->setAppValue(Application::APP_ID, 'sepa_creditor_id', $creditorId);
		$this->config->setAppValue(Application::APP_ID, 'sepa_debtor_account_id', (string)($debtorAccountId ?? ''));
		$membershipEnabled = (string)($this->request->getParam('membership_enabled') ?? '') === '1';
		$this->config->setAppValue(Application::APP_ID, 'membership_enabled', $membershipEnabled ? '1' : '0');

		return new DataResponse([
			'storage_user' => $storageUser,
			'storage_path' => $storagePath,
			'cost_center_mode' => $ccMode,
			'club_name' => $clubName,
			'brand_color' => $brandColor,
			'statement_watch_user' => $watchUser,
			'statement_watch_path' => $watchPath,
			'sepa_creditor_id' => $creditorId,
			'sepa_debtor_account_id' => $debtorAccountId,
			'membership_enabled' => $membershipEnabled,
			'membership_active' => $membershipEnabled
				|| $this->sepaMandateMapper->count() > 0
				|| $this->membershipFeeMapper->count() > 0,
		]);
	}
}
