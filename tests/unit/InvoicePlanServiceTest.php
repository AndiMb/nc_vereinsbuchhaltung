<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Debtor;
use OCA\Vereinsbuchhaltung\Db\DebtorMapper;
use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Db\InvoicePlan;
use OCA\Vereinsbuchhaltung\Db\InvoicePlanMapper;
use OCA\Vereinsbuchhaltung\Exception\InvoiceNotIssuableException;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\EInvoice\EInvoiceService;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoiceDocumentService;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoiceMailService;
use OCA\Vereinsbuchhaltung\Service\InvoicePlanService;
use OCA\Vereinsbuchhaltung\Service\InvoiceService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Wiederkehrende Rechnungen: Termine (Monatsende, Ende, Pause), Entwurf mit
 * Leistungszeitraum und Platzhaltern, Festschreiben und Versand, Fehler im Plan.
 */
class InvoicePlanServiceTest extends TestCase {

	private InvoicePlanMapper&MockObject $plans;
	private DebtorMapper&MockObject $debtors;
	private InvoiceService&MockObject $invoices;
	private InvoiceMailService&MockObject $mail;
	private InvoiceDocumentService&MockObject $documents;

	private function service(): InvoicePlanService {
		$this->plans = $this->createMock(InvoicePlanMapper::class);
		$this->plans->method('insert')->willReturnArgument(0);
		$this->plans->method('update')->willReturnArgument(0);
		$this->debtors = $this->createMock(DebtorMapper::class);
		$debtor = new Debtor();
		$debtor->setName('Muster GmbH');
		$debtor->setEmail('info@muster.example');
		$this->debtors->method('find')->willReturn($debtor);
		$this->invoices = $this->createMock(InvoiceService::class);
		$this->mail = $this->createMock(InvoiceMailService::class);
		$this->documents = $this->createMock(InvoiceDocumentService::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, $params = []) => vsprintf($text, (array)$params));
		$l10n->method('getLocaleCode')->willReturn('de_DE');
		return new InvoicePlanService(
			$this->plans,
			$this->debtors,
			$this->invoices,
			$this->documents,
			$this->createMock(EInvoiceService::class),
			$this->mail,
			$this->createMock(AuditService::class),
			$this->createMock(IUserSession::class),
			$this->createMock(LoggerInterface::class),
			$l10n,
		);
	}

	private function plan(array $over = []): InvoicePlan {
		$plan = new InvoicePlan();
		$plan->setName('Hallenmiete');
		$plan->setDebtorId(5);
		$plan->setIntervalMonths($over['interval'] ?? 1);
		$plan->setStartDate($over['start'] ?? '2026-01-31');
		$plan->setEndDate($over['end'] ?? null);
		$plan->setNextRun($over['next'] ?? '2026-01-31');
		$plan->setPeriodMode($over['mode'] ?? InvoicePlan::PERIOD_INTERVAL);
		$plan->setLines(json_encode([['name' => 'Hallenmiete {monat} {jahr}', 'quantity' => 1, 'unitPriceCents' => 12000, 'accountId' => 3]]));
		$plan->setAutoIssue($over['issue'] ?? 0);
		$plan->setAutoSend($over['send'] ?? 0);
		$plan->setActive(1);
		return $plan;
	}

	private function draft(int $id = 77): Invoice {
		$invoice = new Invoice();
		$invoice->setId($id);
		return $invoice;
	}

	public function testMonatsplusRechnetVomBeginnUndKuerztAmMonatsende(): void {
		$s = $this->service();

		$this->assertSame('2026-02-28', $s->addMonths('2026-01-31', 1));
		$this->assertSame('2026-03-31', $s->addMonths('2026-01-31', 2));
		$this->assertSame('2028-02-29', $s->addMonths('2026-01-31', 25));
		$this->assertSame('2027-01-15', $s->addMonths('2026-01-15', 12));
	}

	public function testNeuerPlanHoltVergangeneTermineNichtNach(): void {
		$s = $this->service();
		$this->invoices->method('validateLines');

		$plan = $s->create(['name' => 'Miete', 'debtorId' => 5, 'intervalMonths' => 3, 'startDate' => '2026-01-15', 'lines' => [['name' => 'x', 'unitPriceCents' => 100]]], '2026-05-10');

		$this->assertSame('2026-07-15', $plan->getNextRun());
	}

	public function testPlanMitEndeInDerVergangenheitHatKeinenNaechstenTermin(): void {
		$s = $this->service();
		$this->invoices->method('validateLines');

		$plan = $s->create(['name' => 'Miete', 'debtorId' => 5, 'startDate' => '2026-01-15', 'endDate' => '2026-03-01', 'lines' => [['name' => 'x', 'unitPriceCents' => 100]]], '2026-05-10');

		$this->assertNull($plan->getNextRun());
	}

	public function testEntwurfBekommtLeistungszeitraumDesTaktesUndErsetztPlatzhalter(): void {
		$s = $this->service();
		$captured = null;
		$this->invoices->method('createDraft')->willReturnCallback(function (array $data) use (&$captured): Invoice {
			$captured = $data;
			return $this->draft();
		});
		$plan = $this->plan(['start' => '2026-06-01', 'next' => '2026-06-01']);

		$result = $s->runPlan($plan, '2026-06-01');

		$this->assertSame(['created' => 1, 'issued' => 0, 'sent' => 0, 'failed' => 0], $result);
		$this->assertSame('2026-06-01', $captured['issueDate']);
		$this->assertSame('2026-06-01', $captured['periodStart']);
		$this->assertSame('2026-06-30', $captured['periodEnd']);
		$this->assertArrayNotHasKey('deliveryDate', $captured);
		$this->assertSame('Hallenmiete Juni 2026', $captured['lines'][0]['name']);
		$this->assertSame('2026-07-01', $plan->getNextRun());
		$this->assertSame(77, $plan->getLastInvoiceId());
		$this->assertSame(1, $plan->getRunCount());
	}

	public function testLeistungsdatumGleichRechnungsdatumWennSoGewaehlt(): void {
		$s = $this->service();
		$captured = null;
		$this->invoices->method('createDraft')->willReturnCallback(function (array $data) use (&$captured): Invoice {
			$captured = $data;
			return $this->draft();
		});

		$s->runPlan($this->plan(['start' => '2026-06-01', 'next' => '2026-06-01', 'mode' => InvoicePlan::PERIOD_ISSUE_DATE]), '2026-06-01');

		$this->assertSame('2026-06-01', $captured['deliveryDate']);
		$this->assertArrayNotHasKey('periodStart', $captured);
	}

	public function testPlanSchreibtFestUndVersendetWennGewuenscht(): void {
		$s = $this->service();
		$this->invoices->method('createDraft')->willReturn($this->draft());
		$this->invoices->expects($this->once())->method('issue')->with(77);
		$this->mail->expects($this->once())->method('send')->with(77, 'info@muster.example');

		$result = $s->runPlan($this->plan(['issue' => 1, 'send' => 1, 'start' => '2026-06-01', 'next' => '2026-06-01']), '2026-06-01');

		$this->assertSame(['created' => 1, 'issued' => 1, 'sent' => 1, 'failed' => 0], $result);
	}

	public function testLaesstSichDerEntwurfNichtFestschreibenBleibtErLiegenUndDerPlanNenntDenGrund(): void {
		$s = $this->service();
		$this->invoices->method('createDraft')->willReturn($this->draft());
		$this->invoices->method('issue')->willThrowException(new InvoiceNotIssuableException('unvollständig', ['Leistungsdatum']));
		$this->mail->expects($this->never())->method('send');
		$plan = $this->plan(['issue' => 1, 'send' => 1, 'start' => '2026-06-01', 'next' => '2026-06-01']);

		$result = $s->runPlan($plan, '2026-06-01');

		$this->assertSame(1, $result['created']);
		$this->assertSame(0, $result['issued']);
		$this->assertSame(1, $result['failed']);
		$this->assertStringContainsString('Leistungsdatum', (string)$plan->getLastError());
		$this->assertSame('2026-07-01', $plan->getNextRun(), 'der Plan rückt trotzdem vor, der Entwurf wartet auf einen Menschen');
	}

	public function testFehlerBeimAnlegenStopptDenPlanNichtUndWirdVermerkt(): void {
		$s = $this->service();
		$this->invoices->method('createDraft')->willThrowException(new \InvalidArgumentException('Das Erlöskonto existiert nicht.'));
		$plan = $this->plan(['start' => '2026-06-01', 'next' => '2026-06-01']);

		$result = $s->runPlan($plan, '2026-06-01');

		$this->assertSame(['created' => 0, 'issued' => 0, 'sent' => 0, 'failed' => 1], $result);
		$this->assertSame('Das Erlöskonto existiert nicht.', $plan->getLastError());
		$this->assertSame('2026-07-01', $plan->getNextRun());
	}

	public function testVerpassteTermineWerdenNachgeholtUndDasEndeBeendetDenPlan(): void {
		$s = $this->service();
		$created = [];
		$this->invoices->method('createDraft')->willReturnCallback(function (array $data) use (&$created): Invoice {
			$created[] = $data['issueDate'];
			return $this->draft(count($created));
		});
		$plan = $this->plan(['start' => '2026-01-15', 'next' => '2026-01-15', 'end' => '2026-03-31']);

		$result = $s->runPlan($plan, '2026-06-01');

		$this->assertSame(3, $result['created']);
		$this->assertSame(['2026-01-15', '2026-02-15', '2026-03-15'], $created);
		$this->assertNull($plan->getNextRun());
	}

	public function testPausierterPlanLaeuftNichtUndFortsetzenSpringtZumNaechstenTermin(): void {
		$s = $this->service();
		$plan = $this->plan(['start' => '2026-01-15', 'next' => '2026-01-15']);
		$plan->setActive(0);
		$this->plans->method('find')->willReturn($plan);
		$this->invoices->expects($this->never())->method('createDraft');

		$this->assertSame(['created' => 0, 'issued' => 0, 'sent' => 0, 'failed' => 0], $s->runPlan($plan, '2026-06-01'));

		$resumed = $s->setActive(1, true, '2026-06-01');
		$this->assertSame('2026-06-15', $resumed->getNextRun());
	}

	public function testVersandOhneFestschreibenIstKeinPlan(): void {
		$s = $this->service();
		$this->invoices->method('validateLines');

		$this->expectException(\InvalidArgumentException::class);
		$s->create(['name' => 'x', 'debtorId' => 5, 'startDate' => '2026-06-01', 'autoSend' => true, 'autoIssue' => false, 'lines' => [['name' => 'x', 'unitPriceCents' => 100]]]);
	}

	public function testUnbekannterTaktUndEmpfaengerWerdenAbgelehnt(): void {
		$s = $this->service();
		$this->invoices->method('validateLines');
		try {
			$s->create(['name' => 'x', 'debtorId' => 5, 'startDate' => '2026-06-01', 'intervalMonths' => 5, 'lines' => [['name' => 'x', 'unitPriceCents' => 100]]]);
			$this->fail('Takt 5 ist nicht erlaubt');
		} catch (\InvalidArgumentException) {
		}
		$this->debtors->method('find')->willThrowException(new DoesNotExistException('x'));
		$this->expectException(\InvalidArgumentException::class);
		$s->create(['name' => 'x', 'debtorId' => 99, 'startDate' => '2026-06-01', 'lines' => [['name' => 'x', 'unitPriceCents' => 100]]]);
	}
}
