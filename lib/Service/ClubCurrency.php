<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IL10N;

/**
 * Die Vereinswährung (Issue #202): einzige Quelle für Code, Zeichen und
 * Schreibweise von Beträgen in Berichten, PDFs, Mails und Meldungen.
 *
 * Das Zahlenformat bleibt deutsch („1.234,50“), nur das Zeichen wechselt.
 * Die Liste enthält nur Währungen mit zwei Nachkommastellen – die App
 * rechnet durchgehend in Cent. SEPA-Lastschrift und GiroCode gibt es nur in
 * Euro, siehe {@see assertSepaAvailable()}.
 *
 * Gesetzliche Euro-Grenzen (Freigrenze, § 19 UStG) laufen bewusst nicht
 * hierüber: Sie sind Beträge des deutschen Rechts, keine Vereinsbeträge.
 */
class ClubCurrency {
	public const SETTING = 'club_currency';
	public const DEFAULT = 'EUR';

	/** Code → Zeichen; Code als Zeichen, wo kein eigenes üblich ist. */
	public const SUPPORTED = [
		'EUR' => '€',
		'CHF' => 'CHF',
		'GBP' => '£',
		'DKK' => 'DKK',
		'SEK' => 'SEK',
		'NOK' => 'NOK',
		'PLN' => 'PLN',
		'CZK' => 'CZK',
	];

	private ?string $code = null;

	public function __construct(
		private IAppConfig $config,
	) {
	}

	public static function isSupported(string $code): bool {
		return array_key_exists($code, self::SUPPORTED);
	}

	/** Der eingestellte Code; leer oder unbekannt bedeutet Euro. */
	public function code(): string {
		if ($this->code === null) {
			$stored = strtoupper(trim($this->config->getValueString(Application::APP_ID, self::SETTING, self::DEFAULT)));
			$this->code = self::isSupported($stored) ? $stored : self::DEFAULT;
		}
		return $this->code;
	}

	public function symbol(): string {
		return self::SUPPORTED[$this->code()];
	}

	public function isEuro(): bool {
		return $this->code() === 'EUR';
	}

	/** 123450 → "1.234,50" */
	public function formatNumber(int $cents): string {
		return number_format($cents / 100, 2, ',', '.');
	}

	/** 123450 → "1.234,50 €" bzw. "1.234,50 CHF" */
	public function format(int $cents): string {
		return $this->withSymbol($this->formatNumber($cents));
	}

	/** Hängt das Zeichen an einen schon formatierten Betrag (z. B. englische Mails). */
	public function withSymbol(string $amount): string {
		return $amount . ' ' . $this->symbol();
	}

	/** @throws \InvalidArgumentException wenn die Vereinswährung nicht Euro ist */
	public function assertSepaAvailable(IL10N $l10n): void {
		if (!$this->isEuro()) {
			throw new \InvalidArgumentException($l10n->t('SEPA-Lastschrift und GiroCode gibt es nur in Euro. Die Vereinswährung ist %s.', [$this->code()]));
		}
	}
}
