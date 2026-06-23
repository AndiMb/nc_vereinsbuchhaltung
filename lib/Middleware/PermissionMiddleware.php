<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Middleware;

use OCA\Vereinsbuchhaltung\Controller\PageController;
use OCA\Vereinsbuchhaltung\Controller\PermissionController;
use OCA\Vereinsbuchhaltung\Exception\ForbiddenException;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;

/**
 * Zentrale Rechteprüfung für alle App-Endpunkte.
 *
 * Heuristik: GET/HEAD = lesen (Revisor+), alles andere = schreiben (Buchhalter+).
 * Der PermissionController (Rechtevergabe) erfordert Verwalter; nur "me" ist
 * für jeden angemeldeten Nutzer erreichbar. Die Seite selbst wird immer
 * gerendert (die Vue-App zeigt dann ggf. "kein Zugriff").
 */
class PermissionMiddleware extends Middleware {

	public function __construct(
		private PermissionService $permissions,
		private IRequest $request,
	) {
	}

	public function beforeController(Controller $controller, string $methodName): void {
		if ($controller instanceof PageController) {
			return;
		}
		if ($controller instanceof PermissionController) {
			if ($methodName === 'me') {
				return;
			}
			if (!$this->permissions->isAdmin()) {
				throw new ForbiddenException('Nur Verwalter dürfen Berechtigungen verwalten.');
			}
			return;
		}

		$verb = strtoupper($this->request->getMethod());
		if ($verb === 'GET' || $verb === 'HEAD') {
			if (!$this->permissions->canRead()) {
				throw new ForbiddenException('Kein Lesezugriff auf die Vereinsbuchhaltung.');
			}
		} elseif (!$this->permissions->canWrite()) {
			throw new ForbiddenException('Keine Schreibberechtigung.');
		}
	}

	public function afterException(Controller $controller, string $methodName, \Exception $exception): JSONResponse {
		if ($exception instanceof ForbiddenException) {
			return new JSONResponse(['message' => $exception->getMessage()], Http::STATUS_FORBIDDEN);
		}
		throw $exception;
	}
}
