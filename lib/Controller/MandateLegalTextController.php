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
 * kommen ausschließlich aus {@see MandateLegalTextService::saveRahmenAsNewVersion()}
 * (zu lang, unverändert, reservierte Zeichenfolge) - deren Meldung ist bereits
 * übersetzt, ein eigenes IL10N wird hier nicht gebraucht.
 *
 * Die Antworten tragen jede Version als `pflichtblock` + `rahmen` und
 * absichtlich NICHT als `body` (Issue #101): der Textkörper enthält den
 * internen Rahmen-Marker, den kein Aufrufer je zu Gesicht bekommen soll - die
 * Einstellungsseite rendert beide Teile getrennt, ein Zusammensetzen auf
 * Client-Seite braucht den Marker nicht.
 */
class MandateLegalTextController extends Controller {

	public function __construct(
		IRequest $request,
		private MandateLegalTextService $service,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Eine Version für die Einstellungsseite: Kopfdaten plus die beiden
	 * Textteile, ohne den internen Marker.
	 *
	 * @param int $number laufende Nummer der Fassung (älteste = 1)
	 * @return array{id:int,number:int,createdBy:string,createdAt:string,pflichtblock:string,rahmen:string}
	 */
	private function present(MandateLegalTextVersion $version, int $number): array {
		$parts = $this->service->splitBody($version->getBody());
		return [
			'id' => (int)$version->getId(),
			'number' => $number,
			'createdBy' => $version->getCreatedBy(),
			'createdAt' => $version->getCreatedAt(),
			'pflichtblock' => $parts['pflichtblock'],
			'rahmen' => $parts['rahmen'],
		];
	}

	/** Aktuelle Version + der daraus extrahierte, editierbare Rahmen fürs Formular. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function current(): DataResponse {
		$version = $this->service->current();
		return new DataResponse($this->currentPayload($version));
	}

	/** @return DataResponse Verlauf, neueste zuerst - Nachvollziehbarkeit früherer Mandats-Rechtstexte. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function history(): DataResponse {
		// current() zuerst: legt beim allerersten Aufruf bzw. nach einem
		// App-Update mit geändertem Pflichtblock die fällige Version an, damit
		// der Verlauf nie leer ist und die „aktuelle" Fassung enthält.
		$this->service->current();
		$versions = $this->service->history();
		$total = count($versions);
		$out = [];
		foreach ($versions as $index => $version) {
			$out[] = $this->present($version, $total - $index);
		}
		return new DataResponse($out);
	}

	/** Legt eine neue, `verwalter`-getriebene Version mit geändertem Rahmen an (Spec §2.2: keine Rückwirkung, immer eine neue Zeile). */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function update(string $rahmen): DataResponse {
		try {
			$version = $this->service->saveRahmenAsNewVersion($rahmen);
			return new DataResponse($this->currentPayload($version), Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/** @return array<string,mixed> */
	private function currentPayload(MandateLegalTextVersion $version): array {
		$presented = $this->present($version, count($this->service->history()));
		return [
			'version' => $presented,
			'pflichtblock' => $presented['pflichtblock'],
			'rahmen' => $presented['rahmen'],
		];
	}
}
