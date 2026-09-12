<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

class PageController extends Controller {

	public function __construct(IRequest $request) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		Util::addScript(Application::APP_ID, Application::APP_ID . '-main');
		return new TemplateResponse(Application::APP_ID, 'main');
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
}
