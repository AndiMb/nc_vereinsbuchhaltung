<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Middleware;

use OCA\Vereinsbuchhaltung\Controller\L10nController;
use OCA\Vereinsbuchhaltung\Controller\MandateConsentController;
use OCA\Vereinsbuchhaltung\Controller\PageController;
use OCA\Vereinsbuchhaltung\Controller\PermissionController;
use OCA\Vereinsbuchhaltung\Controller\SelfController;
use OCA\Vereinsbuchhaltung\Exception\ForbiddenException;
use OCA\Vereinsbuchhaltung\Exception\PeriodClosedException;
use OCA\Vereinsbuchhaltung\Exception\PeriodNotFoundException;
use OCA\Vereinsbuchhaltung\Service\ActorContextService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCA\Vereinsbuchhaltung\Service\SelfServiceService;
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
 *
 * Vierter Sonderfall (Spec §3.4): der SelfController braucht KEINE vbh-Rolle
 * – statt dessen zentral `self_service_enabled && Kontoverknüpfung` prüfen
 * (SelfServiceService) und die aufgelöste member_id als Request-Kontext
 * bereitstellen (ActorContextService). Bewusst kein `#[PublicPage]` am
 * Controller: die Middleware muss für jeden Aufruf laufen.
 *
 * Fünfter Sonderfall (Issue #67): der {@see MandateConsentController} braucht
 * ÜBERHAUPT KEINE vbh-Rolle und KEINEN Self-Service-Zugang - er ist die
 * anonyme, login-lose Zustimmungsseite des elektronischen Einmal-Links
 * (funktioniert bewusst auch ohne NC-Konto). Seine eigene Absicherung ist der
 * kryptographisch geprüfte Token selbst, siehe dortige Klassendoku - hier
 * genügt derselbe komplette Überspringen wie bei Page-/L10nController.
 */
class PermissionMiddleware extends Middleware {

	public function __construct(
		private PermissionService $permissions,
		private SelfServiceService $selfService,
		private ActorContextService $actorContext,
		private IRequest $request,
		private IL10N $l10n,
	) {
	}

	public function beforeController(Controller $controller, string $methodName): void {
		if ($controller instanceof PageController) {
			return;
		}
		// Übersetzungen sind keine Vereinsdaten: ohne sie stünde selbst der
		// Hinweis "Kein Lesezugriff" in der falschen Sprache vor jemandem, der
		// noch keine Rolle hat.
		if ($controller instanceof L10nController) {
			return;
		}
		if ($controller instanceof MandateConsentController) {
			return;
		}
		if ($controller instanceof SelfController) {
			$this->authorizeSelfService();
			return;
		}

		// Ab hier "normale" Buchhaltungs-/Verwaltungs-Endpunkte: der Kanal ist
		// staff, unabhängig davon, ob die handelnde Person selbst auch ein
		// verknüpftes Mitglied ist (Personalunion, Spec §3.9) – der Kanal
		// entscheidet actor_type, nicht die Identität.
		$this->actorContext->setStaffChannel();

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
	 * Zentrales Gate für den SelfController (Spec §3.4): statt einer
	 * Rollenprüfung zählt ausschließlich `self_service_enabled &&
	 * Kontoverknüpfung`. Bei Erfolg landet die aufgelöste member_id im
	 * {@see ActorContextService} – jede Query des SelfController MUSS darauf
	 * filtern (IDOR-Schutz, siehe SelfController).
	 *
	 * @throws ForbiddenException wenn der Schalter aus ist oder das Konto
	 *                            nicht mit einem Mitglied verknüpft ist
	 */
	private function authorizeSelfService(): void {
		$member = $this->selfService->isEnabled() ? $this->selfService->currentMember() : null;
		if ($member === null) {
			throw new ForbiddenException($this->l10n->t('Kein Self-Service-Zugang.'));
		}
		$this->actorContext->setMemberChannel($member->getId());
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
		if ($exception instanceof PeriodClosedException) {
			return new JSONResponse(['message' => $exception->getMessage()], Http::STATUS_LOCKED);
		}
		// Ein Zeitraum, den es nicht (mehr) gibt. Der praktische Fall ist keine
		// falsche URL, sondern eine im Browser stehengebliebene Zeitraum-Auswahl,
		// nachdem jemand anderes die Geschäftsjahr-Regel umgestellt hat. 404 mit
		// der Bitte, neu zu laden, ist ehrlicher als eine Serverfehlerseite.
		if ($exception instanceof PeriodNotFoundException) {
			return new JSONResponse(['message' => $exception->getMessage()], Http::STATUS_NOT_FOUND);
		}
		throw $exception;
	}
}
