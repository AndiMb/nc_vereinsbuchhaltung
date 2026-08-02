<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\OpenItemService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

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
	public function cancel(int $id): DataResponse {
		try {
			$item = $this->service->cancel($id);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Offener Posten nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
		$this->audit->log('Offener Posten storniert', 'open_item', $id, ['debtor' => $item->getDebtor()]);
		return new DataResponse($item);
	}

	#[NoAdminRequired]
	public function reopen(int $id): DataResponse {
		try {
			$item = $this->service->reopen($id);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Offener Posten nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse($item);
	}

	#[NoAdminRequired]
	public function destroy(int $id): DataResponse {
		try {
			$item = $this->service->find($id);
			$this->service->delete($id);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Offener Posten nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
		$this->audit->log('Offener Posten gelöscht', 'open_item', $id, ['debtor' => $item->getDebtor()]);
		return new DataResponse([]);
	}
}
