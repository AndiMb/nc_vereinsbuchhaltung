<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCP\IConfig;
use OCP\IL10N;

/**
 * Einstellungen des Rechnungsmoduls: Angaben des Ausstellers (Verein) und
 * Vorgaben für neue Rechnungen. Eine Klasse für alle Schlüssel, damit die
 * Prüfregeln an einer Stelle stehen und Tests nicht den ganzen
 * SettingsController brauchen (Muster {@see DunningSettings}).
 *
 * Der Vereinsname liegt weiter unter `club_name` (Branding, Mandate, Mails).
 * Das Geldkonto für Zahlungsangaben und GiroCode ist eine Konto-ID
 * (`invoice_account_id`); ohne eigene Wahl gilt das einziehende Konto des
 * SEPA-Einzugs. Die Prüfung, dass es ein Geldkonto mit IBAN ist, macht der
 * SettingsController, der die Konten kennt.
 */
class InvoiceSettings {

	public const SETTING_ACTIVE = 'invoice_module_active';
	public const SETTING_AUTO_ASSIGN = 'invoice_auto_assign';
	public const SETTING_TAX_MODE = 'invoice_tax_mode';
	public const SETTING_EXEMPTION_TEXT = 'invoice_default_exemption_text';
	public const SETTING_DUE_DAYS = 'invoice_default_due_days';
	public const SETTING_PAYMENT_TERMS = 'invoice_payment_terms';
	public const SETTING_NOTE_HEAD = 'invoice_note_head';
	public const SETTING_NOTE_FOOT = 'invoice_note_foot';
	public const SETTING_ACCOUNT_ID = 'invoice_account_id';
	public const SETTING_BIC = 'invoice_bic';
	public const SETTING_FOLDER = 'invoice_folder';
	public const SETTING_MAIL_SUBJECT = 'invoice_mail_subject';
	public const SETTING_MAIL_BODY = 'invoice_mail_body';

	public const DEFAULT_FOLDER = 'Vereinsbuchhaltung/Rechnungen';
	public const DEFAULT_DUE_DAYS = 14;
	public const MAX_DUE_DAYS = 365;

	/** Steuer-Modi, die diese Ausbaustufe anbietet. Die Regelbesteuerung kommt mit Stufe 2. */
	public const AVAILABLE_TAX_MODES = [Invoice::TAX_SMALL_BUSINESS, Invoice::TAX_EXEMPT];

	/**
	 * Schlüssel der Ausstellerangaben => Feld im Ausstellerblock und Höchstlänge.
	 *
	 * @var array<string, array{field: string, max: int}>
	 */
	private const ISSUER_KEYS = [
		'club_street' => ['field' => 'street', 'max' => 255],
		'club_postal_code' => ['field' => 'postalCode', 'max' => 16],
		'club_city' => ['field' => 'city', 'max' => 128],
		'club_country' => ['field' => 'country', 'max' => 2],
		'club_tax_number' => ['field' => 'taxNumber', 'max' => 32],
		'club_vat_id' => ['field' => 'vatId', 'max' => 32],
		'club_register_court' => ['field' => 'registerCourt', 'max' => 128],
		'club_register_no' => ['field' => 'registerNo', 'max' => 64],
		'club_representatives' => ['field' => 'representatives', 'max' => 255],
		'club_phone' => ['field' => 'phone', 'max' => 64],
		'club_email' => ['field' => 'email', 'max' => 255],
	];

	/** Kleinunternehmer-Hinweis nach § 19 Abs. 1 UStG, wenn nichts anderes hinterlegt ist. */
	public const SMALL_BUSINESS_NOTICE = 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.';

	public function __construct(
		private IConfig $config,
		private FolderPathValidator $folderPathValidator,
		private ?IL10N $l10n = null,
	) {
	}

	private function get(string $key, string $default = ''): string {
		return $this->config->getAppValue(Application::APP_ID, $key, $default);
	}

	public function isActive(): bool {
		return $this->get(self::SETTING_ACTIVE, '0') === '1';
	}

	/** Bucht der Import einen eindeutigen Zahlungseingang (Rechnungsnummer und Betrag stimmen) selbst? Ab Werk ja. */
	public function autoAssign(): bool {
		return $this->get(self::SETTING_AUTO_ASSIGN, '1') === '1';
	}

	public function taxMode(): string {
		$mode = $this->get(self::SETTING_TAX_MODE, Invoice::TAX_SMALL_BUSINESS);
		return in_array($mode, self::AVAILABLE_TAX_MODES, true) ? $mode : Invoice::TAX_SMALL_BUSINESS;
	}

	public function defaultDueDays(): int {
		$days = (int)$this->get(self::SETTING_DUE_DAYS, (string)self::DEFAULT_DUE_DAYS);
		return $days >= 0 && $days <= self::MAX_DUE_DAYS ? $days : self::DEFAULT_DUE_DAYS;
	}

	public function folder(): string {
		$folder = trim($this->get(self::SETTING_FOLDER, ''), '/');
		return $folder !== '' ? $folder : self::DEFAULT_FOLDER;
	}

	public function accountId(): ?int {
		$id = (int)$this->get(self::SETTING_ACCOUNT_ID, '0');
		return $id > 0 ? $id : null;
	}

	public function bic(): string {
		return $this->get(self::SETTING_BIC);
	}

	/**
	 * Die Angaben des Ausstellers, wie sie auf der Rechnung stehen.
	 *
	 * @return array<string, string>
	 */
	public function issuer(): array {
		$issuer = ['name' => trim($this->get('club_name'))];
		foreach (self::ISSUER_KEYS as $key => ['field' => $field]) {
			$issuer[$field] = trim($this->get($key, $field === 'country' ? 'DE' : ''));
		}
		if ($issuer['country'] === '') {
			$issuer['country'] = 'DE';
		}
		return $issuer;
	}

	/**
	 * Pflichtangaben des Ausstellers nach § 14 Abs. 4 Nr. 1 und 2 UStG, die
	 * noch fehlen. Der Schlüssel ist die Einstellung, der Wert die Bezeichnung
	 * für die Meldung.
	 *
	 * @return array<string, string>
	 */
	public function missingIssuerFields(): array {
		$issuer = $this->issuer();
		$missing = [];
		if ($issuer['name'] === '') {
			$missing['club_name'] = $this->msg('Name des Vereins');
		}
		if ($issuer['street'] === '') {
			$missing['club_street'] = $this->msg('Straße und Hausnummer');
		}
		if ($issuer['postalCode'] === '') {
			$missing['club_postal_code'] = $this->msg('Postleitzahl');
		}
		if ($issuer['city'] === '') {
			$missing['club_city'] = $this->msg('Ort');
		}
		if ($issuer['taxNumber'] === '' && $issuer['vatId'] === '') {
			$missing['club_tax_number'] = $this->msg('Steuernummer oder USt-IdNr.');
		}
		return $missing;
	}

	/** @return array<string, mixed> */
	public function all(): array {
		$out = [
			self::SETTING_ACTIVE => $this->isActive(),
			self::SETTING_AUTO_ASSIGN => $this->autoAssign(),
			self::SETTING_TAX_MODE => $this->taxMode(),
			self::SETTING_EXEMPTION_TEXT => $this->get(self::SETTING_EXEMPTION_TEXT),
			self::SETTING_DUE_DAYS => $this->defaultDueDays(),
			self::SETTING_PAYMENT_TERMS => $this->get(self::SETTING_PAYMENT_TERMS),
			self::SETTING_NOTE_HEAD => $this->get(self::SETTING_NOTE_HEAD),
			self::SETTING_NOTE_FOOT => $this->get(self::SETTING_NOTE_FOOT),
			self::SETTING_ACCOUNT_ID => $this->accountId(),
			self::SETTING_BIC => $this->bic(),
			self::SETTING_FOLDER => $this->get(self::SETTING_FOLDER),
			self::SETTING_MAIL_SUBJECT => $this->get(self::SETTING_MAIL_SUBJECT),
			self::SETTING_MAIL_BODY => $this->get(self::SETTING_MAIL_BODY),
			'invoice_missing_issuer_fields' => array_keys($this->missingIssuerFields()),
		];
		$issuer = $this->issuer();
		foreach (self::ISSUER_KEYS as $key => ['field' => $field]) {
			$out[$key] = $issuer[$field];
		}
		return $out;
	}

	/**
	 * Schreibt nur die Schlüssel, die im Request stehen (siehe
	 * SettingsController::update()). Das Geldkonto ist ausgenommen, das prüft
	 * und schreibt der Controller selbst.
	 *
	 * @param array<string, mixed> $params
	 * @return string|null Fehlermeldung oder null, wenn alles gespeichert wurde
	 */
	public function update(array $params): ?string {
		// Erst alles prüfen, dann schreiben: ein Fehler im letzten Feld soll
		// keine halbe Änderung hinterlassen.
		$writes = [];

		if (array_key_exists(self::SETTING_ACTIVE, $params)) {
			$writes[self::SETTING_ACTIVE] = (string)$params[self::SETTING_ACTIVE] === '1' ? '1' : '0';
		}

		if (array_key_exists(self::SETTING_AUTO_ASSIGN, $params)) {
			$writes[self::SETTING_AUTO_ASSIGN] = (string)$params[self::SETTING_AUTO_ASSIGN] === '1' ? '1' : '0';
		}

		if (array_key_exists(self::SETTING_TAX_MODE, $params)) {
			$mode = (string)$params[self::SETTING_TAX_MODE];
			if (!in_array($mode, self::AVAILABLE_TAX_MODES, true)) {
				return $this->msg('Dieser Steuer-Modus ist noch nicht verfügbar.');
			}
			$writes[self::SETTING_TAX_MODE] = $mode;
		}

		foreach (self::ISSUER_KEYS as $key => ['max' => $max]) {
			if (!array_key_exists($key, $params)) {
				continue;
			}
			$value = trim((string)$params[$key]);
			if (mb_strlen($value) > $max) {
				return $this->msg('Der Wert für „%s“ ist zu lang (höchstens %d Zeichen).', [$key, $max]);
			}
			if ($key === 'club_country') {
				$value = strtoupper($value);
				if ($value !== '' && preg_match('/^[A-Z]{2}$/', $value) !== 1) {
					return $this->msg('Das Land wird als zweistelliger Ländercode angegeben, z. B. DE.');
				}
			}
			if ($key === 'club_email' && $value !== '' && !EmailValidator::isValid($value)) {
				return $this->msg('Ungültige E-Mail-Adresse des Vereins.');
			}
			if ($key === 'club_vat_id') {
				$value = strtoupper(str_replace(' ', '', $value));
				if ($value !== '' && preg_match('/^[A-Z]{2}[A-Z0-9]{2,12}$/', $value) !== 1) {
					return $this->msg('Die USt-IdNr. sieht nicht gültig aus (erwartet wird z. B. DE123456789).');
				}
			}
			$writes[$key] = $value;
		}

		$textFields = [
			self::SETTING_EXEMPTION_TEXT => 500,
			self::SETTING_PAYMENT_TERMS => 1000,
			self::SETTING_NOTE_HEAD => 2000,
			self::SETTING_NOTE_FOOT => 2000,
			self::SETTING_MAIL_SUBJECT => 200,
			self::SETTING_MAIL_BODY => 5000,
		];
		foreach ($textFields as $key => $max) {
			if (!array_key_exists($key, $params)) {
				continue;
			}
			$value = trim((string)$params[$key]);
			if (mb_strlen($value) > $max) {
				return $this->msg('Der Text für „%s“ ist zu lang (höchstens %d Zeichen).', [$key, $max]);
			}
			$writes[$key] = $value;
		}

		if (array_key_exists(self::SETTING_DUE_DAYS, $params)) {
			$days = (int)$params[self::SETTING_DUE_DAYS];
			if ($days < 0 || $days > self::MAX_DUE_DAYS) {
				return $this->msg('Das Zahlungsziel muss zwischen 0 und %d Tagen liegen.', [self::MAX_DUE_DAYS]);
			}
			$writes[self::SETTING_DUE_DAYS] = (string)$days;
		}

		if (array_key_exists(self::SETTING_BIC, $params)) {
			$bic = strtoupper(str_replace(' ', '', trim((string)$params[self::SETTING_BIC])));
			if ($bic !== '' && preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic) !== 1) {
				return $this->msg('Die BIC sieht nicht gültig aus (8 oder 11 Zeichen).');
			}
			$writes[self::SETTING_BIC] = $bic;
		}

		if (array_key_exists(self::SETTING_FOLDER, $params)) {
			$folder = (string)$params[self::SETTING_FOLDER];
			$error = $this->folderPathValidator->validate($folder, $this->msg('Rechnungsordner'));
			if ($error !== null) {
				return $error;
			}
			$writes[self::SETTING_FOLDER] = trim(str_replace('\\', '/', $folder), '/');
		}

		foreach ($writes as $key => $value) {
			$this->config->setAppValue(Application::APP_ID, $key, $value);
		}
		return null;
	}

	public function setAccountId(?int $accountId): void {
		if ($accountId === null) {
			$this->config->deleteAppValue(Application::APP_ID, self::SETTING_ACCOUNT_ID);
			return;
		}
		$this->config->setAppValue(Application::APP_ID, self::SETTING_ACCOUNT_ID, (string)$accountId);
	}

	/** @param list<int|string> $params */
	private function msg(string $text, array $params = []): string {
		return $this->l10n !== null ? $this->l10n->t($text, $params) : vsprintf($text, $params);
	}
}
