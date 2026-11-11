<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\NumberRange;
use OCA\Vereinsbuchhaltung\Db\ReimbursementMapper;
use OCA\Vereinsbuchhaltung\Exception\ForbiddenException;
use OCA\Vereinsbuchhaltung\Http\ReceiptPreviewPolicy;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\ActorContextService;
use OCA\Vereinsbuchhaltung\Service\CostCenter\AllocationRules;
use OCA\Vereinsbuchhaltung\Service\NumberRangeService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCA\Vereinsbuchhaltung\Service\ReimbursementFileService;
use OCA\Vereinsbuchhaltung\Service\ReimbursementMatchingService;
use OCA\Vereinsbuchhaltung\Service\ReimbursementPresenter;
use OCA\Vereinsbuchhaltung\Service\ReimbursementService;
use OCA\Vereinsbuchhaltung\Service\ReimbursementTransferService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Auslagenerstattung aus Sicht der Verwaltung (Reiter „Buchungen → Auslagen“):
 * alle Anträge lesen (Revisor), freigeben und ablehnen (Verwalter) und die
 * Auszahlung vermerken (Buchhalter).
 *
 * Die Rechte stehen an jeder Methode. Zusätzlich prüft der
 * {@see ReimbursementService}, dass nur ein Verwalter freigibt und niemand
 * seinen eigenen Antrag - die Rolle allein reicht dafür nicht.
 */
class ReimbursementController extends Controller {
	use BookContext;

	public function __construct(
		IRequest $request,
		private ActorContextService $actor,
		private PermissionService $permissions,
		private ReimbursementService $service,
		private ReimbursementPresenter $presenter,
		private ReimbursementMapper $mapper,
		private ReimbursementFileService $fileService,
		private ReimbursementTransferService $transfer,
		private ReimbursementMatchingService $matching,
		private BankTransactionMapper $txMapper,
		private NumberRangeService $numberRanges,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function uid(): string {
		return $this->actor->actorUid() ?? '?';
	}

	/** Revisoren sehen die IBAN nur maskiert. */
	private function fullIban(): bool {
		return $this->permissions->canWrite();
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function index(): DataResponse {
		$uid = $this->uid();
		$fullIban = $this->fullIban();
		$all = $this->mapper->findAll();
		$this->presenter->preload($all);
		return new DataResponse([
			'items' => array_map(fn ($r) => $this->presenter->forStaff($r, $uid, $fullIban), $all),
		]);
	}

	/** Zahlen für die Übersichtskachel „Offene Auslagen“. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function summary(): DataResponse {
		return new DataResponse($this->service->openSummary());
	}

	/**
	 * Für „Zuzuordnen“: ob eine Auslage zu bezahlen ist (`payable`) und Zuordnungs-
	 * vorschläge für die unzugeordneten Abgänge, als Objekt Umsatz-ID => Vorschläge.
	 */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function suggestions(): DataResponse {
		$approved = $this->mapper->findApproved();
		if ($approved === []) {
			return new DataResponse(['payable' => false, 'suggestions' => new \stdClass()]);
		}
		$txs = $this->txMapper->findFiltered($this->userId(), 'unassigned', 1000, 0);
		return new DataResponse(['payable' => true, 'suggestions' => (object)$this->matching->suggestForMany($txs, $approved)]);
	}

	/** Der Nummernkreis der Auslagenanträge (Einstellungen → Auslagenerstattung). */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function numberRange(): DataResponse {
		return new DataResponse($this->numberRanges->ensure(NumberRange::KIND_REIMBURSEMENT));
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function updateNumberRange(string $prefixPattern, string $resetMode, ?int $nextNo = null): DataResponse {
		try {
			return new DataResponse($this->numberRanges->update(NumberRange::KIND_REIMBURSEMENT, $prefixPattern, $resetMode, $nextNo));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function show(int $id): DataResponse {
		try {
			$reimbursement = $this->service->find($id);
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
		return new DataResponse($this->presenter->forStaff($reimbursement, $this->uid(), $this->fullIban(), true));
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function approve(int $id): DataResponse {
		return $this->decide(fn () => $this->service->approve($id, $this->uid()));
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function reject(int $id, string $reason = ''): DataResponse {
		return $this->decide(fn () => $this->service->reject($id, $this->uid(), $reason));
	}

	/**
	 * Überweisungsdaten mit GiroCode für eine freigegebene Auslage. Nur für
	 * Buchhalter und Verwalter wie die Auszahlung selbst: die Antwort trägt die
	 * volle IBAN.
	 */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function transfer(int $id): DataResponse {
		try {
			return new DataResponse($this->transfer->prepare($id));
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/** Die Überweisung ist angewiesen; gebucht wird mit dem Kontoumsatz. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function transferred(int $id): DataResponse {
		return $this->decide(fn () => $this->service->markTransferred($id, $this->uid()));
	}

	/** Nimmt die Vormerkung „Überweisung angewiesen“ zurück. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function notTransferred(int $id): DataResponse {
		return $this->decide(fn () => $this->service->unmarkTransferred($id, $this->uid()));
	}

	/** Nimmt die Auszahlung zurück und löscht ihre Buchung. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function revertPayment(int $id): DataResponse {
		return $this->decide(fn () => $this->service->revertPayment($id));
	}

	/**
	 * Ordnet einen Kontoumsatz der Auslage zu und bucht ihn gegen das gewählte
	 * Aufwandskonto.
	 *
	 * @param array|null $allocations Verteilung auf Kostenstellen, Beträge in Euro (Issue #333)
	 */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function assignTransaction(int $id, int $bankTxId, int $contraAccountId, ?array $allocations = null): DataResponse {
		return $this->decide(fn () => $this->service->payFromBankTransaction(
			$id,
			$this->txMapper->find($bankTxId, $this->userId()),
			$contraAccountId,
			$this->uid(),
			AllocationRules::parseEuro(['allocations' => $allocations]),
		));
	}

	/**
	 * Vermerkt die Überweisung. Die Buchung hat der Buchungsdialog schon
	 * angelegt; hier kommt ihre Nummer an.
	 */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function pay(int $id, int $journalId = 0, string $paidDate = ''): DataResponse {
		return $this->decide(fn () => $this->service->markPaid($id, $journalId, $paidDate, $this->uid()));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function receipt(int $id, int $fileId): DataDownloadResponse|DataResponse {
		try {
			$this->service->find($id);
			$file = $this->fileService->findActiveFor($id, $fileId);
			$content = $this->fileService->content($file);
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\RuntimeException) {
			return new DataResponse(['message' => $this->l10n->t('Datei nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
		return ReceiptPreviewPolicy::inlineResponse($content, $file->getFileName(), $file->getMimeType());
	}

	/**
	 * @param callable():\OCA\Vereinsbuchhaltung\Db\Reimbursement $action
	 */
	private function decide(callable $action): DataResponse {
		try {
			$reimbursement = $action();
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (ForbiddenException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($this->presenter->forStaff($reimbursement, $this->uid(), $this->fullIban(), true));
	}

	private function notFound(): DataResponse {
		return new DataResponse(['message' => $this->l10n->t('Nicht gefunden')], Http::STATUS_NOT_FOUND);
	}
}
