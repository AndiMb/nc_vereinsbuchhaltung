<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateLegalTextVersion;
use OCP\IL10N;

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
 * Was NICHT hier ist: die "Hülle" selbst (Druck-Stylesheet der PrintableReportPage
 * vs. das `<button>Zustimmen</button>`-Formular der öffentlichen
 * Zustimmungsseite) - das unterscheidet sich laut Spec bewusst je Kontext und
 * gehört in die jeweiligen Aufrufer ({@see \OCA\Vereinsbuchhaltung\Controller\MandateController::form()},
 * {@see \OCA\Vereinsbuchhaltung\Controller\MandateConsentController}).
 */
class MandateFormRenderer {

	public function __construct(
		private IL10N $l10n,
	) {
	}

	/**
	 * Reiner Rechtstext, HTML-escaped und mit Absätzen - identisch für PDF
	 * und Zustimmungsseite (Spec §3.11).
	 */
	public function renderLegalText(MandateLegalTextVersion $legalText, string $creditorName): string {
		$rendered = $legalText->render($creditorName !== '' ? $creditorName : $this->l10n->t('den Verein'));
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
			[$this->l10n->t('Mandatsreferenz'), $mandate->getMandateReference()],
			[$this->l10n->t('Kontoinhaber'), $mandate->getAccountHolder()],
			[$this->l10n->t('IBAN'), $mandate->getIban() ?? '—'],
			[$this->l10n->t('Gläubiger-Identifikationsnummer'), $creditorId !== '' ? $creditorId : '—'],
			[$this->l10n->t('Zahlungsart'), $this->l10n->t('wiederkehrende Zahlung (SEPA-Basislastschrift)')],
			[$this->l10n->t('Datum'), $referenceDate],
		];
		$html = '<dl class="vbh-mandate-data">';
		foreach ($rows as [$label, $value]) {
			$html .= '<dt>' . htmlspecialchars($label, ENT_QUOTES) . '</dt><dd>' . htmlspecialchars((string)$value, ENT_QUOTES) . '</dd>';
		}
		return $html . '</dl>';
	}
}
