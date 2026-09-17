<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * GiroCode (EPC069-12 „SEPA Credit Transfer", auch „EPC-QR-Code" genannt) als
 * PNG-Bilddaten für EINE Mahnwesen-Position (Spec §3.11 T32, Issue #73):
 * „GiroCode (EPC-QR) als Anhang je Position" – kein Sammelbetrag, jede
 * Forderung bekommt ihren eigenen Code mit eigenem Verwendungszweck, damit
 * eine Einzelüberweisung je Position möglich bleibt (Spec „Einzelüberweisungen
 * (kein Sammelbetrag)").
 *
 * Reine Wertlogik ohne Nextcloud-Abhängigkeit (wie {@see \OCA\Vereinsbuchhaltung\Service\Sepa\SepaCreditor}/
 * {@see \OCA\Vereinsbuchhaltung\Service\Sepa\PainXmlBuilder}) – der Payload-Aufbau ist
 * formatkritisch (ein Feldfehler fiele erst beim Scan in der Banking-App auf)
 * und deshalb ohne Mock-Aufwand testbar.
 *
 * Nutzt `chillerlan/php-qrcode` (neue Composer-Produktivabhängigkeit seit
 * diesem Ticket, siehe composer.json-Kommentar) – die erste echte
 * Fremdcode-Abhängigkeit dieser App.
 *
 * @see https://www.europeanpaymentscouncil.eu/document-library/guidance-documents/quick-response-code-guidelines-enable-data-capture-initiation
 */
final class EpcQrCodeGenerator {

	private const SERVICE_TAG = 'BCD';
	private const VERSION = '002';
	private const CHARSET_UTF8 = '1';
	private const IDENTIFICATION = 'SCT';

	/** EPC069-12 Feldlängen-Obergrenzen. */
	private const MAX_LENGTH_NAME = 70;
	private const MAX_LENGTH_REMITTANCE = 140;

	/**
	 * @param string $creditorName Empfängername (Verein), wird auf 70 Zeichen gekürzt
	 * @param string $creditorIban IBAN des Vereinskontos, das die Überweisung empfängt
	 * @param string|null $creditorBic BIC – seit der SEPA-Vollanwendung 2016 für IBANs aus dem EWR optional
	 * @param int $amountCents Betrag dieser EINEN Position in Cent, muss größer als 0 sein
	 * @param string $remittanceText Verwendungszweck dieser einen Position (unstrukturiert), wird auf 140 Zeichen gekürzt
	 * @return string rohe PNG-Bilddaten (kein Data-URI, siehe Klassendoc)
	 * @throws \InvalidArgumentException bei nicht-positivem Betrag oder leerer IBAN
	 */
	public function generatePng(string $creditorName, string $creditorIban, ?string $creditorBic, int $amountCents, string $remittanceText): string {
		$payload = $this->buildPayload($creditorName, $creditorIban, $creditorBic, $amountCents, $remittanceText);

		$options = new QROptions([
			'outputInterface' => QRGdImagePNG::class,
			'outputBase64' => false,
			// EPC069-12 empfiehlt ECC-Level M als Kompromiss aus Fehlertoleranz
			// und Codegröße bei der vergleichsweise langen Zeichenkette.
			'eccLevel' => EccLevel::M,
			'imageTransparent' => false,
			'scale' => 6,
		]);

		/** @var string $png returnResource ist Default false, outputBase64 hier explizit aus - siehe QRGdImage::dump() */
		$png = (new QRCode($options))->render($payload);
		return $png;
	}

	/** Baut den zeilenweisen EPC069-12-Payload (LF-getrennt, feste Feldreihenfolge). */
	public function buildPayload(string $creditorName, string $creditorIban, ?string $creditorBic, int $amountCents, string $remittanceText): string {
		if ($amountCents <= 0) {
			throw new \InvalidArgumentException('Der Betrag für einen GiroCode muss größer als 0 sein.');
		}
		$iban = strtoupper(str_replace(' ', '', $creditorIban));
		if ($iban === '') {
			throw new \InvalidArgumentException('Ein GiroCode braucht eine IBAN.');
		}
		$bic = $creditorBic !== null ? strtoupper(str_replace(' ', '', $creditorBic)) : '';
		$amount = 'EUR' . number_format($amountCents / 100, 2, '.', '');

		$lines = [
			self::SERVICE_TAG,
			self::VERSION,
			self::CHARSET_UTF8,
			self::IDENTIFICATION,
			$bic,
			$this->truncate($creditorName, self::MAX_LENGTH_NAME),
			$iban,
			$amount,
			// Zweck (optionaler 4-stelliger externer Purpose-Code) - nicht genutzt.
			'',
			// Strukturierte Verwendungszweck-Referenz (Creditor Reference) - nicht
			// genutzt, wir setzen stattdessen unstrukturiert (nächste Zeile), damit
			// der freie Grund-Satz je Position (Spec „eigener Grund-Satz je
			// Position") lesbar in der Banking-App der Zahlerin/des Zahlers landet.
			'',
			$this->truncate($remittanceText, self::MAX_LENGTH_REMITTANCE),
		];
		return implode("\n", $lines);
	}

	private function truncate(string $value, int $maxLength): string {
		$value = trim($value);
		return mb_strlen($value) > $maxLength ? mb_substr($value, 0, $maxLength) : $value;
	}
}
