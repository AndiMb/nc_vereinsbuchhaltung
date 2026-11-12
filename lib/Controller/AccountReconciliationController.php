<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Middleware\NoRevisionBump;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCA\Vereinsbuchhaltung\Service\Reconciliation\ReconciliationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Kontoabstimmung (Issue #159): vergleicht einen hochgeladenen Kontoauszug
 * mit den Bewegungen eines Geldkontos, siehe {@see ReconciliationService}.
 *
 * Nicht der „Bankabgleich“ des SEPA-Moduls ({@see BankReconciliationController}).
 */
class AccountReconciliationController extends Controller {

	use BookContext;
	use InternalErrorResponder;
	use StatementUpload;

	public function __construct(
		IRequest $request,
		private ReconciliationService $service,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Ein POST wegen des Datei-Uploads – geschrieben wird nichts. Deshalb die
	 * Leserolle ausdrücklich (die Verb-Heuristik verlangte sonst `buchhalter`)
	 * und kein Revisionssprung: die Daten der anderen Sitzungen bleiben aktuell.
	 */
	#[NoAdminRequired]
	#[NoRevisionBump]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function run(int $id): DataResponse {
		try {
			$upload = $this->readUpload();
			$tolerance = $this->request->getParam('tolerance');
			return new DataResponse($this->service->reconcile(
				$this->userId(),
				$id,
				$upload['content'],
				$this->dateParam('from'),
				$this->dateParam('to'),
				is_numeric($tolerance) ? (int)$tolerance : ReconciliationService::DEFAULT_TOLERANCE,
			));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Konto nicht gefunden')], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			return $this->internalError($e, Http::STATUS_BAD_REQUEST, $this->l10n->t('Die Kontoabstimmung ist fehlgeschlagen.'));
		}
	}

	private function dateParam(string $name): ?string {
		$value = $this->request->getParam($name);
		return is_string($value) && trim($value) !== '' ? trim($value) : null;
	}
}
