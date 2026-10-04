<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\DebitItem;
use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\ReturnedDebit;
use OCA\Vereinsbuchhaltung\Db\ReturnedDebitMapper;
use OCA\Vereinsbuchhaltung\Db\Task;
use OCA\Vereinsbuchhaltung\Service\ClaimFollowUpTaskService;
use OCA\Vereinsbuchhaltung\Service\DirectDebitEligibilityResolver;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Aggregierte Folge-Aufgaben des Aufgaben-Katalogs (Spec §7, Issue #117):
 * „Rücklastschrift ohne Wiedereinzug“ und „Forderungen nach Widerruf offen“,
 * je EINE Zeile mit Anzahl, abgeleitet und von selbst verschwindend.
 */
class ClaimFollowUpTaskServiceTest extends TestCase {

	private OpenItemMapper&MockObject $openItems;
	private ReturnedDebitMapper&MockObject $returnedDebits;
	private DebitItemMapper&MockObject $debitItems;
	private MandateMapper&MockObject $mandates;
	private AssignmentMapper&MockObject $assignments;

	protected function setUp(): void {
		$this->openItems = $this->createMock(OpenItemMapper::class);
		$this->returnedDebits = $this->createMock(ReturnedDebitMapper::class);
		$this->debitItems = $this->createMock(DebitItemMapper::class);
		$this->mandates = $this->createMock(MandateMapper::class);
		$this->assignments = $this->createMock(AssignmentMapper::class);
	}

	private function service(): ClaimFollowUpTaskService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$l10n->method('n')->willReturnCallback(static function (string $singular, string $plural, int $count, array $params = []): string {
			return vsprintf(str_replace('%n', (string)$count, $count === 1 ? $singular : $plural), $params);
		});
		return new ClaimFollowUpTaskService($this->openItems, $this->returnedDebits, $this->debitItems, $this->mandates, new DirectDebitEligibilityResolver($this->assignments, $this->mandates), $l10n);
	}

	private function claim(int $id, int $memberId, int $amountCents = 1000, string $status = 'open', ?int $assignmentId = null): OpenItem {
		$claim = new OpenItem();
		$claim->setId($id);
		$claim->setMemberId($memberId);
		$claim->setType(OpenItem::TYPE_CONTRIBUTION);
		$claim->setStatus($status);
		$claim->setAmountCents($amountCents);
		$claim->setAssignmentId($assignmentId);
		return $claim;
	}

	private function debitItem(int $id, int $openItemId): DebitItem {
		$item = new DebitItem();
		$item->setId($id);
		$item->setOpenItemId($openItemId);
		return $item;
	}

	private function returned(int $id, int $debitItemId, ?string $reasonCode): ReturnedDebit {
		$returned = new ReturnedDebit();
		$returned->setId($id);
		$returned->setDebitItemId($debitItemId);
		$returned->setReasonCode($reasonCode);
		return $returned;
	}

	private function revokedMandate(int $id, int $memberId): Mandate {
		$mandate = new Mandate();
		$mandate->setId($id);
		$mandate->setMemberId($memberId);
		$mandate->setStatus(Mandate::STATUS_ENDED);
		$mandate->setEndReason(Mandate::END_REASON_REVOKED);
		return $mandate;
	}

	// --- Rücklastschrift ohne Wiedereinzug ----------------------------------------------

	public function testOhneRuecklastschriftUndWiderrufKeineAufgabeUndKeineTeurenAbfragen(): void {
		$this->returnedDebits->method('findAllByDebitItem')->willReturn([]);
		$this->mandates->method('findAll')->willReturn([]);
		$this->openItems->expects($this->never())->method('findClaims');
		$this->debitItems->expects($this->never())->method('findAllInLiveBatches');

		$this->assertSame([], $this->service()->findTasks());
	}

	public function testRuecklastschriftenWerdenZuEinerZeileMitAnzahlUndSummeAggregiert(): void {
		$this->returnedDebits->method('findAllByDebitItem')->willReturn([
			5 => $this->returned(1, 5, 'AM04'),
			6 => $this->returned(2, 6, 'AM04'),
		]);
		$this->debitItems->method('findAllInLiveBatches')->willReturn([$this->debitItem(5, 10), $this->debitItem(6, 11), $this->debitItem(7, 12)]);
		$this->openItems->method('findClaims')->willReturn([
			$this->claim(10, 1, 1500),
			$this->claim(11, 2, 2500),
			$this->claim(12, 3, 9900), // eingezogen, nicht zurückgegeben
		]);

		$tasks = $this->service()->findTasks();

		$this->assertCount(1, $tasks);
		$this->assertStringContainsString('2 Forderungen nach Rücklastschrift weiter offen, zusammen 40,00 €', $tasks[0]['message']);
		$this->assertStringContainsString('nicht erneut eingezogen', $tasks[0]['message']);
		$this->assertSame('claims', $tasks[0]['objectType']);
		$this->assertNull($tasks[0]['objectId']);
		$this->assertNull($tasks[0]['memberId']);
	}

	public function testEineRuecklastschriftSagtEinzahl(): void {
		$this->returnedDebits->method('findAllByDebitItem')->willReturn([5 => $this->returned(1, 5, 'AM04')]);
		$this->debitItems->method('findAllInLiveBatches')->willReturn([$this->debitItem(5, 10)]);
		$this->openItems->method('findClaims')->willReturn([$this->claim(10, 1, 1500)]);

		$tasks = $this->service()->findTasks();

		$this->assertStringContainsString('1 Forderung nach Rücklastschrift weiter offen, zusammen 15,00 €', $tasks[0]['message']);
	}

	/** @return array<string, array{0:?string,1:string}> Rückgabe-Code => erwarteter Schweregrad */
	public static function severityProvider(): array {
		return [
			// insufficient_funds: nur ein Hinweis (Spec §3.6)
			'AM04 Deckung fehlt' => ['AM04', Task::SEVERITY_HINT],
			'MS03 Deckung fehlt' => ['MS03', Task::SEVERITY_HINT],
			// alle anderen Klassen: dringend
			'AC04 Konto nicht nutzbar' => ['AC04', Task::SEVERITY_ACTION_REQUIRED],
			'MD06 Widerspruch' => ['MD06', Task::SEVERITY_ACTION_REQUIRED],
			'MD07 verstorben' => ['MD07', Task::SEVERITY_ACTION_REQUIRED],
			'AM05 technisch' => ['AM05', Task::SEVERITY_ACTION_REQUIRED],
			'unbekannter Code' => ['XX99', Task::SEVERITY_ACTION_REQUIRED],
			'ohne Code' => [null, Task::SEVERITY_ACTION_REQUIRED],
		];
	}

	/** @dataProvider severityProvider */
	public function testSchweregradFolgtDerRueckgabeKlasse(?string $reasonCode, string $expected): void {
		$this->returnedDebits->method('findAllByDebitItem')->willReturn([5 => $this->returned(1, 5, $reasonCode)]);
		$this->debitItems->method('findAllInLiveBatches')->willReturn([$this->debitItem(5, 10)]);
		$this->openItems->method('findClaims')->willReturn([$this->claim(10, 1)]);

		$tasks = $this->service()->findTasks();

		$this->assertSame($expected, $tasks[0]['severity']);
	}

	public function testEineDringendeUrsacheMachtDieGanzeZeileZumHandlungsbedarf(): void {
		$this->returnedDebits->method('findAllByDebitItem')->willReturn([
			5 => $this->returned(1, 5, 'AM04'), // nur Hinweis
			6 => $this->returned(2, 6, 'MD06'), // dringend
		]);
		$this->debitItems->method('findAllInLiveBatches')->willReturn([$this->debitItem(5, 10), $this->debitItem(6, 11)]);
		$this->openItems->method('findClaims')->willReturn([$this->claim(10, 1), $this->claim(11, 2)]);

		$tasks = $this->service()->findTasks();

		$this->assertCount(1, $tasks);
		$this->assertSame(Task::SEVERITY_ACTION_REQUIRED, $tasks[0]['severity']);
	}

	public function testRuecklastschriftZeileVerschwindetMitDemErledigungsvermerk(): void {
		$this->returnedDebits->method('findAllByDebitItem')->willReturn([5 => $this->returned(1, 5, 'AM04')]);
		$this->debitItems->method('findAllInLiveBatches')->willReturn([$this->debitItem(5, 10)]);
		// Die Forderung wurde inzwischen bezahlt.
		$this->openItems->method('findClaims')->willReturn([$this->claim(10, 1, 1500, 'paid')]);

		$this->assertSame([], $this->service()->findTasks());
	}

	public function testStornierteForderungNachRuecklastschriftZaehltNicht(): void {
		$this->returnedDebits->method('findAllByDebitItem')->willReturn([5 => $this->returned(1, 5, 'AM04')]);
		$this->debitItems->method('findAllInLiveBatches')->willReturn([$this->debitItem(5, 10)]);
		$this->openItems->method('findClaims')->willReturn([$this->claim(10, 1, 1500, 'cancelled')]);

		$this->assertSame([], $this->service()->findTasks());
	}

	// --- Forderungen nach Widerruf offen ------------------------------------------------

	public function testOffeneForderungenNachWiderrufWerdenAggregiertAlsHinweis(): void {
		$this->returnedDebits->method('findAllByDebitItem')->willReturn([]);
		$this->mandates->method('findAll')->willReturn([$this->revokedMandate(1, 7)]);
		$this->openItems->method('findClaims')->willReturn([
			$this->claim(10, 7, 1500),
			$this->claim(11, 7, 2500, 'open', 3),   // aus einer Lastschrift-Zuweisung
			$this->claim(12, 7, 9900, 'paid'),      // erledigt
			$this->claim(13, 8, 7700),              // Mitglied ohne Widerruf
		]);
		$this->assignments->method('findAll')->willReturn([]);

		$tasks = $this->service()->findTasks();

		$this->assertCount(1, $tasks);
		$this->assertSame(Task::SEVERITY_HINT, $tasks[0]['severity']);
		$this->assertStringContainsString('2 Forderungen nach Widerruf des Mandats weiter offen, zusammen 40,00 €', $tasks[0]['message']);
		$this->assertSame('claims', $tasks[0]['objectType']);
	}

	public function testForderungenAusUeberweiserZuweisungZaehlenNachWiderrufNichtMit(): void {
		// Sie sind ohnehin auf dem Überweisungsweg (und fließen in „Überweiser-Forderungen überfällig“ ein).
		$transfer = new Assignment();
		$transfer->setId(3);
		$transfer->setPaymentMethod(Assignment::PAYMENT_METHOD_TRANSFER);
		$this->returnedDebits->method('findAllByDebitItem')->willReturn([]);
		$this->mandates->method('findAll')->willReturn([$this->revokedMandate(1, 7)]);
		$this->openItems->method('findClaims')->willReturn([$this->claim(11, 7, 2500, 'open', 3)]);
		$this->assignments->method('findAll')->willReturn([$transfer]);

		$this->assertSame([], $this->service()->findTasks());
	}

	public function testNeuesEinzugsfaehigesMandatBehebtDieWiderrufsZeile(): void {
		$active = new Mandate();
		$active->setId(2);
		$active->setMemberId(7);
		$active->setStatus(Mandate::STATUS_ACTIVE);
		$this->returnedDebits->method('findAllByDebitItem')->willReturn([]);
		$this->mandates->method('findAll')->willReturn([$this->revokedMandate(1, 7), $active]);
		$this->openItems->expects($this->never())->method('findClaims');

		$this->assertSame([], $this->service()->findTasks());
	}

	public function testBeendetesMandatOhneWiderrufIstKeinWiderrufsFall(): void {
		$expired = $this->revokedMandate(1, 7);
		$expired->setEndReason(Mandate::END_REASON_EXPIRED);
		$this->returnedDebits->method('findAllByDebitItem')->willReturn([]);
		$this->mandates->method('findAll')->willReturn([$expired]);
		$this->openItems->expects($this->never())->method('findClaims');

		$this->assertSame([], $this->service()->findTasks());
	}

	public function testBeideZeilenKoennenNebeneinanderStehen(): void {
		$this->returnedDebits->method('findAllByDebitItem')->willReturn([5 => $this->returned(1, 5, 'AM04')]);
		$this->debitItems->method('findAllInLiveBatches')->willReturn([$this->debitItem(5, 10)]);
		$this->mandates->method('findAll')->willReturn([$this->revokedMandate(1, 7)]);
		$this->openItems->method('findClaims')->willReturn([$this->claim(10, 1, 1500), $this->claim(11, 7, 2500)]);
		$this->assignments->method('findAll')->willReturn([]);

		$tasks = $this->service()->findTasks();

		$this->assertCount(2, $tasks);
		$this->assertStringContainsString('Rücklastschrift', $tasks[0]['message']);
		$this->assertStringContainsString('Widerruf', $tasks[1]['message']);
	}
}
