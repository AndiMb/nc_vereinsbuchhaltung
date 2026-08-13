<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\MemberReferenceValidator;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCA\Vereinsbuchhaltung\Service\SepaBatchService;
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
 * SEPA-Sammeleinzüge: Vorschau, Erzeugen und Herunterladen der pain.008-Datei
 * (siehe {@see SepaBatchService}). Dieselbe Verwalter-Einstufung wie die
 * übrigen SEPA-Endpunkte.
 */
class SepaBatchController extends Controller {

	public function __construct(
		IRequest $request,
		private SepaBatchService $service,
		private MemberReferenceValidator $memberRef,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * @param string|null $executionDate geplanter Fälligkeitstag; nur bis dahin
	 *        fällige Posten kommen mit. Ohne Angabe der Vorschlagstermin.
	 */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function preview(?string $executionDate = null): DataResponse {
		$executionDate = $executionDate !== null && $executionDate !== '' ? $executionDate : $this->service->defaultExecutionDate();
		$rows = array_map(function (array $row): array {
			return [
				'openItem' => $row['openItem'],
				'mandate' => $row['mandate'],
				'debtorName' => $this->memberRef->displayName($row['mandate']->getMemberUid(), $row['mandate']->getMemberLabel()),
				'sequenceType' => $row['sequenceType'],
			];
		}, $this->service->previewEligible($executionDate));
		return new DataResponse(['executionDate' => $executionDate, 'rows' => $rows]);
	}

	/** Die Zeilen eines Einzugs – wer, wie viel, angekündigt, zurückgebucht. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function items(int $id): DataResponse {
		try {
			$rows = array_map(static fn (array $row): array => $row['item']->jsonSerialize() + [
				'debtorName' => $row['debtorName'],
				'mandateReference' => $row['mandateReference'],
				'description' => $row['description'],
			], $this->service->findBatchItems($id));
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Einzug nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse($rows);
	}

	/**
	 * Bucht den Einzug als ausgeführt und schließt die zugehörigen offenen
	 * Posten.
	 *
	 * @param int|null $journalId Buchung der Sammelgutschrift, falls erfasst
	 */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function settle(int $id, ?int $journalId = null): DataResponse {
		try {
			return new DataResponse($this->service->settleBatch($id, $journalId));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Einzug nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/** Verwirft einen versehentlich erzeugten Einzug wieder. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function destroy(int $id): DataResponse {
		try {
			$this->service->deleteBatch($id);
			return new DataResponse([]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Einzug nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/** Nimmt eine falsch erkannte Rücklastschrift zurück. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function revertReturn(int $itemId): DataResponse {
		try {
			return new DataResponse($this->service->revertReturn($itemId));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Zeile nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function index(): DataResponse {
		return new DataResponse($this->service->findAllBatches());
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function create(string $executionDate): DataResponse {
		try {
			$batch = $this->service->createBatch($executionDate);
			return new DataResponse($batch, Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/** Download der pain.008-XML-Datei eines bereits erzeugten Einzugs. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function xml(int $id): DataDownloadResponse|DataResponse {
		try {
			$xml = $this->service->generateXml($id);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Einzug nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
		return new DataDownloadResponse($xml, "sepa-lastschrift-{$id}.xml", 'application/xml; charset=utf-8');
	}
}
