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
		return Application::BOOK;
	}

	#[NoAdminRequired]
	public function index(?string $status = null, int $limit = 10000, int $offset = 0): DataResponse {
		$items = $this->txMapper->findFiltered($this->userId(), $status, $limit, $offset);
		return new DataResponse($items);
	}

	/**
	 * Ordnet einen Umsatz einem Gegenkonto zu – oder, mit $parts, aufgeteilt
	 * mehreren.
	 *
	 * @param array $parts Aufteilung: [{accountId, amount}, …] mit Beträgen in
	 *        Euro. Ist der Parameter gesetzt, wird $contraAccountId nicht
	 *        ausgewertet; die Summe der Teile muss den Umsatz ergeben.
	 */
	#[NoAdminRequired]
	public function assign(int $id, int $contraAccountId = 0, array $parts = []): DataResponse {
		try {
			$tx = $this->txMapper->find($id, $this->userId());
			if ($parts === []) {
				$tx = $this->bookingService->assign($tx, $contraAccountId);
			} else {
				// Beträge kommen in Euro und werden je Teil einzeln auf Cent
				// gerundet – erst danach prüft validateParts() die Summe.
				$tx = $this->bookingService->assignParts($tx, array_map(
					static fn ($part): array => [
						'accountId' => (int)($part['accountId'] ?? 0),
						'amountCents' => (int)round(((float)($part['amount'] ?? 0)) * 100),
					],
					array_values(array_filter($parts, 'is_array')),
				));
			}
			return new DataResponse($tx);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Buchung oder Konto nicht gefunden'], Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
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
