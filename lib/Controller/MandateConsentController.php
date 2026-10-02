<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Exception\ConsumedActivationTokenException;
use OCA\Vereinsbuchhaltung\Exception\ExpiredActivationTokenException;
use OCA\Vereinsbuchhaltung\Exception\InvalidActivationTokenException;
use OCA\Vereinsbuchhaltung\Service\MandateActivationService;
use OCA\Vereinsbuchhaltung\Service\MandateFormRenderer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Die öffentliche, login-lose Zustimmungsseite der elektronischen
 * Mandatserteilung (Spec §2.2/§8, Issue #67) – erreichbar über den
 * E-Mail-Einmal-Link, AUSDRÜCKLICH ohne Anmeldung: das ist die
 * Kernanforderung, damit auch ein Mitglied ohne NC-Konto zustimmen kann.
 *
 * Bewusst KEIN JavaScript/Fetch-Roundtrip: die Seite rendert serverseitig
 * (GET) und die Zustimmung ist ein simples HTML-`<form method="post">`
 * (POST auf dieselbe Route) – funktioniert auch ohne aktiviertes
 * Client-JavaScript und kommt ohne eigenes Frontend-Tooling für diese eine,
 * von der übrigen Vue-SPA komplett unabhängige Fläche aus (Spec §1.4 „kein
 * eigenes Tooling").
 *
 * Sicherheit (Issue #67 „unauthentifizierte, öffentlich erreichbare
 * Fläche"): `#[PublicPage]` + `#[NoCSRFRequired]` sind hier absichtlich
 * gesetzt – die eigentliche Absicherung ist NICHT die NC-Session/CSRF-Prüfung
 * (die gibt es für einen anonymen Aufruf gar nicht), sondern der
 * kryptographisch zufällige, zeitlich begrenzte, einmal verwendbare Token
 * selbst (siehe {@see MandateActivationService}). Der Validator IST in
 * diesem Sinn das Anti-CSRF-Token: eine fremde Seite kann ihn nicht kennen
 * oder erraten. Zusätzlich `#[AnonRateLimit]`/`#[BruteForceProtection]`
 * gegen Enumerations-/Brute-Force-Versuche.
 *
 * Bewusst KEIN Bypass in der {@see \OCA\Vereinsbuchhaltung\Middleware\PermissionMiddleware}
 * über eine vbh-Rolle – dieser Controller braucht gar keine, er wird dort
 * komplett übersprungen (kein Self-Service-Sonderfall, kein Staff-Kanal),
 * siehe dortiger `instanceof`-Ausschluss.
 */
class MandateConsentController extends Controller {

	public function __construct(
		IRequest $request,
		private MandateActivationService $activation,
		private MandateFormRenderer $formRenderer,
		private IURLGenerator $urlGenerator,
		private IConfig $config,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 3600)]
	#[BruteForceProtection(action: 'vbh_mandate_consent')]
	public function show(string $token): TemplateResponse {
		try {
			$view = $this->activation->view($token);
		} catch (InvalidActivationTokenException $e) {
			return $this->errorPage('invalid', $e->getMessage(), Http::STATUS_NOT_FOUND);
		} catch (ExpiredActivationTokenException $e) {
			return $this->errorPage('expired', $e->getMessage(), Http::STATUS_GONE);
		} catch (DoesNotExistException) {
			// Das Mandat wurde inzwischen gelöscht - praktisch nur durch einen
			// parallelen Admin-Eingriff möglich.
			return $this->errorPage('invalid', $this->l10n->t('Dieser Link ist ungültig.'), Http::STATUS_NOT_FOUND);
		}
		return $this->renderView($view, $token);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 3600)]
	#[BruteForceProtection(action: 'vbh_mandate_consent')]
	public function accept(string $token): TemplateResponse {
		try {
			$this->activation->consent($token, $this->request->getRemoteAddress(), $this->request->getHeader('User-Agent'));
		} catch (InvalidActivationTokenException $e) {
			return $this->errorPage('invalid', $e->getMessage(), Http::STATUS_NOT_FOUND);
		} catch (ExpiredActivationTokenException $e) {
			return $this->errorPage('expired', $e->getMessage(), Http::STATUS_GONE);
		} catch (ConsumedActivationTokenException|\InvalidArgumentException) {
			// Bereits verbraucht (z.B. Doppelklick) oder inzwischen ein
			// unzulässiger Zustand (z.B. das Mandat wurde parallel widerrufen) -
			// beides zeigt sich der/dem Zustimmenden am ehrlichsten über den
			// aktuellen Stand, nicht über eine Fehlerseite.
		} catch (DoesNotExistException) {
			return $this->errorPage('invalid', $this->l10n->t('Dieser Link ist ungültig.'), Http::STATUS_NOT_FOUND);
		}

		try {
			$view = $this->activation->view($token);
		} catch (InvalidActivationTokenException|ExpiredActivationTokenException|DoesNotExistException) {
			return $this->errorPage('invalid', $this->l10n->t('Dieser Link ist ungültig.'), Http::STATUS_NOT_FOUND);
		}
		return $this->renderView($view, $token);
	}

	/**
	 * @param array{status: 'pending'|'consumed', mandate: Mandate, legalText: \OCA\Vereinsbuchhaltung\Db\MandateLegalTextVersion, token: \OCA\Vereinsbuchhaltung\Db\MandateActivationToken} $view
	 */
	private function renderView(array $view, string $token): TemplateResponse {
		$mandate = $view['mandate'];
		$clubName = $this->config->getAppValue(Application::APP_ID, 'club_name', '');
		$creditorId = $this->config->getAppValue(Application::APP_ID, 'sepa_creditor_id', '');
		// "Datum" der Pflichtangaben: bei einer noch offenen Zustimmung das
		// heutige Datum (das prospektive Unterschriftsdatum), bei einer bereits
		// erteilten das tatsächliche - sonst zeigte ein Wochen später erneut
		// geöffneter Link ein falsches, viel zu spätes Datum an.
		$referenceDate = $view['status'] === 'consumed' && $mandate->getSignedAt() !== null
			? $mandate->getSignedAt()
			: date('Y-m-d');

		return new TemplateResponse(
			Application::APP_ID,
			'mandateConsent',
			[
				'status' => $view['status'],
				'clubName' => $clubName,
				'legalTextHtml' => $this->formRenderer->renderLegalText($view['legalText'], $clubName),
				'dataBlockHtml' => $this->formRenderer->renderDataBlock($mandate, $creditorId, $referenceDate),
				'consentAt' => $mandate->getConsentAt(),
				'token' => $token,
				'acceptUrl' => $this->urlGenerator->linkToRoute('vereinsbuchhaltung.mandateConsent.accept', ['token' => $token]),
				'errorMessage' => null,
			],
			TemplateResponse::RENDER_AS_PUBLIC,
		);
	}

	/** @param 404|410 $httpStatus */
	private function errorPage(string $status, string $message, int $httpStatus): TemplateResponse {
		return new TemplateResponse(
			Application::APP_ID,
			'mandateConsent',
			[
				'status' => $status,
				'clubName' => $this->config->getAppValue(Application::APP_ID, 'club_name', ''),
				'legalTextHtml' => '',
				'dataBlockHtml' => '',
				'consentAt' => null,
				'token' => '',
				'acceptUrl' => '',
				'errorMessage' => $message,
			],
			TemplateResponse::RENDER_AS_PUBLIC,
			$httpStatus,
		);
	}
}
