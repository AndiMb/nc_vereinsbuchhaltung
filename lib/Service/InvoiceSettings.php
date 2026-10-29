<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoiceDunningService;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoiceMailService;
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
 * SEPA-Einzugs, sonst das einzige Geldkonto mit IBAN ({@see InvoiceBankAccountService}).
 * Die Prüfung, dass die Wahl ein Geldkonto mit IBAN ist, macht der
 * SettingsController, der die Konten kennt.
 */
class InvoiceSettings {

	public const SETTING_ACTIVE = 'invoice_module_active';
	public const SETTING_AUTO_ASSIGN = 'invoice_auto_assign';
	public const SETTING_EINVOICE_AUTO = 'invoice_einvoice_auto';
	public const SETTING_RF_REFERENCE = 'invoice_rf_reference';
	public const SETTING_DUNNING_AUTO = 'invoice_dunning_auto';
	public const SETTING_DUNNING_GRACE_DAYS = 'invoice_dunning_grace_days';
	public const SETTING_DUNNING_SUBJECT = 'invoice_dunning_subject';
	/** Adresse des Vorstands für Blindkopien von Rechnungs- und Mahnmails. */
	public const SETTING_BOARD_EMAIL = 'invoice_board_email';
	/** Geht jede Mahnmail zusätzlich als Blindkopie an die Vorstands-Adresse? */
	public const SETTING_DUNNING_COPY_BOARD = 'invoice_dunning_copy_board';
	/** Tage bis zur Stufe 1/2/3: nach dem Zahlungsziel, nach der Erinnerung, nach der 1. Mahnung. */
	public const SETTING_DUNNING_DAYS = [1 => 'invoice_dunning_days_1', 2 => 'invoice_dunning_days_2', 3 => 'invoice_dunning_days_3'];
	public const SETTING_DUNNING_BODY = [1 => 'invoice_dunning_body_1', 2 => 'invoice_dunning_body_2', 3 => 'invoice_dunning_body_3'];
	public const DUNNING_DEFAULT_DAYS = [1 => 7, 2 => 14, 3 => 14];
	public const DUNNING_DEFAULT_GRACE_DAYS = 7;
	public const MAX_DUNNING_DAYS = 365;
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
	/** Umsatzsteuerkonten je Satz (Konto-ID), nur bei Regelbesteuerung gebraucht. */
	public const SETTING_VAT_ACCOUNT_19 = 'invoice_vat_account_19';
	public const SETTING_VAT_ACCOUNT_7 = 'invoice_vat_account_7';
	/** Vorgeschlagener Steuersatz (Basispunkte) je Sphäre des Erlöskontos. */
	public const SETTING_RATE_ZWECKBETRIEB = 'invoice_rate_zweckbetrieb';
	public const SETTING_RATE_WIRTSCHAFTLICH = 'invoice_rate_wirtschaftlich';

	/** Steuersätze, die die Einstellungen als Vorschlag kennen (Basispunkte). */
	public const SELECTABLE_RATES = [0, 700, 1900];

	public const DEFAULT_FOLDER = 'Vereinsbuchhaltung/Rechnungen';
	public const DEFAULT_DUE_DAYS = 14;
	public const MAX_DUE_DAYS = 365;

	/** Steuer-Modi, die der Editor anbietet. */
	public const AVAILABLE_TAX_MODES = [Invoice::TAX_SMALL_BUSINESS, Invoice::TAX_EXEMPT, Invoice::TAX_REGULAR];

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
		'club_nonprofit_note' => ['field' => 'nonprofitNote', 'max' => 500],
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

	/** Erzeugt das Festschreiben zugleich die E-Rechnung, die zum Empfänger passt? */
	public function eInvoiceAuto(): bool {
		return $this->get(self::SETTING_EINVOICE_AUTO, '1') === '1';
	}

	/** Druckt die Rechnung eine strukturierte Zahlungsreferenz (ISO 11649) und trägt sie in den GiroCode ein? */
	public function rfReference(): bool {
		return $this->get(self::SETTING_RF_REFERENCE, '1') === '1';
	}

	/** Mahnt der tägliche Hintergrundjob fällige Rechnungen selbst? Aus, bis der Verein es einschaltet. */
	public function dunningAuto(): bool {
		return $this->get(self::SETTING_DUNNING_AUTO, '0') === '1';
	}

	/** Adresse des Vorstands für Kopien; null, wenn keine gültige hinterlegt ist. */
	public function boardEmail(): ?string {
		$email = trim($this->get(self::SETTING_BOARD_EMAIL));
		return $email !== '' && EmailValidator::isValid($email) ? $email : null;
	}

	/** Bekommt der Vorstand von jeder Mahnmail eine Blindkopie? Aus, bis der Verein es einschaltet. */
	public function dunningCopyBoard(): bool {
		return $this->get(self::SETTING_DUNNING_COPY_BOARD, '0') === '1';
	}

	/**
	 * Tage, nach denen die Mahnstufe fällig wird: Stufe 1 nach dem Zahlungsziel,
	 * Stufe 2 nach der Erinnerung, Stufe 3 nach der ersten Mahnung.
	 *
	 * @return array{1: int, 2: int, 3: int}
	 */
	public function dunningDays(): array {
		$out = [];
		foreach (self::SETTING_DUNNING_DAYS as $level => $key) {
			$raw = $this->get($key, '');
			$out[$level] = $raw === '' ? self::DUNNING_DEFAULT_DAYS[$level] : max(0, min(self::MAX_DUNNING_DAYS, (int)$raw));
		}
		/** @var array{1: int, 2: int, 3: int} $out */
		return $out;
	}

	/** Frist, die das Schreiben für die Zahlung setzt (Tage nach dem Datum des Schreibens). */
	public function dunningGraceDays(): int {
		$raw = $this->get(self::SETTING_DUNNING_GRACE_DAYS, '');
		return $raw === '' ? self::DUNNING_DEFAULT_GRACE_DAYS : max(1, min(self::MAX_DUNNING_DAYS, (int)$raw));
	}

	/**
	 * Die Standardtexte für Mail und Mahnungen in der Sprache des Aufrufers, mit
	 * Platzhaltern. Die Einstellungen zeigen sie als Platzhalter der Felder: wer das
	 * Feld leer lässt, bekommt genau diesen Text.
	 *
	 * @return array<string, string>
	 */
	public function defaultTexts(): array {
		return [
			'invoice_mail_subject' => $this->msg(InvoiceMailService::DEFAULT_SUBJECT),
			'invoice_mail_body' => $this->msg(InvoiceMailService::DEFAULT_BODY),
			'invoice_dunning_subject' => $this->msg(InvoiceDunningService::DEFAULT_SUBJECT),
			'invoice_dunning_body_1' => $this->msg(InvoiceDunningService::DEFAULT_BODIES[1]),
			'invoice_dunning_body_2' => $this->msg(InvoiceDunningService::DEFAULT_BODIES[2]),
			'invoice_dunning_body_3' => $this->msg(InvoiceDunningService::DEFAULT_BODIES[3]),
		];
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
	 * Das Umsatzsteuerkonto für einen Steuersatz (Basispunkte), oder null, wenn
	 * keines eingestellt ist. Es gibt Konten für 19 % und 7 %.
	 */
	public function vatAccountId(int $rateBp): ?int {
		$key = match ($rateBp) {
			1900 => self::SETTING_VAT_ACCOUNT_19,
			700 => self::SETTING_VAT_ACCOUNT_7,
			default => null,
		};
		if ($key === null) {
			return null;
		}
		$id = (int)$this->get($key, '0');
		return $id > 0 ? $id : null;
	}

	public function setVatAccountId(string $key, ?int $accountId): void {
		if (!in_array($key, [self::SETTING_VAT_ACCOUNT_19, self::SETTING_VAT_ACCOUNT_7], true)) {
			throw new \InvalidArgumentException('Unbekanntes Umsatzsteuerkonto');
		}
		if ($accountId === null) {
			$this->config->deleteAppValue(Application::APP_ID, $key);
			return;
		}
		$this->config->setAppValue(Application::APP_ID, $key, (string)$accountId);
	}

	/**
	 * Vorgeschlagene Steuersätze je Sphäre des Erlöskontos (Basispunkte).
	 *
	 * @return array{zweckbetrieb: int, wirtschaftlich: int}
	 */
	public function defaultRates(): array {
		$read = function (string $key, int $default): int {
			$value = (int)$this->get($key, (string)$default);
			return in_array($value, self::SELECTABLE_RATES, true) ? $value : $default;
		};
		return [
			'zweckbetrieb' => $read(self::SETTING_RATE_ZWECKBETRIEB, 700),
			'wirtschaftlich' => $read(self::SETTING_RATE_WIRTSCHAFTLICH, 1900),
		];
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
			self::SETTING_EINVOICE_AUTO => $this->eInvoiceAuto(),
			self::SETTING_RF_REFERENCE => $this->rfReference(),
			'invoice_default_texts' => $this->defaultTexts(),
			self::SETTING_DUNNING_AUTO => $this->dunningAuto(),
			self::SETTING_DUNNING_GRACE_DAYS => $this->dunningGraceDays(),
			self::SETTING_DUNNING_SUBJECT => $this->get(self::SETTING_DUNNING_SUBJECT),
			self::SETTING_DUNNING_COPY_BOARD => $this->dunningCopyBoard(),
			self::SETTING_BOARD_EMAIL => trim($this->get(self::SETTING_BOARD_EMAIL)),
			'invoice_dunning_days_1' => $this->dunningDays()[1],
			'invoice_dunning_days_2' => $this->dunningDays()[2],
			'invoice_dunning_days_3' => $this->dunningDays()[3],
			'invoice_dunning_body_1' => $this->get(self::SETTING_DUNNING_BODY[1]),
			'invoice_dunning_body_2' => $this->get(self::SETTING_DUNNING_BODY[2]),
			'invoice_dunning_body_3' => $this->get(self::SETTING_DUNNING_BODY[3]),
			self::SETTING_TAX_MODE => $this->taxMode(),
			self::SETTING_VAT_ACCOUNT_19 => $this->vatAccountId(1900),
			self::SETTING_VAT_ACCOUNT_7 => $this->vatAccountId(700),
			self::SETTING_RATE_ZWECKBETRIEB => $this->defaultRates()['zweckbetrieb'],
			self::SETTING_RATE_WIRTSCHAFTLICH => $this->defaultRates()['wirtschaftlich'],
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

		if (array_key_exists(self::SETTING_DUNNING_AUTO, $params)) {
			$writes[self::SETTING_DUNNING_AUTO] = (string)$params[self::SETTING_DUNNING_AUTO] === '1' ? '1' : '0';
		}

		if (array_key_exists(self::SETTING_DUNNING_COPY_BOARD, $params)) {
			$writes[self::SETTING_DUNNING_COPY_BOARD] = (string)$params[self::SETTING_DUNNING_COPY_BOARD] === '1' ? '1' : '0';
		}

		if (array_key_exists(self::SETTING_BOARD_EMAIL, $params)) {
			$board = trim((string)$params[self::SETTING_BOARD_EMAIL]);
			if ($board !== '' && (mb_strlen($board) > 255 || !EmailValidator::isValid($board))) {
				return $this->msg('Ungültige E-Mail-Adresse des Vorstands.');
			}
			$writes[self::SETTING_BOARD_EMAIL] = $board;
		}

		$dayFields = array_merge(self::SETTING_DUNNING_DAYS, [0 => self::SETTING_DUNNING_GRACE_DAYS]);
		foreach ($dayFields as $level => $key) {
			if (!array_key_exists($key, $params)) {
				continue;
			}
			$days = (int)$params[$key];
			$min = $level === 0 ? 1 : 0;
			if ($days < $min || $days > self::MAX_DUNNING_DAYS) {
				return $this->msg('Die Tage für das Mahnwesen müssen zwischen %1$d und %2$d liegen.', [$min, self::MAX_DUNNING_DAYS]);
			}
			$writes[$key] = (string)$days;
		}

		if (array_key_exists(self::SETTING_RF_REFERENCE, $params)) {
			$writes[self::SETTING_RF_REFERENCE] = (string)$params[self::SETTING_RF_REFERENCE] === '1' ? '1' : '0';
		}

		if (array_key_exists(self::SETTING_EINVOICE_AUTO, $params)) {
			$writes[self::SETTING_EINVOICE_AUTO] = (string)$params[self::SETTING_EINVOICE_AUTO] === '1' ? '1' : '0';
		}

		if (array_key_exists(self::SETTING_TAX_MODE, $params)) {
			$mode = (string)$params[self::SETTING_TAX_MODE];
			if (!in_array($mode, self::AVAILABLE_TAX_MODES, true)) {
				return $this->msg('Dieser Steuer-Modus ist noch nicht verfügbar.');
			}
			$writes[self::SETTING_TAX_MODE] = $mode;
		}

		foreach ([self::SETTING_RATE_ZWECKBETRIEB, self::SETTING_RATE_WIRTSCHAFTLICH] as $rateKey) {
			if (!array_key_exists($rateKey, $params)) {
				continue;
			}
			$rate = (int)$params[$rateKey];
			if (!in_array($rate, self::SELECTABLE_RATES, true)) {
				return $this->msg('Der Steuersatz muss 0, 7 oder 19 Prozent sein.');
			}
			$writes[$rateKey] = (string)$rate;
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
			self::SETTING_DUNNING_SUBJECT => 200,
			self::SETTING_DUNNING_BODY[1] => 5000,
			self::SETTING_DUNNING_BODY[2] => 5000,
			self::SETTING_DUNNING_BODY[3] => 5000,
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
