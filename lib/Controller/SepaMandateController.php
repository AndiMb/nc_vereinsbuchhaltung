<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\SepaMandate;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\MemberReferenceValidator;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCA\Vereinsbuchhaltung\Service\SepaMandateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Pflege der SEPA-Lastschriftmandate (siehe {@see SepaMandateService}).
 * Rein optionales Zusatzmodul – wer es nie öffnet, merkt nichts davon.
 *
 * Wie beim PermissionController (Rechtevergabe) ist jede Methode Verwaltern
 * vorbehalten: ein Mandat verknüpft ein Nextcloud-Konto mit einer IBAN, also
 * personenbezogenen Bankdaten – dieselbe Sensibilität wie die Rechteliste,
 * die aus demselben Grund schon auf Verwalter beschränkt ist.
 */
class SepaMandateController extends Controller {

	public function __construct(
		IRequest $request,
		private SepaMandateService $service,
		private MemberReferenceValidator $memberRef,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** Reichert ein Mandat um den Anzeigenamen des Zahlers an. */
	private function decorate(SepaMandate $mandate): array {
		$data = $mandate->jsonSerialize();
		$data['displayName'] = $this->memberRef->displayName($mandate->getMemberUid(), $mandate->getMemberLabel());
		return $data;
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function index(): DataResponse {
		return new DataResponse(array_map($this->decorate(...), $this->service->findAll()));
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function create(
		?string $memberUid,
		?string $memberLabel,
		string $iban,
		?string $bic,
		string $mandateType,
		string $signedDate,
	): DataResponse {
		try {
			$mandate = $this->service->create($memberUid, $memberLabel, $iban, $bic, $mandateType, $signedDate);
			return new DataResponse($this->decorate($mandate), Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function update(int $id, string $iban, ?string $bic, string $mandateType, string $signedDate): DataResponse {
		try {
			$mandate = $this->service->update($id, $iban, $bic, $mandateType, $signedDate);
			return new DataResponse($this->decorate($mandate));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Mandat nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function revoke(int $id): DataResponse {
		try {
			return new DataResponse($this->decorate($this->service->revoke($id)));
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Mandat nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function destroy(int $id): DataResponse {
		try {
			$this->service->delete($id);
			return new DataResponse([]);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Mandat nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}
}
