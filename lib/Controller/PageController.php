<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Http\ReceiptPreviewPolicy;
use OCA\Vereinsbuchhaltung\Service\FrontendStateService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\Util;
use Psr\Log\LoggerInterface;

class PageController extends Controller {

	public function __construct(
		IRequest $request,
		private IInitialState $initialState,
		private FrontendStateService $frontendState,
		private PermissionService $permissions,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		Util::addScript(Application::APP_ID, Application::APP_ID . '-main');
		// Übersetzungen als l10n/<sprache>.js (Issue #264). addScript() meldet sie
		// auch selbst an; ausdrücklich, weil die Oberfläche sie nicht mehr
		// selbst nachlädt und ohne sie in jeder Sprache deutsch bliebe.
		Util::addTranslations(Application::APP_ID);
		$this->provideBootState();
		$response = new TemplateResponse(Application::APP_ID, 'main');
		// Belegvorschau: iframes vom eigenen Ursprung (ReceiptPreviewPolicy).
		$response->setContentSecurityPolicy(ReceiptPreviewPolicy::forPage());
		return $response;
	}

	/**
	 * Deep-Linking: liefert fuer jeden Client-seitigen Pfad dieselbe SPA-Huelle
	 * wie index() (vue-router uebernimmt im Browser). Eigene Methode statt
	 * derselben Route "page#index" ein zweites Mal: Nextcloud leitet den
	 * internen Routennamen aus Controller+Methode ab, zwei Routen mit
	 * demselben Namen liessen die Reverse-URL-Generierung fuer das App-Menuee
	 * (die ohne path-Parameter aufruft) mit "Internal Server Error" scheitern.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function catchAll(string $path = ''): TemplateResponse {
		return $this->index();
	}

	/**
	 * Die Startwerte als Initial State (Issue #264): die Oberfläche fragte sie
	 * bisher nach dem Laden nacheinander ab (`permissions/me`, dann `periods`
	 * und `settings`), bevor irgendetwas zu sehen war. Inhalt wie bei den
	 * Endpunkten, siehe FrontendStateService.
	 *
	 * `periods` und `settings` nur ab Leserolle – dieselbe Grenze wie
	 * `#[RequiresRole(ROLE_READ)]` an den Endpunkten (die PermissionMiddleware
	 * prüft den PageController nicht). Ein Konto, das nur „Mein Bereich" sieht,
	 * bekommt nur `me`.
	 *
	 * Schlägt ein Wert fehl, fehlt er eben: die Oberfläche holt ihn dann über
	 * die API und zeigt dort ihre Fehlermeldung. Die Seite selbst soll daran
	 * nicht mit einem Serverfehler scheitern.
	 */
	private function provideBootState(): void {
		$this->provide('me', fn () => $this->frontendState->me());
		if (!$this->permissions->canRead()) {
			return;
		}
		$this->provide('periods', fn () => $this->frontendState->periods());
		$this->provide('settings', fn () => $this->frontendState->settings());
	}

	/** @param callable(): array<array-key, mixed> $load */
	private function provide(string $key, callable $load): void {
		try {
			$this->initialState->provideInitialState($key, $load());
		} catch (\Throwable $e) {
			$this->logger->warning('Startwert {key} nicht in die Seite gelegt: {message}', [
				'key' => $key,
				'message' => $e->getMessage(),
				'exception' => $e,
			]);
		}
	}
}
