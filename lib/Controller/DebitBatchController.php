<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\DebitBatch;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\ContributionCycleSettings;
use OCA\Vereinsbuchhaltung\Service\DebitBatchService;
use OCA\Vereinsbuchhaltung\Service\DebitBatchXmlStorageService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
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
 * Freigabe & Einreichung des Einzugszyklus (Spec §2.2/§3.5, Issue #71) – siehe
 * {@see DebitBatchService}. Rollen laut Spec §3.9: Lauf-Freigabe/Einreichung/
 * Terminverschiebung nur `buchhalter`; „Einzug-Unterreiter lesend (Läufe,
 * Forderungen, …), IBAN maskiert" ab `revisor` – die Maskierung besorgt
 * bereits {@see \OCA\Vereinsbuchhaltung\Db\DebitItem::jsonSerialize()} selbst,
 * unabhängig von der tatsächlichen Rolle (anders als beim Mitglieder-
 * Unterreiter, siehe {@see MandateController::decorate()}). Der XML-Download
 * enthält dagegen die volle IBAN und bleibt deshalb `buchhalter`.
 *
 * Modul-Konvention (Spec §3.9): „jede neue Controller-Methode trägt explizit
 * #[RequiresRole] – die Fail-open-Verb-Heuristik des Gefäßes wird im Modul
 * nicht genutzt."
 */
class DebitBatchController extends Controller {

	public function __construct(
		IRequest $request,
		private DebitBatchService $service,
		private DebitBatchXmlStorageService $xmlStorage,
		private ContributionCycleSettings $settings,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** @return array<string,mixed> */
	private function decorate(DebitBatch $batch): array {
		$items = $this->service->findItems((int)$batch->getId());
		$data = $batch->jsonSerialize();
		$data['itemCount'] = count($items);
		$data['sumCents'] = array_sum(array_column($items, 'amountCents'));
		// Abweichungspruefung nur fuer das Freigabe->Einreichung-Fenster
		// sinnvoll (Spec §3.5) - ein eingereichter/verworfener Lauf ist bereits
		// abgeschlossen, eine "Abweichung" waere folgenlose Nacharbeit.
		$data['driftWarning'] = $batch->getStatus() === DebitBatch::STATUS_RELEASED
			? $this->service->driftWarning((int)$batch->getId())
			: null;
		return $data;
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function index(): DataResponse {
		return new DataResponse(array_map($this->decorate(...), $this->service->findAll()));
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function show(int $id): DataResponse {
		try {
			$batch = $this->service->find($id);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Lauf nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
		$data = $this->decorate($batch);
		$data['items'] = $this->service->findItems($id);
		return new DataResponse($data);
	}

	/**
	 * Vorschau auf einen möglichen Lauf zu `dueDate` – reine Abfrage, kein
	 * Lauf-Datensatz (Spec §3.5 „vor der Freigabe existiert kein
	 * Lauf-Datensatz").
	 */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function preview(string $dueDate): DataResponse {
		return new DataResponse([
			'dueDate' => $dueDate,
			'claims' => array_map(static fn ($c) => $c->jsonSerialize(), $this->service->preview($dueDate)),
			'summary' => $this->service->summary($dueDate),
		]);
	}

	/** Schritt 1 „Freigeben & Datei erzeugen" (Spec §3.5). */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function release(string $dueDate): DataResponse {
		try {
			return new DataResponse($this->decorate($this->service->release($dueDate)), Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/** Schritt 2 „Datei ist bei der Bank eingereicht" (Spec §3.5). */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function submit(int $id): DataResponse {
		try {
			return new DataResponse($this->decorate($this->service->submit($id)));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Lauf nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function discard(int $id, string $reason): DataResponse {
		try {
			return new DataResponse($this->decorate($this->service->discard($id, $reason)));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Lauf nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/** Terminverschiebung, nur nach hinten (Spec §2.2). */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function reschedule(int $id, string $dueDate): DataResponse {
		try {
			return new DataResponse($this->decorate($this->service->rescheduleDueDate($id, $dueDate)));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Lauf nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Download der pain.008-XML-Datei – anders als die übrigen Lese-Endpunkte
	 * `buchhalter`, weil sie (anders als {@see show()}/{@see index()}) die
	 * volle, unmaskierte IBAN enthält.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function xml(int $id): DataDownloadResponse|DataResponse {
		try {
			$xml = $this->service->renderXml($id);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Lauf nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
		return new DataDownloadResponse($xml, "lastschriftlauf-{$id}.xml", 'application/xml; charset=utf-8');
	}

	/** Vorlauf-Puffer + XML-Ablage (Spec §3.9: `verwalter`-Einstellungen). */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function settings(): DataResponse {
		return new DataResponse([
			'releaseLeadDays' => $this->settings->releaseLeadDays(),
			'xmlFolderEnabled' => $this->xmlStorage->isEnabled(),
			'xmlFolderPath' => $this->xmlStorage->folderPath(),
		]);
	}

	/**
	 * `xmlFolderEnabled` als String ('1'/'0'), nicht als `bool` – dieselbe
	 * Konvention wie überall sonst in dieser App (z. B.
	 * `SettingsController::update()`: `show_missing_document_warning`), damit
	 * ein Wert unabhängig vom genauen Content-Type des Requests ankommt.
	 */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function updateSettings(?int $releaseLeadDays = null, ?string $xmlFolderEnabled = null, ?string $xmlFolderPath = null): DataResponse {
		try {
			if ($releaseLeadDays !== null) {
				$this->settings->setReleaseLeadDays($releaseLeadDays);
			}
			if ($xmlFolderEnabled !== null) {
				$this->xmlStorage->setEnabled($xmlFolderEnabled === '1');
			}
			if ($xmlFolderPath !== null) {
				$this->xmlStorage->setFolderPath($xmlFolderPath);
			}
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return $this->settings();
	}
}
