<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Exception\ClaimManagedException;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\OpenItemService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Generische Offene-Posten-Verwaltung (freie Posten wie Handwerkerrechnungen).
 *
 * Forderungen des Beitragsmoduls liegen in derselben Tabelle, werden aber
 * ausschließlich über den {@see \OCA\Vereinsbuchhaltung\Controller\ClaimController}
 * bearbeitet: Bezahlt, Stornieren, Wieder öffnen und Löschen antworten für sie
 * mit 400 und dem Hinweis auf den Einzug-Reiter (Issue #121, siehe
 * {@see OpenItemService}). Lesen (`index`) liefert beide Sorten, Anlegen
 * erzeugt nur freie Posten.
 *
 * Die vier schreibenden Methoden tragen ihre Rolle ausdrücklich (`buchhalter`);
 * das ist dieselbe Stufe, die die Verb-Heuristik der Middleware für POST/DELETE
 * ohnehin ergäbe - der Kern-Controller ändert damit keine Rechte.
 */
class OpenItemController extends Controller {

	public function __construct(
		IRequest $request,
		private OpenItemService $service,
		private AuditService $audit,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): DataResponse {
		return new DataResponse($this->service->findAll());
	}

	#[NoAdminRequired]
	public function create(string $debtor, ?string $description = null, float $amount = 0, ?string $dueDate = null, ?int $accountId = null): DataResponse {
		try {
			$item = $this->service->create($debtor, $description, (int)round($amount * 100), $dueDate, $accountId);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		$this->audit->log('Offener Posten angelegt', 'open_item', $item->getId(), [
			'debtor' => $item->getDebtor(),
			'amount' => $item->getAmountCents() / 100,
			'dueDate' => $item->getDueDate(),
		]);
		return new DataResponse($item, Http::STATUS_CREATED);
	}

	/** Als bezahlt markieren, optional verknüpft mit einer bestehenden Buchung. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function markPaid(int $id, ?int $journalId = null): DataResponse {
		try {
			$item = $this->service->markPaid($id, $journalId);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Offener Posten nicht gefunden')], Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		$this->audit->log('Offener Posten als bezahlt markiert', 'open_item', $id, [
			'debtor' => $item->getDebtor(),
			'journalId' => $journalId,
		]);
		return new DataResponse($item);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function cancel(int $id): DataResponse {
		try {
			$item = $this->service->cancel($id);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Offener Posten nicht gefunden')], Http::STATUS_NOT_FOUND);
		} catch (ClaimManagedException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		$this->audit->log('Offener Posten storniert', 'open_item', $id, ['debtor' => $item->getDebtor()]);
		return new DataResponse($item);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function reopen(int $id): DataResponse {
		try {
			$item = $this->service->reopen($id);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Offener Posten nicht gefunden')], Http::STATUS_NOT_FOUND);
		} catch (ClaimManagedException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($item);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function destroy(int $id): DataResponse {
		try {
			$item = $this->service->find($id);
			$this->service->delete($id);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Offener Posten nicht gefunden')], Http::STATUS_NOT_FOUND);
		} catch (ClaimManagedException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		$this->audit->log('Offener Posten gelöscht', 'open_item', $id, ['debtor' => $item->getDebtor()]);
		return new DataResponse([]);
	}
}
