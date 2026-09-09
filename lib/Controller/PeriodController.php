<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\BudgetMapper;
use OCA\Vereinsbuchhaltung\Db\BudgetSnapshotMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\Period;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
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
		private JournalMapper $journalMapper,
		private BudgetMapper $budgetMapper,
		private BudgetSnapshotMapper $snapshotMapper,
		private PermissionService $permissionService,
		private IUserSession $userSession,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Alle Geschäftsjahre, neueste zuerst.
	 *
	 * Der laufende Zeitraum wird dabei angelegt, falls er noch fehlt: sonst
	 * stünde er nicht im Auswahlfeld der Kopfzeile, und eine frisch
	 * eingerichtete App hätte gar keinen. Bis 0.32.0 erledigte das der
	 * unbedingt angehängte `date('Y')` in der Jahresliste.
	 *
	 * Ja, das schreibt in einer GET-Anfrage, und auch ein Nutzer mit reinem
	 * Leserecht löst es aus. Bewusst so: die Zeile trägt keine Daten, sie ist
	 * genau die, die die eingestellte Regel für heute vorsieht, und sie
	 * entsteht höchstens einmal je Geschäftsjahr. Die Alternative wäre ein
	 * Auswahleintrag ohne ID, den kein Endpunkt annehmen könnte – oder ein
	 * Auswahlfeld, in dem am 1. Oktober das neue Geschäftsjahr fehlt, bis
	 * jemand die erste Buchung anlegt.
	 */
	#[NoAdminRequired]
	public function index(): DataResponse {
		$userId = $this->userId();
		$this->periods->current($userId);

		$bookings = $this->journalMapper->countsByPeriod($userId);
		$budgets = $this->budgetMapper->countsByPeriod($userId);
		$snapshots = $this->snapshotMapper->countsByPeriod($userId);

		$rows = [];
		foreach ($this->periods->all($userId) as $period) {
			$id = (int)$period->getId();
			$rows[] = $period->jsonSerialize() + [
				'bookings' => $bookings[$id] ?? 0,
				'planValues' => ($budgets[$id] ?? 0) + ($snapshots[$id] ?? 0),
			];
		}
		return new DataResponse($rows);
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

		try {
			if ($endDate !== null && $endDate !== '') {
				if (!self::isIsoDate($endDate)) {
					return new DataResponse(['message' => $this->l10n->t('Ungültiges Datum.')], Http::STATUS_BAD_REQUEST);
				}
				$this->periods->moveEnd($this->userId(), $id, $endDate);
			}
			$period = $label !== null && $label !== ''
				? $this->periods->updateLabel($this->userId(), $id, $label)
				: $this->periods->find($this->userId(), $id);
			return new DataResponse($period->jsonSerialize());
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/** Legt den Zeitraum nach dem bisher letzten an. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function create(): DataResponse {
		$denied = $this->denyIfNotAdmin();
		if ($denied !== null) {
			return $denied;
		}
		return new DataResponse($this->periods->appendNext($this->userId())->jsonSerialize(), Http::STATUS_CREATED);
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
