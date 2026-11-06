<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Service\BookingService;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

class TransactionController extends Controller {

	use BookContext;

	public function __construct(
		IRequest $request,
		private BankTransactionMapper $txMapper,
		private BookingService $bookingService,
		private PeriodService $periods,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(?string $status = null, int $limit = 10000, int $offset = 0): DataResponse {
		$items = $this->txMapper->findFiltered($this->userId(), $status, $limit, $offset);
		return new DataResponse($items);
	}

	/**
	 * Kennzahlen zu den bereits zugeordneten Umsätzen (Issue #255).
	 *
	 * „Zuzuordnen" lädt nur noch offene Umsätze; was die Oberfläche von den
	 * zugeordneten braucht, kommt hier verdichtet:
	 *  - history: je Zahlungspartner und Gegenkonto die Zahl der bisherigen
	 *    Zuordnungen (Grundlage der Vorschläge),
	 *  - progress: zugeordnet von allen Umsätzen (Fortschrittsbalken),
	 *  - byJournal: die Umsätze, aus denen Buchungen des Zeitraums entstanden
	 *    sind, je Journal-ID (für „Regel anlegen" im Journal).
	 */
	#[NoAdminRequired]
	public function context(?int $period = null): DataResponse {
		$userId = $this->userId();
		[$from, $to] = $this->periods->range($userId, $period);
		$counts = $this->txMapper->statusCounts($userId);

		$byJournal = [];
		foreach ($this->txMapper->findAssignedInRange($userId, $from, $to) as $tx) {
			$journalId = $tx->getJournalId();
			if ($journalId && $tx->getCounterparty() && $tx->getContraAccountId()) {
				$byJournal[$journalId] = $tx;
			}
		}

		return new DataResponse([
			'history' => $this->txMapper->counterpartyHistory($userId),
			'progress' => ['total' => $counts['total'], 'assigned' => $counts['assigned']],
			// Leer wäre in JSON ein Array statt eines Objekts.
			'byJournal' => $byJournal === [] ? new \stdClass() : $byJournal,
		]);
	}

	/**
	 * Ordnet einen Umsatz einem Gegenkonto zu – oder, mit $parts, aufgeteilt
	 * mehreren.
	 *
	 * @param array $parts Aufteilung: [{accountId, amount}, …] mit Beträgen in
	 *                     Euro. Ist der Parameter gesetzt, wird $contraAccountId nicht
	 *                     ausgewertet; die Summe der Teile muss den Umsatz ergeben.
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
			return new DataResponse(['message' => $this->l10n->t('Buchung oder Konto nicht gefunden')], Http::STATUS_NOT_FOUND);
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
			return new DataResponse(['message' => $this->l10n->t('Buchung nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Löscht einen noch nicht zugeordneten Bankumsatz – etwa eine Dublette aus
	 * einem sich überschneidenden Kontoauszugs-Import, die der Dublettenabgleich
	 * nicht erkannt hat.
	 */
	#[NoAdminRequired]
	public function destroy(int $id): DataResponse {
		try {
			$tx = $this->txMapper->find($id, $this->userId());
			$this->bookingService->delete($tx);
			return new DataResponse([]);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Buchung nicht gefunden')], Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
