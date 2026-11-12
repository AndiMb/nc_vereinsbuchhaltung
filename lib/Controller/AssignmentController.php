<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Middleware\NoRevisionBump;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\AssignmentService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCA\Vereinsbuchhaltung\Service\StaffChangeReceiptService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Pflege der Zuweisungen (siehe {@see AssignmentService}). Diese Endpunkte sind
 * die Admin-Akte – `actorType` ist deshalb hier immer `staff` (Spec §3.9
 * „Personalunion": der **Kanal** entscheidet, nicht die Identität); das
 * Mitglied ändert über den {@see SelfController}.
 *
 * Rollen (Spec §3.9, Issue #119), jede Methode ausdrücklich: alles ab
 * `buchhalter`, auch das Lesen. Zuweisungen gehören zur Personenakte des
 * Mitglieder-Unterreiters (dort „nicht revisor"): sie tragen die Begründung der
 * individuellen Untergrenze, die Ereignisse die Freitext-Vermerke einer
 * Stellvertretung – beides sollen Kassenprüfer nicht über die API lesen, auch
 * wenn die Oberfläche es ihnen gar nicht erst anzeigt. Die Vorschau ist ein
 * POST ohne Schreibwirkung, bleibt aber auf der Rolle der Änderung, weil sie nur
 * im Dialog zum Speichern vorkommt.
 */
class AssignmentController extends Controller {

	public function __construct(
		IRequest $request,
		private AssignmentService $service,
		private StaffChangeReceiptService $receipts,
		private IUserSession $userSession,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function actorUid(): ?string {
		return $this->userSession->getUser()?->getUID();
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function index(?int $memberId = null): DataResponse {
		$assignments = $memberId !== null ? $this->service->findByMember($memberId) : $this->service->findAll();
		return new DataResponse(array_map(fn ($a) => $a->jsonSerialize(), $assignments));
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function create(
		int $memberId,
		int $groupId,
		int $intervalMonths,
		float $monthlyAmount = 0,
		string $paymentMethod = '',
		string $validFrom = '',
		?string $validTo = null,
		?float $minMonthlyAmountOverride = null,
		?string $overrideReason = null,
		// Gruppen mit Jahresbetrag (Issue #270): Beitrag und individuelle Untergrenze als Jahreswerte.
		?float $annualAmount = null,
		?float $minAnnualAmountOverride = null,
	): DataResponse {
		try {
			$assignment = $this->service->create(
				$memberId,
				$groupId,
				$intervalMonths,
				(int)round($monthlyAmount * 100),
				$paymentMethod,
				$validFrom,
				$validTo,
				$minMonthlyAmountOverride !== null ? (int)round($minMonthlyAmountOverride * 100) : null,
				$overrideReason,
				'staff',
				$this->actorUid(),
				annualAmountCents: self::cents($annualAmount),
				minAnnualOverrideCents: self::cents($minAnnualAmountOverride),
			);
			return new DataResponse($assignment->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Beitragsgruppe nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/** Vorschau der ersten (Prorata-)Periode, bevor überhaupt gespeichert wird (Spec §3.4 „Vorschau vor jedem Speichern"). */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	#[NoRevisionBump]
	public function previewNew(int $intervalMonths, string $validFrom, float $monthlyAmount = 0, ?float $annualAmount = null, ?int $groupId = null): DataResponse {
		$transient = new Assignment();
		$transient->setIntervalMonths($intervalMonths);
		$transient->setMonthlyAmountCents((int)round($monthlyAmount * 100));
		$transient->setValidFrom($validFrom);
		if ($annualAmount !== null) {
			// Jahresbetrag (Issue #270): ob das angebrochene Jahr anteilig zählt, steht an der Gruppe.
			if ($groupId === null) {
				return new DataResponse(['message' => $this->l10n->t('Beitragsgruppe nicht gefunden')], Http::STATUS_BAD_REQUEST);
			}
			$transient->setGroupId($groupId);
			$transient->setAnnualAmountCents(self::cents($annualAmount));
		}
		try {
			return new DataResponse($this->service->previewFirstPeriod($transient));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Beitragsgruppe nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	private static function cents(?float $amount): ?int {
		return $amount !== null ? (int)round($amount * 100) : null;
	}

	/**
	 * Vorschau einer Betrags-/Turnusänderung an einer bestehenden Zuweisung („Wirkt ab … · erster Einzug am … · Betrag …“),
	 * ohne zu speichern – dieselbe Rechnung und Prüfung wie {@see update()} und das Self-Service-Gegenstück.
	 */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	#[NoRevisionBump]
	public function previewChange(int $id, ?float $monthlyAmount = null, ?int $intervalMonths = null, ?int $groupId = null, ?string $paymentMethod = null, ?float $annualAmount = null): DataResponse {
		try {
			return new DataResponse($this->service->previewChange(
				$id,
				self::cents($monthlyAmount),
				$intervalMonths,
				$groupId,
				$paymentMethod,
				self::cents($annualAmount),
			));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Zuweisung nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Betrag, Turnus, Beitragsgruppe und/oder Zahlungsart ändern. Die Zahlungsart wirkt sofort; ein
	 * bestehendes Mandat bleibt beim Wechsel auf Überweisung unberührt und wird nur nicht mehr genutzt.
	 * Das Mitglied bekommt dazu eine Quittungsmail
	 * ({@see StaffChangeReceiptService}); in der Antwort steht unter `receipt`, ob sie rausging
	 * (`sent`, `no_email`, `failed`, `not_needed`), damit die Oberfläche es dem Vorstand sagen kann.
	 */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function update(int $id, ?float $monthlyAmount = null, ?int $intervalMonths = null, ?int $groupId = null, ?string $paymentMethod = null, ?float $annualAmount = null): DataResponse {
		try {
			$monthlyAmountCents = self::cents($monthlyAmount);
			$annualAmountCents = self::cents($annualAmount);
			$current = $this->service->find($id);
			$before = [
				// In der Betragsangabe der Gruppe: Jahresbetrag bei Jahresgruppen (Issue #270), sonst Monatsbetrag.
				'amountCents' => $current->amountCents(),
				'intervalMonths' => $current->getIntervalMonths(),
				'groupId' => $current->getGroupId(),
				'paymentMethod' => $current->getPaymentMethod(),
			];
			// Dieselbe Prüfung und Rechnung wie das Speichern gleich; sie liefert „wirkt ab“ und den ersten Einzug für die Mail.
			$preview = $this->service->previewChange($id, $monthlyAmountCents, $intervalMonths, $groupId, $paymentMethod, $annualAmountCents);
			$assignment = $this->service->update(
				$id,
				$monthlyAmountCents,
				$intervalMonths,
				$groupId,
				'staff',
				$this->actorUid(),
				paymentMethod: $paymentMethod,
				annualAmountCents: $annualAmountCents,
			);
			return new DataResponse($assignment->jsonSerialize() + ['receipt' => $this->receipts->send($assignment, $before, $preview)]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Zuweisung nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/** Individuelle Untergrenze setzen/ändern/entfernen – explizit `buchhalter`-only (Issue #68 AK „Rollen"). */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function setMinAmountOverride(int $id, ?float $minMonthlyAmountOverride = null, ?string $overrideReason = null, ?float $minAnnualAmountOverride = null): DataResponse {
		try {
			// Die Zuweisung bestimmt, welche Angabe zählt: bei einem Jahresbetrag (Issue #270) die Jahres-Untergrenze.
			$annual = $this->service->find($id)->isAnnual();
			$assignment = $this->service->setMinAmountOverride(
				$id,
				self::cents($annual ? $minAnnualAmountOverride : $minMonthlyAmountOverride),
				$overrideReason,
				'staff',
				$this->actorUid(),
			);
			return new DataResponse($assignment->jsonSerialize());
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Zuweisung nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function end(int $id, string $validTo): DataResponse {
		try {
			$assignment = $this->service->end($id, $validTo, 'staff', $this->actorUid());
			return new DataResponse($assignment->jsonSerialize());
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Zuweisung nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function events(int $id): DataResponse {
		return new DataResponse(array_map(fn ($e) => $e->jsonSerialize(), $this->service->findEvents($id)));
	}
}
