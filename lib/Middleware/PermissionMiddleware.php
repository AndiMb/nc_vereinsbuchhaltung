<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Middleware;

use OCA\Vereinsbuchhaltung\Controller\PageController;
use OCA\Vereinsbuchhaltung\Controller\PermissionController;
use OCA\Vereinsbuchhaltung\Exception\ForbiddenException;
use OCA\Vereinsbuchhaltung\Exception\YearClosedException;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Zentrale Rechteprüfung für alle App-Endpunkte.
 *
 * Vorrang hat ein {@see RequiresRole}-Attribut an der Methode. Fehlt es, gilt
 * die Heuristik: GET/HEAD = lesen (Revisor+), alles andere = schreiben
 * (Buchhalter+). Der PermissionController (Rechtevergabe) erfordert Verwalter;
 * nur "me" ist für jeden angemeldeten Nutzer erreichbar. Die Seite selbst wird
 * immer gerendert (die Vue-App zeigt dann ggf. "kein Zugriff").
 */
class PermissionMiddleware extends Middleware {

	public function __construct(
		private PermissionService $permissions,
		private IRequest $request,
		private IL10N $l10n,
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
				throw new ForbiddenException($this->l10n->t('Nur Verwalter dürfen Berechtigungen verwalten.'));
			}
			return;
		}

		// Ausdrückliche Angabe an der Methode hat Vorrang vor der Verb-Heuristik.
		$declared = $this->declaredRole($controller, $methodName);
		if ($declared !== null) {
			$this->requireRole($declared);
			return;
		}

		$verb = strtoupper($this->request->getMethod());
		if ($verb === 'GET' || $verb === 'HEAD') {
			$this->requireRole(PermissionService::ROLE_READ);
		} else {
			$this->requireRole(PermissionService::ROLE_WRITE);
		}
	}

	/**
	 * Liest ein {@see RequiresRole}-Attribut an der aufgerufenen Methode aus.
	 */
	private function declaredRole(Controller $controller, string $methodName): ?string {
		try {
			$method = new \ReflectionMethod($controller, $methodName);
		} catch (\ReflectionException) {
			return null;
		}
		$attributes = $method->getAttributes(RequiresRole::class);
		if ($attributes === []) {
			return null;
		}
		return $attributes[0]->newInstance()->role;
	}

	/**
	 * @throws ForbiddenException wenn die Rolle des Nutzers nicht ausreicht
	 */
	private function requireRole(string $role): void {
		$current = $this->permissions->getRole();
		if (PermissionService::RANK[$current] >= PermissionService::RANK[$role]) {
			return;
		}
		throw new ForbiddenException(match ($role) {
			PermissionService::ROLE_READ => $this->l10n->t('Kein Lesezugriff auf die Vereinsbuchhaltung.'),
			PermissionService::ROLE_WRITE => $this->l10n->t('Keine Schreibberechtigung.'),
			default => $this->l10n->t('Diese Aktion ist Verwaltern vorbehalten.'),
		});
	}

	public function afterException(Controller $controller, string $methodName, \Exception $exception): JSONResponse {
		if ($exception instanceof ForbiddenException) {
			return new JSONResponse(['message' => $exception->getMessage()], Http::STATUS_FORBIDDEN);
		}
		// Festschreibung: Schreibversuch auf ein abgeschlossenes Geschäftsjahr.
		if ($exception instanceof YearClosedException) {
			return new JSONResponse(['message' => $exception->getMessage()], Http::STATUS_LOCKED);
		}
		throw $exception;
	}
}
