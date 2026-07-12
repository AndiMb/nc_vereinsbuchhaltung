<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\AccountService;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\OpeningBalanceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

class AccountController extends Controller {

	public function __construct(
		IRequest $request,
		private AccountService $service,
		private OpeningBalanceService $openingService,
		private IUserSession $userSession,
		private AuditService $audit,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function userId(): string {
		// Gemeinsamer Datenbestand des Vereins; Zugriff regelt die Rechteprüfung.
		return Application::BOOK;
	}

	#[NoAdminRequired]
	public function index(): DataResponse {
		return new DataResponse($this->service->findAll($this->userId()));
	}

	#[NoAdminRequired]
	public function show(int $id): DataResponse {
		try {
			return new DataResponse($this->service->find($id, $this->userId()));
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Konto nicht gefunden'], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	public function create(string $number, string $name, string $type, ?string $category = null, bool $isBank = false, ?int $parentId = null, ?string $sphere = null): DataResponse {
		try {
			$account = $this->service->create($this->userId(), $number, $name, $type, $category, $isBank, $parentId, $sphere);
			return new DataResponse($account, Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * @param int $parentId 0 = kein Überkonto (Wurzel); >0 = ID des Überkontos.
	 *                       Die Account-Bearbeitung sendet das Feld immer mit.
	 * @param string|null $sphere '' = nicht zugeordnet (löscht eine ggf. gesetzte Sphäre).
	 */
	#[NoAdminRequired]
	public function update(int $id, ?string $number = null, ?string $name = null, ?string $type = null, ?string $category = null, ?bool $isBank = null, ?bool $active = null, int $parentId = 0, ?string $sphere = null): DataResponse {
		$data = array_filter([
			'number' => $number,
			'name' => $name,
			'type' => $type,
			'category' => $category,
			'isBank' => $isBank,
			'active' => $active,
			'sphere' => $sphere,
		], static fn ($v) => $v !== null);
		// parentId immer übernehmen (0 = Wurzel), damit Umhängen/Lösen möglich ist.
		$data['parentId'] = $parentId;
		try {
			return new DataResponse($this->service->update($id, $this->userId(), $data));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Konto nicht gefunden'], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Mehrere Konten auf einmal einer Sphäre zuordnen (Bulk-Zuordnung, siehe
	 * SettingsSpheres.vue) – erspart bei Bestandsvereinen das Konto-für-Konto-Bearbeiten.
	 *
	 * @param int[] $accountIds
	 */
	#[NoAdminRequired]
	public function bulkSphere(array $accountIds, string $sphere): DataResponse {
		try {
			$count = $this->service->bulkSetSphere($this->userId(), $accountIds, $sphere);
			$this->audit->log('Sphären zugeordnet', 'account', null, ['anzahl' => $count, 'sphere' => $sphere]);
			return new DataResponse(['updated' => $count]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	public function destroy(int $id): DataResponse {
		try {
			$this->service->delete($id, $this->userId());
			return new DataResponse([]);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Konto nicht gefunden'], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	public function seedDefaults(): DataResponse {
		return new DataResponse($this->service->seedDefaults($this->userId()));
	}

	/**
	 * Eröffnungssaldo setzen (Betrag in Euro) und Eröffnungsbuchung erzeugen.
	 */
	#[NoAdminRequired]
	public function setOpening(int $id, float $amount = 0, ?string $date = null): DataResponse {
		try {
			$cents = (int)round($amount * 100);
			$account = $this->service->setOpeningFields($id, $this->userId(), $cents, $date);
			$this->openingService->sync($account);
			$this->audit->log('Eröffnungssaldo gesetzt', 'account', $id, [
				'konto' => $account->getNumber() . ' ' . $account->getName(),
				'amount' => $cents / 100,
				'date' => $account->getOpeningDate(),
			]);
			return new DataResponse($account);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Konto nicht gefunden'], Http::STATUS_NOT_FOUND);
		}
	}
}
