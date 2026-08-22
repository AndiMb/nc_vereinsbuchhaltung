<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\MembershipFeeMapper;
use OCA\Vereinsbuchhaltung\Db\SepaMandateMapper;
use OCA\Vereinsbuchhaltung\Service\BillingPeriod;
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

	/**
	 * Der vollständige, aktuell gespeicherte Einstellungssatz. Gemeinsame
	 * Grundlage für index() und die Antwort von update(): zwei getrennte
	 * Formulare (Einstellungsseite, Kostenstellen-Modus in ReportsTab)
	 * schreiben seit der Aufteilung in Nextcloud-Einstellungen jeweils nur
	 * ihre eigenen Felder (siehe update()), sollen aber beide denselben
	 * vollständigen Satz zurückbekommen.
	 *
	 * @return array<string,mixed>
	 */
	private function currentSettings(): array {
		$membershipEnabled = $this->config->getAppValue(Application::APP_ID, 'membership_enabled', '0') === '1';
		$defaultFeeAmountCents = $this->config->getAppValue(Application::APP_ID, 'default_fee_amount_cents', '');
		return [
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
			// Vorbelegung fuer "Mitglied aufnehmen" und den CSV-Import, siehe
			// SettingsSepaBasics.vue ("Standard-Beitrag").
			'default_fee_amount' => $defaultFeeAmountCents !== '' ? ((int)$defaultFeeAmountCents) / 100 : null,
			'default_fee_frequency' => $this->config->getAppValue(Application::APP_ID, 'default_fee_frequency', 'yearly'),
			'membership_enabled' => $membershipEnabled,
			// Steuert den Reiter „Beiträge": auch ohne den Schalter sichtbar,
			// sobald bereits Mandate oder Beiträge bestehen – siehe
			// NAVIGATION-KONZEPT.md Abschnitt 4. Keine Migration noetig, die
			// bestehende Installationen zeigen den Reiter dadurch sofort.
			'membership_active' => $membershipEnabled
				|| $this->sepaMandateMapper->count() > 0
				|| $this->membershipFeeMapper->count() > 0,
		];
	}

	#[NoAdminRequired]
	public function index(): DataResponse {
		return new DataResponse($this->currentSettings());
	}

	/**
	 * Schreibt nur die Schlüssel, die tatsächlich im Request stehen - die
	 * Einstellungsseite (elf Felder) und der Kostenstellen-Modus in
	 * ReportsTab (ein Feld) teilen sich diesen Endpunkt, seit sie nicht mehr
	 * im selben Formular stehen. Ohne diese Unterscheidung würde das jeweils
	 * andere Formular mit einem veralteten Schnappschuss überschrieben.
	 */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function update(): DataResponse {
		if (PermissionService::RANK[$this->permissionService->getRole()] < PermissionService::RANK[PermissionService::ROLE_ADMIN]) {
			return new DataResponse(['message' => $this->l10n->t('Zugriff verweigert')], Http::STATUS_FORBIDDEN);
		}

		$params = $this->request->getParams();
		$appId = Application::APP_ID;

		// Belegablage (Paar): nur anfassen, wenn mindestens eine Hälfte
		// gesendet wurde; die fehlende Hälfte wird aus dem aktuellen Stand
		// ergänzt, damit die Paarprüfung (Nutzer + Pfad) vollständig bleibt.
		if (array_key_exists('storage_user', $params) || array_key_exists('storage_path', $params)) {
			$storageUser = trim((string)($params['storage_user'] ?? $this->config->getAppValue($appId, 'storage_user', '')));
			$storagePath = trim((string)($params['storage_path'] ?? $this->config->getAppValue($appId, 'storage_path', 'Vereinsbuchhaltung/Belege')));
			$storageError = $this->validateStorage($storageUser, $storagePath);
			if ($storageError !== null) {
				return new DataResponse(['message' => $storageError], Http::STATUS_BAD_REQUEST);
			}
			$storagePath = trim(str_replace('\\', '/', $storagePath), '/');
			if ($storagePath === '') {
				$storagePath = 'Vereinsbuchhaltung/Belege';
			}
			$this->config->setAppValue($appId, 'storage_user', $storageUser);
			$this->config->setAppValue($appId, 'storage_path', $storagePath);
		}

		if (array_key_exists('cost_center_mode', $params)) {
			$ccMode = (string)($params['cost_center_mode'] ?? 'group');
			if (!in_array($ccMode, ReportService::MODES, true)) {
				$ccMode = 'group';
			}
			$this->config->setAppValue($appId, 'cost_center_mode', $ccMode);
		}

		if (array_key_exists('club_name', $params)) {
			$clubName = mb_substr(trim((string)$params['club_name']), 0, 128);
			$this->config->setAppValue($appId, 'club_name', $clubName);
		}

		if (array_key_exists('brand_color', $params)) {
			$brandColor = trim((string)$params['brand_color']);
			if ($brandColor !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $brandColor)) {
				return new DataResponse(['message' => $this->l10n->t('Ungültige Akzentfarbe (Format #RRGGBB erwartet)')], Http::STATUS_BAD_REQUEST);
			}
			$this->config->setAppValue($appId, 'brand_color', $brandColor);
		}

		// Wachordner (Paar): dieselbe Ergänzungslogik wie bei der Belegablage.
		if (array_key_exists('statement_watch_user', $params) || array_key_exists('statement_watch_path', $params)) {
			$watchUser = trim((string)($params['statement_watch_user'] ?? $this->config->getAppValue($appId, WatchFolderService::SETTING_USER, '')));
			$watchPath = trim(str_replace('\\', '/', (string)($params['statement_watch_path'] ?? $this->config->getAppValue($appId, WatchFolderService::SETTING_PATH, ''))), '/');
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
			$this->config->setAppValue($appId, WatchFolderService::SETTING_USER, $watchUser);
			$this->config->setAppValue($appId, WatchFolderService::SETTING_PATH, $watchPath);
		}

		if (array_key_exists('sepa_creditor_id', $params)) {
			$creditorId = strtoupper(trim((string)$params['sepa_creditor_id']));
			if ($creditorId !== '' && !preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{1,28}$/', $creditorId)) {
				return new DataResponse(['message' => $this->l10n->t('Das sieht nicht nach einer SEPA-Gläubiger-ID aus (erwartet wird z. B. DE98ZZZ09999999999).')], Http::STATUS_BAD_REQUEST);
			}
			$this->config->setAppValue($appId, 'sepa_creditor_id', $creditorId);
		}

		if (array_key_exists('default_fee_amount', $params)) {
			$defaultFeeAmountParam = trim((string)$params['default_fee_amount']);
			$defaultFeeAmountCents = '';
			if ($defaultFeeAmountParam !== '') {
				$defaultFeeAmount = (float)str_replace(',', '.', $defaultFeeAmountParam);
				if ($defaultFeeAmount <= 0) {
					return new DataResponse(['message' => $this->l10n->t('Der Standard-Beitrag muss größer als 0 sein.')], Http::STATUS_BAD_REQUEST);
				}
				$defaultFeeAmountCents = (string)(int)round($defaultFeeAmount * 100);
			}
			$this->config->setAppValue($appId, 'default_fee_amount_cents', $defaultFeeAmountCents);
		}

		if (array_key_exists('default_fee_frequency', $params)) {
			$defaultFeeFrequency = (string)$params['default_fee_frequency'];
			if (!isset(BillingPeriod::FREQUENCY_MONTHS[$defaultFeeFrequency])) {
				$defaultFeeFrequency = 'yearly';
			}
			$this->config->setAppValue($appId, 'default_fee_frequency', $defaultFeeFrequency);
		}

		if (array_key_exists('sepa_debtor_account_id', $params)) {
			$debtorAccountParam = $params['sepa_debtor_account_id'];
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
			$this->config->setAppValue($appId, 'sepa_debtor_account_id', (string)($debtorAccountId ?? ''));
		}

		if (array_key_exists('membership_enabled', $params)) {
			$membershipEnabled = (string)$params['membership_enabled'] === '1';
			$this->config->setAppValue($appId, 'membership_enabled', $membershipEnabled ? '1' : '0');
		}

		return new DataResponse($this->currentSettings());
	}
}
