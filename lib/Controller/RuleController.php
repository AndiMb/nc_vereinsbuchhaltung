<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BookingCostCenterMapper;
use OCA\Vereinsbuchhaltung\Db\Rule;
use OCA\Vereinsbuchhaltung\Db\RuleMapper;
use OCA\Vereinsbuchhaltung\Db\RulePart;
use OCA\Vereinsbuchhaltung\Db\RulePartMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Service\CostCenterSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

class RuleController extends Controller {

	use BookContext;

	public function __construct(
		IRequest $request,
		private RuleMapper $mapper,
		private IL10N $l10n,
		private BookingCostCenterMapper $costCenters,
		private CostCenterSettings $costCenterSettings,
		private RulePartMapper $parts,
		private AccountMapper $accounts,
		private TransactionRunner $transaction,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): DataResponse {
		return new DataResponse($this->mapper->findAll($this->userId()));
	}

	#[NoAdminRequired]
	/** @param list<array<string, mixed>>|null $parts feste Anteile (Issue #163), Beträge in Euro */
	public function create(string $matchField, string $matchValue, int $contraAccountId, int $priority = 0, ?int $costCenterId = null, ?array $parts = null): DataResponse {
		if (!$this->isValid($matchField, $matchValue, $contraAccountId)) {
			return new DataResponse(['message' => $this->l10n->t('Ungültige Regel (Feld, Suchtext oder Gegenkonto fehlt).')], Http::STATUS_BAD_REQUEST);
		}
		$rule = new Rule();
		$error = $this->applyCostCenter($rule, $costCenterId);
		$newParts = $this->parseParts($parts, $error);
		if ($error !== null) {
			return new DataResponse(['message' => $error], Http::STATUS_BAD_REQUEST);
		}
		$rule->setUserId($this->userId());
		$rule->setMatchField($matchField);
		$rule->setMatchValue(trim($matchValue));
		$rule->setContraAccountId($contraAccountId);
		$rule->setPriority($priority);
		return new DataResponse($this->save($rule, $newParts, true), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	/** @param list<array<string, mixed>>|null $parts feste Anteile (Issue #163), Beträge in Euro */
	public function update(int $id, string $matchField, string $matchValue, int $contraAccountId, int $priority = 0, ?int $costCenterId = null, ?array $parts = null): DataResponse {
		if (!$this->isValid($matchField, $matchValue, $contraAccountId)) {
			return new DataResponse(['message' => $this->l10n->t('Ungültige Regel (Feld, Suchtext oder Gegenkonto fehlt).')], Http::STATUS_BAD_REQUEST);
		}
		try {
			$rule = $this->mapper->find($id, $this->userId());
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Regel nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
		$error = $this->applyCostCenter($rule, $costCenterId);
		$newParts = $this->parseParts($parts, $error);
		if ($error !== null) {
			return new DataResponse(['message' => $error], Http::STATUS_BAD_REQUEST);
		}
		$rule->setMatchField($matchField);
		$rule->setMatchValue(trim($matchValue));
		$rule->setContraAccountId($contraAccountId);
		$rule->setPriority($priority);
		return new DataResponse($this->save($rule, $newParts, false));
	}

	/**
	 * Speichert Regel und Anteile gemeinsam. $parts === null: die
	 * gespeicherten Anteile bleiben, wie sie sind.
	 *
	 * @param list<RulePart>|null $parts
	 */
	private function save(Rule $rule, ?array $parts, bool $isNew): Rule {
		return $this->transaction->run(function () use ($rule, $parts, $isNew): Rule {
			$kept = $rule->getPartsList();
			$saved = $isNew ? $this->mapper->insert($rule) : $this->mapper->update($rule);
			$saved->setPartsList($parts === null ? $kept : $this->parts->replaceForRule($this->userId(), (int)$saved->getId(), $parts));
			return $saved;
		});
	}

	/**
	 * Feste Anteile aus dem Request (Issue #163). Fehlt `parts` im Request
	 * (alter Client), kommt null zurück: die gespeicherten Anteile bleiben.
	 * Jeder Anteil braucht ein vorhandenes Konto und einen Betrag > 0; eine
	 * Kostenstelle gilt wie an der Regel selbst.
	 *
	 * @param list<array<string, mixed>>|null $raw
	 * @param string|null $error Fehlermeldung; ist schon eine gesetzt, wird nichts geprüft
	 * @return list<RulePart>|null
	 */
	private function parseParts(?array $raw, ?string &$error): ?array {
		if ($error !== null || !array_key_exists('parts', $this->request->getParams())) {
			return null;
		}
		$parts = [];
		foreach ($raw ?? [] as $item) {
			$accountId = (int)($item['accountId'] ?? 0);
			// Je Anteil auf Cent runden, wie bei der Aufteilung von Hand.
			$cents = (int)round(((float)($item['amount'] ?? 0)) * 100);
			if ($accountId <= 0 || $cents <= 0) {
				$error = $this->l10n->t('Ungültiger Anteil (Konto oder Betrag fehlt).');
				return null;
			}
			try {
				$this->accounts->find($accountId, $this->userId());
			} catch (DoesNotExistException) {
				$error = $this->l10n->t('Konto nicht gefunden');
				return null;
			}
			$costCenterId = (int)($item['costCenterId'] ?? 0);
			if ($costCenterId > 0) {
				$error = $this->costCenterError($costCenterId);
				if ($error !== null) {
					return null;
				}
			}
			$part = new RulePart();
			$part->setAccountId($accountId);
			$part->setAmountCents($cents);
			$part->setCostCenterId($costCenterId > 0 ? $costCenterId : null);
			$parts[] = $part;
		}
		return $parts;
	}

	/**
	 * Vorgabe-Kostenstelle der Regel (Issue #331) setzen.
	 *
	 * Fehlt der Parameter im Request (alter Client, Formular bei
	 * ausgeschaltetem Modul), bleibt die gespeicherte Vorgabe stehen. 0 oder
	 * null entfernt sie. Ein Wert wird nur bei eingeschaltetem Modul
	 * angenommen und muss eine vorhandene Kostenstelle sein.
	 *
	 * @return string|null Fehlermeldung, oder null
	 */
	private function applyCostCenter(Rule $rule, ?int $costCenterId): ?string {
		if (!array_key_exists('costCenterId', $this->request->getParams())) {
			return null;
		}
		if ($costCenterId === null || $costCenterId <= 0) {
			$rule->setCostCenterId(null);
			return null;
		}
		$error = $this->costCenterError($costCenterId);
		if ($error === null) {
			$rule->setCostCenterId($costCenterId);
		}
		return $error;
	}

	/** Darf diese Kostenstelle vorgegeben werden? null = ja, sonst die Meldung. */
	private function costCenterError(int $costCenterId): ?string {
		if (!$this->costCenterSettings->isEnabled()) {
			return $this->l10n->t('Kostenstellen sind in den Einstellungen ausgeschaltet.');
		}
		try {
			$this->costCenters->find($costCenterId, $this->userId());
		} catch (DoesNotExistException) {
			return $this->l10n->t('Kostenstelle nicht gefunden');
		}
		return null;
	}

	private function isValid(string $matchField, string $matchValue, int $contraAccountId): bool {
		return in_array($matchField, ['counterparty', 'purpose', 'iban'], true)
			&& trim($matchValue) !== ''
			&& $contraAccountId > 0;
	}

	#[NoAdminRequired]
	public function destroy(int $id): DataResponse {
		try {
			$rule = $this->mapper->find($id, $this->userId());
			$this->transaction->run(function () use ($rule): void {
				$this->parts->deleteByRule($this->userId(), (int)$rule->getId());
				$this->mapper->delete($rule);
			});
			return new DataResponse([]);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Regel nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}
}
