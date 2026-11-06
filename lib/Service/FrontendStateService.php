<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\BudgetMapper;
use OCA\Vereinsbuchhaltung\Db\BudgetSnapshotMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCP\IAppConfig;

/**
 * Die drei Antworten, mit denen die Oberfläche startet: eigene Rolle (`me`),
 * Geschäftsjahre (`periods`) und Einstellungen (`settings`).
 *
 * Bis Issue #264 standen sie nur in PermissionController::me(),
 * PeriodController::index() und SettingsController::index(); die Oberfläche
 * fragte sie nach dem Laden nacheinander ab. Jetzt legt der PageController sie
 * zusätzlich als Initial State in die Seite. Damit beide Wege garantiert
 * dasselbe liefern, steht der Inhalt nur hier, und die Controller reichen ihn
 * durch.
 *
 * Wer was davon sehen darf, entscheidet weiterhin der Aufrufer: die Endpunkte
 * über die PermissionMiddleware, die Seite über PermissionService::canRead().
 */
class FrontendStateService {

	/**
	 * Darstellung der Beträge in Übersicht, „Alle Buchungen" und „Zuzuordnen":
	 * 'plain' zeigt Buchungsbeträge neutral, wie der Buchungssatz sie kennt
	 * (ohne Vorzeichen und Farbe), Bankumsätze wie bisher mit dem Vorzeichen
	 * der Bank und rotem Abgang. 'signed' macht Einnahmen und Ausgaben überall
	 * mit Vorzeichen und Farbe kenntlich. Ab Werk 'plain' – Buchungssätze haben
	 * kein Vorzeichen, die Richtung ergibt sich aus Soll und Haben.
	 */
	public const AMOUNT_DISPLAYS = ['plain', 'signed'];

	public function __construct(
		private PermissionService $permissions,
		private SelfServiceService $selfService,
		private ReimbursementAccess $reimbursementAccess,
		private PeriodService $periods,
		private JournalMapper $journalMapper,
		private BudgetMapper $budgetMapper,
		private BudgetSnapshotMapper $snapshotMapper,
		private IAppConfig $config,
		private DemoDataService $demoService,
		private MemberMapper $memberMapper,
		private SepaDebtorAccountService $sepaDebtorAccount,
		private AttachmentStorageService $attachmentStorage,
		private MandateDocumentService $mandateDocuments,
		private ContributionYearService $contributionYear,
		private DunningSettings $dunningSettings,
		private MandateExpirySettings $mandateExpirySettings,
		private InvoiceSettings $invoiceSettings,
		private ReimbursementSettings $reimbursementSettings,
	) {
	}

	/**
	 * Eigene Rolle/Rechte.
	 *
	 * `selfService` steuert zusätzlich, ob die SPA den Bereich „Mein Beitrag"
	 * zeigt (Spec §3.4) – unabhängig von der vbh-Rolle, siehe App.vue.
	 *
	 * @return array<string,mixed>
	 */
	public function me(): array {
		$data = $this->permissions->describeCurrent();
		$data['selfService'] = $this->selfService->describeCurrent();
		// Auslagenerstattung: steuert den Unterreiter „Auslagen“ unter „Mein Bereich“ und den Reiter
		// in der Verwaltungssicht, siehe ReimbursementAccess.
		$data['reimbursement'] = $this->reimbursementAccess->describeCurrent();
		// Zeitzone des Vereins (#263): die Oberfläche rechnet „heute“ darin.
		// Hier statt nur in settings(), weil Konten ohne Leserolle („Mein
		// Bereich“) keinen Einstellungssatz bekommen.
		$data['clubTimeZone'] = ClubClock::zone()->getName();
		return $data;
	}

	/**
	 * Alle Geschäftsjahre, neueste zuerst, mit der Zahl ihrer Buchungen und
	 * Planwerte.
	 *
	 * Der laufende Zeitraum wird dabei angelegt, falls er noch fehlt: sonst
	 * stünde er nicht im Auswahlfeld der Kopfzeile, und eine frisch
	 * eingerichtete App hätte gar keinen. Bis 0.32.0 erledigte das der
	 * unbedingt angehängte `date('Y')` in der Jahresliste.
	 *
	 * Ja, das schreibt beim Lesen, und auch ein Nutzer mit reinem Leserecht
	 * löst es aus. Bewusst so: die Zeile trägt keine Daten, sie ist genau die,
	 * die die eingestellte Regel für heute vorsieht, und sie entsteht höchstens
	 * einmal je Geschäftsjahr. Die Alternative wäre ein Auswahleintrag ohne ID,
	 * den kein Endpunkt annehmen könnte – oder ein Auswahlfeld, in dem am
	 * 1. Oktober das neue Geschäftsjahr fehlt, bis jemand die erste Buchung
	 * anlegt.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function periods(): array {
		$userId = Application::BOOK;
		$this->periods->current($userId);

		$bookings = $this->journalMapper->countsByPeriod($userId);
		$budgets = $this->budgetMapper->countsByPeriod($userId);
		$snapshots = $this->snapshotMapper->countsByPeriod($userId);

		$rows = [];
		foreach ($this->periods->all($userId) as $period) {
			$id = (int)$period->getId();
			$rows[] = $period->jsonSerialize() + [
				'bookings' => $bookings[$id] ?? 0,
				'planValues' => ($budgets[$id] ?? 0) + ($snapshots[$id] ?? 0),
			];
		}
		return $rows;
	}

	/**
	 * Der vollständige, aktuell gespeicherte Einstellungssatz. Gemeinsame
	 * Grundlage für SettingsController::index(), die Antwort von
	 * SettingsController::update() und den Initial State der Seite: zwei
	 * getrennte Formulare (Einstellungsseite, Kostenstellen-Modus in
	 * ReportsTab) schreiben seit der Aufteilung in Nextcloud-Einstellungen
	 * jeweils nur ihre eigenen Felder (siehe update()), sollen aber beide
	 * denselben vollständigen Satz zurückbekommen.
	 *
	 * @return array<string,mixed>
	 */
	public function settings(): array {
		// Eine ausdrückliche Einstellung gewinnt; nur solange der Schalter nie
		// gesetzt wurde, entscheidet das Vorhandensein von Mitgliedern.
		// Mandate und Zuweisungen hängen immer an einem Mitglied (member_id ist
		// Pflicht), ein Mitglied genügt deshalb als Kriterium; bestehende
		// Installationen zeigen den Reiter dadurch ohne Migration sofort
		// (siehe NAVIGATION-KONZEPT.md Abschnitt 4).
		$stored = $this->config->getValueString(Application::APP_ID, 'membership_enabled', '');
		$membershipEnabled = $stored === '' ? $this->memberMapper->count() > 0 : $stored === '1';
		return [
			'storage_mode' => $this->attachmentStorage->mode(),
			'storage_user' => $this->config->getValueString(Application::APP_ID, AttachmentStorageService::SETTING_USER, ''),
			'storage_path' => $this->config->getValueString(Application::APP_ID, AttachmentStorageService::SETTING_PATH, AttachmentStorageService::DEFAULT_PATH),
			'cost_center_mode' => $this->config->getValueString(Application::APP_ID, 'cost_center_mode', 'group'),
			'amount_display' => $this->amountDisplay(),
			'club_name' => $this->config->getValueString(Application::APP_ID, 'club_name', ''),
			// Nur lesbar: Zeitzone des Vereins (Server-Zeitzone, sonst Europe/Berlin, #263).
			'club_time_zone' => ClubClock::zone()->getName(),
			'brand_color' => $this->config->getValueString(Application::APP_ID, 'brand_color', ''),
			'has_logo' => $this->config->getValueString(Application::APP_ID, 'brand_logo_mime', '') !== '',
			'demo_active' => $this->demoService->isActive(),
			'statement_watch_user' => $this->config->getValueString(Application::APP_ID, WatchFolderService::SETTING_USER, ''),
			'statement_watch_path' => $this->config->getValueString(Application::APP_ID, WatchFolderService::SETTING_PATH, ''),
			'sepa_creditor_id' => $this->config->getValueString(Application::APP_ID, 'sepa_creditor_id', ''),
			'sepa_debtor_account_id' => $this->sepaDebtorAccount->getAccountId(),
			'membership_enabled' => $membershipEnabled,
			'membership_active' => $membershipEnabled,
			// Mandats-Lifecycle (Issue #66, Spec §4 „Neue Einstellungen"):
			'mandate_reference_prefix' => $this->config->getValueString(Application::APP_ID, MandateService::SETTING_REFERENCE_PREFIX, MandateReferenceGenerator::DEFAULT_PREFIX),
			'mandate_document_folder' => $this->mandateDocuments->folderPath(),
			'show_missing_document_warning' => $this->mandateDocuments->showMissingDocumentWarning(),
			// Ablauf-Vorwarnung (Spec §4 expiry_warning_days) und Mahnabstand
			// (Spec §3.6, Issue #73): bis dahin nur Konstante bzw. ohne
			// Schreibweg, jetzt in der Einstellungsseite pflegbar (Issue #101).
			'expiry_warning_days' => $this->mandateExpirySettings->warningDays(),
			'dunning_interval_days' => $this->dunningSettings->intervalDays(),
			// Beitragsjahr (Issue #68, Spec §3.3/§4): eigenständig vom
			// Geschäftsjahr der Kern-Buchhaltung, siehe ContributionYearService.
			'fiscal_year_start_month' => $this->contributionYear->getStartMonth(),
			// Self-Service-Zugang (Spec §3.4): einfacher Bool-Schalter, nur ab
			// Verwalter änderbar (siehe update(), RequiresRole ROLE_ADMIN).
			'self_service_enabled' => $this->config->getValueString(Application::APP_ID, SelfServiceService::SETTING_ENABLED, '0') === '1',
			// Zusatzschalter, siehe SelfServiceService::canSwitchToTransfer(). Standard aus.
			'self_service_transfer_switch_enabled' => $this->config->getValueString(Application::APP_ID, SelfServiceService::SETTING_TRANSFER_SWITCH, '0') === '1',
			// Auslagenerstattung (docs/reimbursement-design.md §2): Schalter, nötige Freigaben N
			// und die Zahl der Verwalter, die zur Wahl von N passen muss (N+1).
			'reimbursement_enabled' => $this->reimbursementSettings->isEnabled(),
			'reimbursement_required_approvals' => $this->reimbursementSettings->requiredApprovals(),
		] + $this->invoiceSettings->all();
	}

	/** Gespeicherte Betragsdarstellung; ein unbekannter Wert gilt als Standard. */
	private function amountDisplay(): string {
		$display = $this->config->getValueString(Application::APP_ID, 'amount_display', 'plain');
		return in_array($display, self::AMOUNT_DISPLAYS, true) ? $display : 'plain';
	}
}
