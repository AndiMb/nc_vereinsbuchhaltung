<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\MandateLegalTextVersion;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\MandateLegalTextService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Verwaltung des Mandats-Rechtstexts (Spec §2.2/§3.11, Issue #67): nur der
 * freie Rahmen ist editierbar, der DK-Pflichtblock ist Code
 * ({@see MandateLegalTextService::defaultPflichtblock()}). Verwalter-only,
 * wie jede andere rechtlich bindende Vereinseinstellung (Spec §3.9). Fehler
 * kommen ausschließlich aus {@see MandateLegalTextService::createVersion()}
 * (ungültiger `createdBy`) - dessen Meldung ist bereits übersetzt, ein
 * eigenes IL10N wird hier nicht gebraucht.
 */
class MandateLegalTextController extends Controller {

	public function __construct(
		IRequest $request,
		private MandateLegalTextService $service,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** Aktuelle Version + der daraus extrahierte, editierbare Rahmen fürs Formular. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function current(): DataResponse {
		$version = $this->service->current();
		return new DataResponse([
			'version' => $version->jsonSerialize(),
			'pflichtblock' => $this->service->defaultPflichtblock(),
			'rahmen' => $this->service->extractRahmen($version->getBody()),
		]);
	}

	/** @return DataResponse Verlauf, neueste zuerst - Nachvollziehbarkeit früherer Mandats-Rechtstexte. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function history(): DataResponse {
		return new DataResponse(array_map(static fn (MandateLegalTextVersion $v) => $v->jsonSerialize(), $this->service->history()));
	}

	/** Legt eine neue, `verwalter`-getriebene Version mit geändertem Rahmen an (Spec §2.2: keine Rückwirkung, immer eine neue Zeile). */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function update(string $rahmen): DataResponse {
		try {
			$version = $this->service->createVersion($rahmen, MandateLegalTextVersion::CREATED_BY_VERWALTER);
			return new DataResponse([
				'version' => $version->jsonSerialize(),
				'pflichtblock' => $this->service->defaultPflichtblock(),
				'rahmen' => $this->service->extractRahmen($version->getBody()),
			], Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
