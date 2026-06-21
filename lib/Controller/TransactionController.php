<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Service\BookingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

class TransactionController extends Controller {

	public function __construct(
		IRequest $request,
		private BankTransactionMapper $txMapper,
		private BookingService $bookingService,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function userId(): string {
		return $this->userSession->getUser()->getUID();
	}

	#[NoAdminRequired]
	public function index(?string $status = null, int $limit = 500, int $offset = 0): DataResponse {
		$items = $this->txMapper->findFiltered($this->userId(), $status, $limit, $offset);
		return new DataResponse($items);
	}

	#[NoAdminRequired]
	public function assign(int $id, int $contraAccountId): DataResponse {
		try {
			$tx = $this->txMapper->find($id, $this->userId());
			$tx = $this->bookingService->assign($tx, $contraAccountId);
			return new DataResponse($tx);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Buchung oder Konto nicht gefunden'], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	public function unassign(int $id): DataResponse {
		try {
			$tx = $this->txMapper->find($id, $this->userId());
			$tx = $this->bookingService->unassign($tx);
			return new DataResponse($tx);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Buchung nicht gefunden'], Http::STATUS_NOT_FOUND);
		}
	}
}
