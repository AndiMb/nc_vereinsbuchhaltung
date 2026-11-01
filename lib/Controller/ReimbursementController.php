<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\ReimbursementMapper;
use OCA\Vereinsbuchhaltung\Exception\ForbiddenException;
use OCA\Vereinsbuchhaltung\Http\ReceiptPreviewPolicy;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\ActorContextService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCA\Vereinsbuchhaltung\Service\ReimbursementFileService;
use OCA\Vereinsbuchhaltung\Service\ReimbursementPresenter;
use OCA\Vereinsbuchhaltung\Service\ReimbursementService;
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

	public function __construct(
		IRequest $request,
		private ActorContextService $actor,
		private PermissionService $permissions,
		private ReimbursementService $service,
		private ReimbursementPresenter $presenter,
		private ReimbursementMapper $mapper,
		private ReimbursementFileService $fileService,
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
