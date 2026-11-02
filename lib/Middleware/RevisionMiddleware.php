<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Middleware;

use OCA\Vereinsbuchhaltung\Service\RevisionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
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
		if ($verb !== 'GET' && $verb !== 'HEAD' && $response->getStatus() < 400 && !$this->skipsBump($controller, $methodName, $response)) {
			$bumped = $this->revision->bump();
			$response->addHeader('X-Vbh-Revision-Previous', $bumped['previous']);
			$response->addHeader('X-Vbh-Revision', $bumped['current']);
		}
		return $response;
	}

	/** Trägt die Methode {@see NoRevisionBump}, und gilt die Ausnahme für diese Antwort? */
	private function skipsBump(Controller $controller, string $methodName, Response $response): bool {
		try {
			$attributes = (new \ReflectionMethod($controller, $methodName))->getAttributes(NoRevisionBump::class);
		} catch (\ReflectionException) {
			return false;
		}
		if ($attributes === []) {
			return false;
		}
		$key = $attributes[0]->newInstance()->unlessNonEmpty;
		if ($key === null) {
			return true;
		}
		$data = $response instanceof DataResponse ? $response->getData() : null;
		return !is_array($data) || empty($data[$key]);
	}
}
