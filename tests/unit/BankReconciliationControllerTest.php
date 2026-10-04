<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Controller\BankReconciliationController;
use OCA\Vereinsbuchhaltung\Controller\SepaImportController;
use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\BankTxSepaDetail;
use OCA\Vereinsbuchhaltung\Db\BankTxSepaDetailMapper;
use OCA\Vereinsbuchhaltung\Exception\SettlementBlockedException;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCA\Vereinsbuchhaltung\Service\Sepa\BankReconciliationService;
use OCA\Vereinsbuchhaltung\Service\Sepa\IncomingPaymentMatchingService;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaImportConfirmationService;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaImportSettingsService;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaMatchingService;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaSettingsAccountValidator;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Rechtelinie des Bankabgleichs (Spec §3.9, Issue #105): lesend ab `revisor`
 * (Arbeitsliste, Vorschau, die Lese-Endpunkte des Imports), jedes Urteil und
 * jede Verbuchung ab `buchhalter` – jeweils ausdrücklich per #[RequiresRole].
 * Den Rückgabecode der Bank bekommt nur, wer schreiben darf (Spec §3.6) –
 * auch über die älteren Lese-Endpunkte `pending`/`show`.
 */
class BankReconciliationControllerTest extends TestCase {

	private BankReconciliationService&MockObject $service;
	private PermissionService&MockObject $permissions;
	private SepaImportConfirmationService&MockObject $confirmation;

	protected function setUp(): void {
		$this->service = $this->createMock(BankReconciliationService::class);
		$this->permissions = $this->createMock(PermissionService::class);
		$this->confirmation = $this->createMock(SepaImportConfirmationService::class);
	}

	private function l10n(): IL10N&MockObject {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		return $l10n;
	}

	private function controller(): BankReconciliationController {
		return new BankReconciliationController($this->createMock(IRequest::class), $this->service, $this->permissions, $this->l10n());
	}

	private function declaredRole(string $class, string $method): ?string {
		$attributes = (new \ReflectionMethod($class, $method))->getAttributes(RequiresRole::class);
		return $attributes === [] ? null : $attributes[0]->newInstance()->role;
	}

	public function testListeUndVorschauSindAbRevisorLesbarDasAblehnenErstAbBuchhalter(): void {
		$this->assertSame(PermissionService::ROLE_READ, $this->declaredRole(BankReconciliationController::class, 'index'));
		$this->assertSame(PermissionService::ROLE_READ, $this->declaredRole(BankReconciliationController::class, 'preview'));
		$this->assertSame(PermissionService::ROLE_WRITE, $this->declaredRole(BankReconciliationController::class, 'rejectIncomingPayment'));
	}

	public function testUrteileUndVerbuchenSindErstAbBuchhalterDasLesenAbRevisor(): void {
		foreach (['assign', 'reject', 'markUnmatched', 'settle', 'confirmIncomingPayment'] as $method) {
			$this->assertSame(PermissionService::ROLE_WRITE, $this->declaredRole(SepaImportController::class, $method), $method);
		}
		foreach (['pending', 'show', 'incomingPaymentSuggestions'] as $method) {
			$this->assertSame(PermissionService::ROLE_READ, $this->declaredRole(SepaImportController::class, $method), $method);
		}
	}

	public function testBuchhalterBekommenDenRueckgabecodeRevisorenNicht(): void {
		$this->permissions->method('canWrite')->willReturnOnConsecutiveCalls(true, false, true, false);
		$this->service->expects($this->exactly(2))->method('worklist')
			->willReturnCallback(static fn (bool $withCodes): array => ['items' => [], 'incoming' => [], 'withCodes' => $withCodes]);
		$this->service->expects($this->exactly(2))->method('settlementPreview')
			->willReturnCallback(static fn (int $id, bool $withCodes): array => ['withCodes' => $withCodes]);

		$controller = $this->controller();

		$this->assertTrue($controller->index()->getData()['withCodes']);
		$this->assertFalse($controller->index()->getData()['withCodes']);
		$this->assertTrue($controller->preview(1)->getData()['withCodes']);
		$this->assertFalse($controller->preview(1)->getData()['withCodes']);
	}

	public function testVorschauEinesUnbekanntenUmsatzesIstEine404(): void {
		$this->service->method('settlementPreview')->willThrowException(new DoesNotExistException('weg'));

		$response = $this->controller()->preview(404);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testAblehnenLeitetAnDenDienstWeiterUndMeldetUnbekanntes(): void {
		$this->service->expects($this->once())->method('rejectIncomingPayment')->with(5, 100);
		$this->assertSame(Http::STATUS_OK, $this->controller()->rejectIncomingPayment(5, 100)->getStatus());

		$this->service = $this->createMock(BankReconciliationService::class);
		$this->service->method('rejectIncomingPayment')->willThrowException(new DoesNotExistException('weg'));
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->rejectIncomingPayment(5, 404)->getStatus());
	}

	// --- Die älteren Lese-Endpunkte tragen den Rückgabecode ebenfalls nur für die Buchhaltung ---

	private function importController(): SepaImportController {
		$tx = new BankTransaction();
		$tx->setId(1);
		$detail = new BankTxSepaDetail();
		$detail->setId(7);
		$detail->setBankTxId(1);
		$detail->setReturnReasonCode('AM04');
		$detail->setReturnReasonText('Freitext der Bank');
		$detail->setStatus(BankTxSepaDetail::STATUS_ASSIGNED);

		$details = $this->createMock(BankTxSepaDetailMapper::class);
		$details->method('findOpen')->willReturn([$detail]);
		$txMapper = $this->createMock(BankTransactionMapper::class);
		$txMapper->method('find')->willReturn($tx);
		$confirmation = $this->confirmation;
		$confirmation->method('findByBankTx')->willReturn([$detail]);

		return new SepaImportController(
			$this->createMock(IRequest::class),
			$details,
			$txMapper,
			$this->createMock(SepaMatchingService::class),
			$confirmation,
			$this->createMock(IncomingPaymentMatchingService::class),
			$this->createMock(SepaImportSettingsService::class),
			$this->createMock(SepaSettingsAccountValidator::class),
			$this->permissions,
			$this->l10n(),
		);
	}

	public function testPendingUndShowLassenDenRueckgabecodeFuerLeserWeg(): void {
		$this->permissions->method('canWrite')->willReturn(false);
		$controller = $this->importController();

		$pending = $controller->pending()->getData()[0]['details'][0];
		$show = $controller->show(1)->getData()['details'][0];

		foreach ([$pending, $show] as $detail) {
			$this->assertArrayNotHasKey('returnReasonCode', $detail);
			$this->assertArrayNotHasKey('returnReasonText', $detail);
		}
	}

	public function testPendingUndShowLiefernDenRueckgabecodeFuerDieBuchhaltung(): void {
		$this->permissions->method('canWrite')->willReturn(true);
		$controller = $this->importController();

		$this->assertSame('AM04', $controller->pending()->getData()[0]['details'][0]['returnReasonCode']);
		$this->assertSame('AM04', $controller->show(1)->getData()['details'][0]['returnReasonCode']);
	}

	/** Ein Posten, der schon einer anderen Zeile gehört, ist ein Fehler mit lesbarer Meldung – keine Serverfehlerseite. */
	public function testDoppelteZuordnungEinesPostensIstEine400MitMeldung(): void {
		$this->confirmation->method('assign')->willThrowException(new SettlementBlockedException('Dieser Einzugsposten ist bereits einer anderen Zeile zugeordnet.', SettlementBlockedException::REASON_ITEM_TAKEN));

		$response = $this->importController()->assign(7, 10);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Dieser Einzugsposten ist bereits einer anderen Zeile zugeordnet.', $response->getData()['message']);
	}
}
