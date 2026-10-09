<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateLegalTextVersion;

/**
 * Gemeinsamer Renderer für Mandatsformular-PDF und elektronische
 * Zustimmungsseite (Spec §2.2 „Mandatsformular-PDF wird aus demselben
 * versionierten Rechtstext erzeugt wie die elektronische Zustimmung"/§3.11
 * „nur die Hülle unterscheidet sich", Issue #67).
 *
 * Zwei Bausteine, die beide Aufrufer identisch bekommen:
 *
 * - {@see self::renderLegalText()}: der reine Rechtstext (Pflichtblock +
 *   Rahmen der fixierten {@see MandateLegalTextVersion}, `{{creditor_name}}`
 *   ersetzt).
 * - {@see self::renderDataBlock()}: die mandatsspezifischen Pflichtangaben
 *   aus Spec §8 ("Mandatsreferenz/Name/IBAN/Gläubiger-ID/Zahlungsart/Datum/
 *   Unterschrift") als Definitionsliste.
 *
 * **Bewusst nur Deutsch, ohne `t()`** (Spec §3.11 „Mandats-Rechtstext … Nur
 * Deutsch", Issue #106): das Mandat ist ein Dokument, das ein Mitglied
 * unterschreibt oder dem es elektronisch zustimmt – Rechtstext, Platzhalter-
 * Ersatz („den Verein") und Pflichtangaben gehören zu EINEM deutschen
 * Dokument. Übersetzte Beschriftungen um einen deutschen Rechtstext herum
 * gäben ein Sprachgemisch, und der Ersatz für den Vereinsnamen steht mitten in
 * einem deutschen Satz („Ich ermächtige …"). Die Sprache des Betrachters spielt
 * hier keine Rolle – auch nicht, ob Du oder Sie: der Text ist durchgehend Sie.
 *
 * Was NICHT hier ist: die "Hülle" selbst (Druck-Stylesheet der PrintableReportPage
 * vs. das `<button>Zustimmen</button>`-Formular der öffentlichen
 * Zustimmungsseite) - das unterscheidet sich laut Spec bewusst je Kontext und
 * gehört in die jeweiligen Aufrufer ({@see \OCA\Vereinsbuchhaltung\Controller\MandateController::form()},
 * {@see \OCA\Vereinsbuchhaltung\Controller\MandateConsentController}).
 */
class MandateFormRenderer {

	/**
	 * Reiner Rechtstext, HTML-escaped und mit Absätzen - identisch für PDF
	 * und Zustimmungsseite (Spec §3.11).
	 */
	public function renderLegalText(MandateLegalTextVersion $legalText, string $creditorName): string {
		$rendered = $legalText->render($creditorName !== '' ? $creditorName : 'den Verein');
		// Pflichtblock und Rahmen stecken im selben Textkörper, getrennt durch
		// einen HTML-Kommentar-Marker (siehe MandateLegalTextService). Der
		// Marker ist reine Buchführung und darf nie als Text erscheinen -
		// stattdessen trennt er die beiden Teile als Absätze.
		$rendered = str_replace(MandateLegalTextService::RAHMEN_MARKER, "\n\n", $rendered);
		$paragraphs = preg_split('/\n{2,}/', trim($rendered)) ?: [];
		$html = '';
		foreach ($paragraphs as $paragraph) {
			$escaped = nl2br(htmlspecialchars($paragraph, ENT_QUOTES));
			$html .= '<p>' . $escaped . '</p>';
		}
		return $html;
	}

	/**
	 * Die Pflichtangaben nach Spec §8: Mandatsreferenz, Name (=Kontoinhaber),
	 * IBAN, Gläubiger-ID, Zahlungsart (immer wiederkehrend/RCUR, Spec §2.2),
	 * Datum. Die "Unterschrift"-Zeile ist bewusst NICHT Teil dieses
	 * Datenblocks - Papier-PDF, bereits erteiltes elektronisches Mandat und
	 * die offene Zustimmungsseite zeigen dort jeweils etwas anderes (siehe
	 * Klassendoc).
	 *
	 * @param string $referenceDate Anzeigedatum ("Datum" der Pflichtangaben) -
	 *                              bei einem bereits erteilten Mandat dessen `signedAt`, bei einer noch
	 *                              offenen Zustimmung das heutige Datum.
	 */
	public function renderDataBlock(Mandate $mandate, string $creditorId, string $referenceDate): string {
		$rows = [
			['Mandatsreferenz', $mandate->getMandateReference()],
			['Kontoinhaber', $mandate->getAccountHolder()],
			['IBAN', $mandate->getIban() !== null ? trim(chunk_split($mandate->getIban(), 4, ' ')) : '—'],
			['Gläubiger-Identifikationsnummer', $creditorId !== '' ? $creditorId : '—'],
			['Zahlungsart', 'wiederkehrende Zahlung (SEPA-Basislastschrift)'],
			['Datum', GermanDate::format($referenceDate)],
		];
		$html = '<dl class="vbh-mandate-data">';
		foreach ($rows as [$label, $value]) {
			$html .= '<dt>' . htmlspecialchars($label, ENT_QUOTES) . '</dt><dd>' . htmlspecialchars((string)$value, ENT_QUOTES) . '</dd>';
		}
		return $html . '</dl>';
	}
}
