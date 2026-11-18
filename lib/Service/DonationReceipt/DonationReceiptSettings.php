<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\DonationReceipt;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\ClubCurrency;
use OCA\Vereinsbuchhaltung\Service\InvoiceSettings;
use OCP\IAppConfig;
use OCP\IL10N;

/**
 * Die Angaben für Zuwendungsbestätigungen (Issue #10): Grundlage der
 * Steuerbegünstigung (Freistellungsbescheid oder Feststellung nach § 60a AO)
 * mit Finanzamt, Steuernummer und Datum, die begünstigten Zwecke, wer
 * unterschreibt, und die Bestätigung, dass Mitgliedsbeiträge abziehbar sind.
 *
 * Anschrift des Vereins und Steuernummer gibt es schon für Rechnungen
 * ({@see InvoiceSettings}); die Steuernummer lässt sich hier abweichend angeben.
 */
class DonationReceiptSettings {

	public const SETTING_ENABLED = 'donation_receipts_enabled';
	public const SETTING_BASIS = 'donation_basis';
	public const SETTING_TAX_OFFICE = 'donation_tax_office';
	public const SETTING_TAX_NUMBER = 'donation_tax_number';
	public const SETTING_NOTICE_DATE = 'donation_notice_date';
	public const SETTING_ASSESSMENT_PERIOD = 'donation_assessment_period';
	public const SETTING_PURPOSES = 'donation_purposes';
	/**
	 * Bestätigung: Der Verein fördert keine Zwecke nach § 10b Abs. 1 Satz 8 EStG,
	 * Mitgliedsbeiträge sind also abziehbar. Ohne sie bestätigt die App nur
	 * Geldspenden und druckt den Satz für Vereine, deren Beiträge nicht
	 * abziehbar sind.
	 */
	public const SETTING_FEES_DEDUCTIBLE = 'donation_fees_deductible';
	/** Konten, auf denen Geldspenden gebucht werden (Ids, kommagetrennt; Teil B). */
	public const SETTING_ACCOUNTS = 'donation_accounts';
	public const SETTING_SIGNATORY = 'donation_signatory';
	public const SETTING_PLACE = 'donation_place';
	/** Maschinelles Verfahren ohne Unterschrift, beim Finanzamt angezeigt (R 10b.1 Abs. 4 EStR). */
	public const SETTING_MACHINE = 'donation_machine_signed';
	public const SETTING_FOLDER = 'donation_receipt_folder';
	public const DEFAULT_FOLDER = 'Vereinsbuchhaltung/Zuwendungsbestätigungen';

	/** Freistellungsbescheid bzw. Anlage zum Körperschaftsteuerbescheid. */
	public const BASIS_EXEMPTION = 'freistellung';
	/** Feststellung der satzungsmäßigen Voraussetzungen nach § 60a AO. */
	public const BASIS_ASSESSMENT = 'feststellung';
	public const BASES = [self::BASIS_EXEMPTION, self::BASIS_ASSESSMENT];

	/** Gültigkeit des Bescheids nach § 63 Abs. 5 AO, in Jahren. */
	public const VALID_YEARS = [self::BASIS_EXEMPTION => 5, self::BASIS_ASSESSMENT => 3];

	private const TEXT_LIMITS = [
		self::SETTING_TAX_OFFICE => 120,
		self::SETTING_TAX_NUMBER => 40,
		self::SETTING_ASSESSMENT_PERIOD => 40,
		self::SETTING_PURPOSES => 600,
		self::SETTING_SIGNATORY => 120,
		self::SETTING_PLACE => 80,
		self::SETTING_FOLDER => 200,
	];

	public function __construct(
		private IAppConfig $config,
		private InvoiceSettings $invoiceSettings,
		private IL10N $l10n,
		private ?ClubCurrency $currency = null,
	) {
	}

	public function isEnabled(): bool {
		return $this->get(self::SETTING_ENABLED) === '1';
	}

	public function basis(): string {
		$basis = $this->get(self::SETTING_BASIS);
		return in_array($basis, self::BASES, true) ? $basis : self::BASIS_EXEMPTION;
	}

	/** Steuernummer: eigene Angabe, sonst die der Rechnungen. */
	public function taxNumber(): string {
		$own = trim($this->get(self::SETTING_TAX_NUMBER));
		return $own !== '' ? $own : trim($this->get('club_tax_number'));
	}

	public function folder(): string {
		$folder = trim($this->get(self::SETTING_FOLDER), '/');
		return $folder !== '' ? $folder : self::DEFAULT_FOLDER;
	}

	public function feesDeductible(): bool {
		return $this->get(self::SETTING_FEES_DEDUCTIBLE) === '1';
	}

	/** @return list<int> Ids der Spendenkonten */
	public function donationAccountIds(): array {
		$ids = [];
		foreach (explode(',', $this->get(self::SETTING_ACCOUNTS)) as $part) {
			$id = (int)trim($part);
			if ($id > 0) {
				$ids[$id] = $id;
			}
		}
		return array_values($ids);
	}

	public function machineSigned(): bool {
		return $this->get(self::SETTING_MACHINE) === '1';
	}

	/**
	 * Alles, was die Bestätigung braucht, ein Wert je Feld.
	 *
	 * @return array{basis: string, taxOffice: string, taxNumber: string, noticeDate: string, assessmentPeriod: string, purposes: string, signatory: string, place: string, machineSigned: bool, feesDeductible: bool, issuer: array<string, string>}
	 */
	public function data(): array {
		return [
			'basis' => $this->basis(),
			'taxOffice' => trim($this->get(self::SETTING_TAX_OFFICE)),
			'taxNumber' => $this->taxNumber(),
			'noticeDate' => trim($this->get(self::SETTING_NOTICE_DATE)),
			'assessmentPeriod' => trim($this->get(self::SETTING_ASSESSMENT_PERIOD)),
			'purposes' => trim($this->get(self::SETTING_PURPOSES)),
			'signatory' => trim($this->get(self::SETTING_SIGNATORY)),
			'place' => trim($this->get(self::SETTING_PLACE)),
			'machineSigned' => $this->machineSigned(),
			'feesDeductible' => $this->feesDeductible(),
			'issuer' => $this->invoiceSettings->issuer(),
		];
	}

	/** @return array<string, string|bool|list<int>> für die Einstellungsseite */
	public function all(): array {
		return [
			self::SETTING_ENABLED => $this->isEnabled(),
			self::SETTING_BASIS => $this->basis(),
			self::SETTING_TAX_OFFICE => $this->get(self::SETTING_TAX_OFFICE),
			self::SETTING_TAX_NUMBER => $this->get(self::SETTING_TAX_NUMBER),
			self::SETTING_NOTICE_DATE => $this->get(self::SETTING_NOTICE_DATE),
			self::SETTING_ASSESSMENT_PERIOD => $this->get(self::SETTING_ASSESSMENT_PERIOD),
			self::SETTING_PURPOSES => $this->get(self::SETTING_PURPOSES),
			self::SETTING_FEES_DEDUCTIBLE => $this->feesDeductible(),
			self::SETTING_SIGNATORY => $this->get(self::SETTING_SIGNATORY),
			self::SETTING_PLACE => $this->get(self::SETTING_PLACE),
			self::SETTING_MACHINE => $this->machineSigned(),
			self::SETTING_FOLDER => $this->folder(),
			self::SETTING_ACCOUNTS => $this->donationAccountIds(),
			// Nur lesbar: Rückfall der Steuernummer, Hinweis in der Oberfläche.
			'club_tax_number' => trim($this->get('club_tax_number')),
		];
	}

	/**
	 * Übernimmt die mitgeschickten Felder. Unbekannte Schlüssel werden ignoriert.
	 *
	 * @param array<string, mixed> $values
	 * @throws \InvalidArgumentException bei ungültigen Werten
	 */
	public function update(array $values): void {
		$write = [];
		foreach ([self::SETTING_ENABLED, self::SETTING_FEES_DEDUCTIBLE, self::SETTING_MACHINE] as $key) {
			if (array_key_exists($key, $values)) {
				$write[$key] = self::truthy($values[$key]) ? '1' : '0';
			}
		}
		if (array_key_exists(self::SETTING_BASIS, $values)) {
			$basis = (string)$values[self::SETTING_BASIS];
			if (!in_array($basis, self::BASES, true)) {
				throw new \InvalidArgumentException($this->l10n->t('Unbekannte Grundlage der Steuerbegünstigung.'));
			}
			$write[self::SETTING_BASIS] = $basis;
		}
		if (array_key_exists(self::SETTING_ACCOUNTS, $values)) {
			$raw = $values[self::SETTING_ACCOUNTS];
			$list = is_array($raw) ? $raw : explode(',', (string)$raw);
			$ids = [];
			foreach ($list as $id) {
				if (!is_numeric($id) || (int)$id <= 0) {
					throw new \InvalidArgumentException($this->l10n->t('Ungültiges Spendenkonto.'));
				}
				$ids[(int)$id] = (int)$id;
			}
			$write[self::SETTING_ACCOUNTS] = implode(',', array_values($ids));
		}
		if (array_key_exists(self::SETTING_NOTICE_DATE, $values)) {
			$date = trim((string)$values[self::SETTING_NOTICE_DATE]);
			if ($date !== '' && !self::isDate($date)) {
				throw new \InvalidArgumentException($this->l10n->t('Das Datum des Bescheids ist ungültig.'));
			}
			$write[self::SETTING_NOTICE_DATE] = $date;
		}
		foreach (self::TEXT_LIMITS as $key => $limit) {
			if (!array_key_exists($key, $values)) {
				continue;
			}
			$text = trim(str_replace(["\r\n", "\r"], "\n", (string)$values[$key]));
			if ($key === self::SETTING_FOLDER) {
				$text = trim(str_replace('\\', '/', $text), '/');
			}
			if (mb_strlen($text) > $limit) {
				throw new \InvalidArgumentException($this->l10n->t('Eine Angabe ist zu lang (höchstens %s Zeichen).', [(string)$limit]));
			}
			$write[$key] = $text;
		}
		foreach ($write as $key => $value) {
			$this->config->setValueString(Application::APP_ID, $key, $value);
		}
	}

	/**
	 * Was fehlt, damit Bestätigungen ausgestellt werden dürfen – leer, wenn alles da ist.
	 *
	 * @param string $today Ausstellungsdatum (Y-m-d) für die Gültigkeit des Bescheids
	 * @return list<string> Meldungen für die Oberfläche
	 */
	public function problems(string $today): array {
		$problems = [];
		if ($this->currency !== null && !$this->currency->isEuro()) {
			$problems[] = $this->l10n->t('Zuwendungsbestätigungen gibt es nur für Vereine, die in Euro buchen.');
		}
		$d = $this->data();
		$issuer = $d['issuer'];
		if ($issuer['name'] === '' || $issuer['street'] === '' || $issuer['postalCode'] === '' || $issuer['city'] === '') {
			$problems[] = $this->l10n->t('Name und Anschrift des Vereins fehlen (Einstellungen → Rechnungen → Angaben des Vereins).');
		}
		if ($d['taxOffice'] === '') {
			$problems[] = $this->l10n->t('Das Finanzamt fehlt.');
		}
		if ($d['taxNumber'] === '') {
			$problems[] = $this->l10n->t('Die Steuernummer fehlt.');
		}
		if ($d['purposes'] === '') {
			$problems[] = $this->l10n->t('Die begünstigten Zwecke fehlen.');
		}
		if ($d['basis'] === self::BASIS_EXEMPTION && $d['assessmentPeriod'] === '') {
			$problems[] = $this->l10n->t('Der Veranlagungszeitraum des Freistellungsbescheids fehlt.');
		}
		if ($d['noticeDate'] === '') {
			$problems[] = $this->l10n->t('Das Datum des Bescheids fehlt.');
		} elseif (self::isExpired($d['basis'], $d['noticeDate'], $today)) {
			$problems[] = $d['basis'] === self::BASIS_EXEMPTION
				? $this->l10n->t('Der Freistellungsbescheid ist älter als 5 Jahre; damit ausgestellte Bestätigungen erkennt das Finanzamt nicht an (§ 63 Abs. 5 AO). Bitte das Datum des neuen Bescheids eintragen.')
				: $this->l10n->t('Die Feststellung nach § 60a AO ist älter als 3 Jahre; damit ausgestellte Bestätigungen erkennt das Finanzamt nicht an (§ 63 Abs. 5 AO). Bitte das Datum des neuen Bescheids eintragen.');
		}
		return $problems;
	}

	/** Liegt der Bescheid am Ausstellungstag länger als 5 (Freistellung) bzw. 3 Jahre (§ 60a AO) zurück? */
	public static function isExpired(string $basis, string $noticeDate, string $today): bool {
		$years = self::VALID_YEARS[$basis] ?? 5;
		$limit = (new \DateTimeImmutable($noticeDate))->modify('+' . $years . ' years')->format('Y-m-d');
		return $today > $limit;
	}

	private static function isDate(string $value): bool {
		$d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		return $d !== false && $d->format('Y-m-d') === $value;
	}

	private static function truthy(mixed $value): bool {
		return $value === true || $value === 1 || $value === '1' || $value === 'true';
	}

	private function get(string $key): string {
		return $this->config->getValueString(Application::APP_ID, $key, '');
	}
}
