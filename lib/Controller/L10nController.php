<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\TranslationBundle;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IRequest;

/**
 * Liefert das Übersetzungsbündel einer Sprache (l10n/<lang>.json) an die
 * Oberfläche aus.
 *
 * Warum überhaupt ein Endpunkt für eine Datei, die im App-Verzeichnis liegt:
 * Nextclouds mitgeliefertes .htaccess liefert statische Dateien nur für eine
 * feste Endungsliste aus (css|js|mjs|svg|gif|ico|jpg|…). **`.json` steht nicht
 * darauf** – ein direkter Abruf von `l10n/en.json` landet in `index.php` und
 * kommt als 404-HTML-Seite zurück. Bis Version 0.27.2 hat die Oberfläche genau
 * das versucht; der Ladeversuch scheiterte immer still, und die App blieb
 * deutsch, egal welche Sprache eingestellt war (übliche nginx-Konfigurationen
 * verhalten sich genauso). Über diese Route greift die Endungsliste nicht.
 *
 * Die Quellsprache der App ist Deutsch; für sie fragt die Oberfläche gar kein
 * Bündel ab, weil die Texte im Code bereits deutsch sind (src/lib/l10n.js).
 */
class L10nController extends Controller {

	private TranslationBundle $bundle;

	public function __construct(
		IRequest $request,
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->bundle = new TranslationBundle(dirname(__DIR__, 2) . '/l10n');
	}

	/**
	 * @param string $lang Sprachcode wie von Nextcloud gemeldet, z. B. "en"
	 *                     oder "pt_BR".
	 */
	#[NoAdminRequired]
	// Ohne NoCSRFRequired antwortet Nextcloud auf ein blankes fetch() mit 412:
	// die Prüfung gilt auch für GET, und der Abruf läuft bewusst ohne den
	// Axios-Aufbau der App (er passiert, bevor die Oberfläche steht). Vertretbar,
	// weil hier nichts geändert wird und die Antwort eine mitgelieferte Datei
	// ohne Nutzerbezug ist.
	#[NoCSRFRequired]
	public function bundle(string $lang): DataDisplayResponse {
		if (!$this->bundle->isValidLanguage($lang)) {
			return $this->json(TranslationBundle::EMPTY_BUNDLE, Http::STATUS_BAD_REQUEST);
		}
		return $this->json($this->bundle->read($lang));
	}

	/** @param Http::STATUS_OK|Http::STATUS_BAD_REQUEST $status */
	private function json(string $body, int $status = Http::STATUS_OK): DataDisplayResponse {
		$response = new DataDisplayResponse(
			$body,
			$status,
			['Content-Type' => 'application/json; charset=utf-8'],
		);
		// Das Bündel ändert sich nur mit einer neuen App-Version, und die
		// Antwort enthält keine nutzerbezogenen Daten.
		$response->cacheFor(3600, false, true);
		return $response;
	}
}
