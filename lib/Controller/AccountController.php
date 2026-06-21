<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\AccountService;
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
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function userId(): string {
		return $this->userSession->getUser()->getUID();
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
	public function create(string $number, string $name, string $type, ?string $category = null, bool $isBank = false): DataResponse {
		try {
			$account = $this->service->create($this->userId(), $number, $name, $type, $category, $isBank);
			return new DataResponse($account, Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	public function update(int $id, ?string $number = null, ?string $name = null, ?string $type = null, ?string $category = null, ?bool $isBank = null, ?bool $active = null): DataResponse {
		$data = array_filter([
			'number' => $number,
			'name' => $name,
			'type' => $type,
			'category' => $category,
			'isBank' => $isBank,
			'active' => $active,
		], static fn ($v) => $v !== null);
		try {
			return new DataResponse($this->service->update($id, $this->userId(), $data));
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Konto nicht gefunden'], Http::STATUS_NOT_FOUND);
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
}
