<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\MemberImportService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Massenanlage von Mitgliedern aus einer CSV-Liste (siehe
 * {@see MemberImportService}). Buchhaltern und Verwaltern vorbehalten wie
 * alle SEPA-Endpunkte: hier entstehen Mandate, also Bankverbindungen.
 */
class MemberImportController extends Controller {

	/**
	 * Obergrenze für die hochgeladene Datei. 2 MB sind etwa 20.000 Zeilen –
	 * weit jenseits dessen, was ein Verein je einliest, und klein genug, dass
	 * niemand den Server mit einer Zeichenkette beschäftigt.
	 */
	private const MAX_BYTES = 2 * 1024 * 1024;

	public function __construct(
		IRequest $request,
		private MemberImportService $service,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** Prüflauf: ändert nichts, zeigt je Zeile, was entstehen würde. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function preview(string $csv): DataResponse {
		$fehler = $this->check($csv);
		if ($fehler !== null) {
			return $fehler;
		}
		return new DataResponse($this->service->preview($csv));
	}

	/** Legt die Zeilen an, die in Ordnung sind. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function import(string $csv): DataResponse {
		$fehler = $this->check($csv);
		if ($fehler !== null) {
			return $fehler;
		}
		return new DataResponse($this->service->import($csv));
	}

	private function check(string $csv): ?DataResponse {
		if (trim($csv) === '') {
			return new DataResponse(['message' => $this->l10n->t('Es wurde keine Datei übergeben.')], Http::STATUS_BAD_REQUEST);
		}
		if (strlen($csv) > self::MAX_BYTES) {
			return new DataResponse(['message' => $this->l10n->t('Die Datei ist zu groß (höchstens %s MB).', ['2'])], Http::STATUS_BAD_REQUEST);
		}
		return null;
	}
}
