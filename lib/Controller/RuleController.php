<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Rule;
use OCA\Vereinsbuchhaltung\Db\RuleMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

class RuleController extends Controller {

	public function __construct(
		IRequest $request,
		private RuleMapper $mapper,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function userId(): string {
		return Application::BOOK;
	}

	#[NoAdminRequired]
	public function index(): DataResponse {
		return new DataResponse($this->mapper->findAll($this->userId()));
	}

	#[NoAdminRequired]
	public function create(string $matchField, string $matchValue, int $contraAccountId, int $priority = 0): DataResponse {
		if (!in_array($matchField, ['counterparty', 'purpose', 'iban'], true)) {
			return new DataResponse(['message' => 'Ungültiges Feld'], Http::STATUS_BAD_REQUEST);
		}
		$rule = new Rule();
		$rule->setUserId($this->userId());
		$rule->setMatchField($matchField);
		$rule->setMatchValue(trim($matchValue));
		$rule->setContraAccountId($contraAccountId);
		$rule->setPriority($priority);
		return new DataResponse($this->mapper->insert($rule), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function destroy(int $id): DataResponse {
		try {
			$rule = $this->mapper->find($id, $this->userId());
			$this->mapper->delete($rule);
			return new DataResponse([]);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Regel nicht gefunden'], Http::STATUS_NOT_FOUND);
		}
	}
}
