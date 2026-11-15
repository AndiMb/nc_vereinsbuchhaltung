<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\FrontendStateService;
use OCA\Vereinsbuchhaltung\Service\PeriodRule;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Geschäftsjahre: Liste, Regel, Grenzen, Festschreibung.
 *
 * Löst den YearController ab, der nur Abschließen und Wiedereröffnen kannte –
 * ein Geschäftsjahr war bis 0.32.0 nichts, was man hätte einstellen können
 * (Issue #8).
 *
 * Rollenaufteilung wie dort: die Liste darf jeder Leseberechtigte sehen, jede
 * Änderung ist Verwaltern vorbehalten.
 */
class PeriodController extends Controller {
	use BookContext;

	public function __construct(
		IRequest $request,
		private PeriodService $periods,
		private PermissionService $permissionService,
		private IUserSession $userSession,
		private IL10N $l10n,
		private FrontendStateService $frontendState,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Alle Geschäftsjahre, neueste zuerst. Inhalt (und warum dabei der
	 * laufende Zeitraum angelegt wird) siehe FrontendStateService::periods();
	 * beim Seitenaufruf liegt dieselbe Liste schon als Initial State in der
	 * Seite (PageController).
	 */
	#[NoAdminRequired]
	public function index(): DataResponse {
		return new DataResponse($this->frontendState->periods());
	}

	/** Die geltende Geschäftsjahr-Regel samt der wählbaren Vorlagen. */
	#[NoAdminRequired]
	public function rule(): DataResponse {
		return new DataResponse([
			'rule' => $this->periods->rule(),
			'presets' => PeriodRule::PRESETS,
			'lengths' => PeriodRule::LENGTHS,
		]);
	}

	/**
	 * Stellt die Regel um – oder zeigt mit `dryRun` nur, was sie bewirken würde.
	 *
	 * Die Vorschau ist kein Beiwerk: die Umstellung ordnet jede Buchung neu zu
	 * und kann Planwerte zusammenführen. Wer das auslöst, soll vorher gesehen
	 * haben, was passiert.
	 */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function saveRule(): DataResponse {
		$denied = $this->denyIfNotAdmin();
		if ($denied !== null) {
			return $denied;
		}

		$params = $this->request->getParams();
		$rule = [
			'preset' => (string)($params['preset'] ?? PeriodRule::PRESET_CUSTOM),
			'startDay' => (int)($params['startDay'] ?? 1),
			'startMonth' => (int)($params['startMonth'] ?? 1),
			'lengthMonths' => (int)($params['lengthMonths'] ?? 12),
		];
		$dryRun = filter_var($params['dryRun'] ?? false, FILTER_VALIDATE_BOOLEAN);

		try {
			if ($dryRun) {
				return new DataResponse($this->periods->previewRule($this->userId(), $rule));
			}
			$uid = $this->userSession->getUser()?->getUID() ?? '?';
			return new DataResponse($this->periods->applyRule($this->userId(), $rule, $uid));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Bezeichnung und/oder Ende eines Zeitraums ändern.
	 *
	 * Beides in einem Endpunkt, weil die Einstellungsseite beides in derselben
	 * Zeile anbietet. Das Verschieben des Endes zieht den Beginn des folgenden
	 * Zeitraums mit – sonst entstünde eine Lücke.
	 */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function update(int $id, ?string $label = null, ?string $endDate = null): DataResponse {
		$denied = $this->denyIfNotAdmin();
		if ($denied !== null) {
			return $denied;
		}

		$endDate = $endDate === '' ? null : $endDate;
		$label = $label === '' ? null : $label;
		if ($endDate !== null && !self::isIsoDate($endDate)) {
			return new DataResponse(['message' => $this->l10n->t('Ungültiges Datum.')], Http::STATUS_BAD_REQUEST);
		}

		try {
			$period = $this->periods->update($this->userId(), $id, $label, $endDate);
			return new DataResponse($period->jsonSerialize());
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Legt einen Zeitraum am Rand der Kette an: nach dem bisher letzten
	 * (Vorgabe) oder mit `position=before` vor dem bisher ersten (Issue #349).
	 */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function create(string $position = 'after'): DataResponse {
		$denied = $this->denyIfNotAdmin();
		if ($denied !== null) {
			return $denied;
		}
		try {
			$period = match ($position) {
				'after' => $this->periods->appendNext($this->userId()),
				'before' => $this->periods->prependPrevious($this->userId()),
				default => throw new \InvalidArgumentException($this->l10n->t('Ungültige Position.')),
			};
			return new DataResponse($period->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/** Entfernt einen leeren Zeitraum am Rand der Kette. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function destroy(int $id): DataResponse {
		$denied = $this->denyIfNotAdmin();
		if ($denied !== null) {
			return $denied;
		}
		try {
			$this->periods->delete($this->userId(), $id);
			return new DataResponse([]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function close(int $id): DataResponse {
		$denied = $this->denyIfNotAdmin();
		if ($denied !== null) {
			return $denied;
		}
		$uid = $this->userSession->getUser()?->getUID() ?? '?';
		return new DataResponse($this->periods->close($this->userId(), $id, $uid)->jsonSerialize(), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function reopen(int $id): DataResponse {
		$denied = $this->denyIfNotAdmin();
		if ($denied !== null) {
			return $denied;
		}
		$this->periods->reopen($this->userId(), $id);
		return new DataResponse([]);
	}

	/**
	 * Die Rollenprüfung als zweite Schicht.
	 *
	 * Die Middleware hat die Rolle bereits geprüft; diese Prüfung bleibt
	 * stehen, damit ein Fehler in der Verdrahtung nicht gleich die
	 * Festschreibung öffnet. Sie stand aus demselben Grund schon im
	 * YearController.
	 */
	private function denyIfNotAdmin(): ?DataResponse {
		if ($this->permissionService->isAdmin()) {
			return null;
		}
		return new DataResponse(
			['message' => $this->l10n->t('Geschäftsjahre zu ändern ist Verwaltern vorbehalten.')],
			Http::STATUS_FORBIDDEN,
		);
	}

	private static function isIsoDate(string $date): bool {
		return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) === 1
			&& checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
	}
}
