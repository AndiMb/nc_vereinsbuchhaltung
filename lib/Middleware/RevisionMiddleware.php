<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Middleware;

use OCA\Vereinsbuchhaltung\Service\RevisionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;

/**
 * Erhöht den Änderungsstand (RevisionService) nach jeder erfolgreichen
 * Schreiboperation dieser App. So bekommen andere geöffnete Browser die
 * Änderung per Polling mit, ohne dass jeder Schreibpfad einzeln daran
 * denken muss.
 */
class RevisionMiddleware extends Middleware {

	public function __construct(
		private RevisionService $revision,
		private IRequest $request,
	) {
	}

	public function afterController(Controller $controller, string $methodName, Response $response): Response {
		$verb = strtoupper($this->request->getMethod());
		if ($verb !== 'GET' && $verb !== 'HEAD' && $response->getStatus() < 400) {
			$this->revision->bump();
		}
		return $response;
	}
}
