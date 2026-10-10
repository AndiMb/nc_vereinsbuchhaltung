<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Service\DebitRunQueryService;
use OCA\Vereinsbuchhaltung\Service\DirectDebitEligibilityResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Lauf-Abfrage (Spec §3.5, Issue #70/#71/#102): {@see DebitRunQueryService::summariesByDueDate()}
 * muss dieselbe Auswahlregel anwenden wie {@see DebitRunQueryService::preview()}
 * – der Zeitstrahl zeigt je Termin dieselbe Zahl wie die Geisterkarte.
 */
class DebitRunQueryServiceTest extends TestCase {

	private OpenItemMapper&MockObject $openItems;
	private MandateMapper&MockObject $mandates;
	private DebitItemMapper&MockObject $debitItems;

	protected function setUp(): void {
		$this->openItems = $this->createMock(OpenItemMapper::class);
		$this->mandates = $this->createMock(MandateMapper::class);
		$this->debitItems = $this->createMock(DebitItemMapper::class);
	}

	/** Mitglieder-IDs, die kein einzugsfähiges Mandat haben (alle anderen schon). */
	private function withoutMandate(int ...$memberIds): void {
		$this->mandates->method('findLiveByMember')->willReturnCallback(static function (int $memberId) use ($memberIds): array {
			if (in_array($memberId, $memberIds, true)) {
				return [];
			}
			$m = new Mandate();
			$m->setStatus(Mandate::STATUS_ACTIVE);
			return [$m];
		});
	}

	private function service(): DebitRunQueryService {
		// Der Resolver ist final - echt, mit gemockten Mappern (wie in den Aufgaben-Tests).
		$eligibility = new DirectDebitEligibilityResolver($this->createMock(AssignmentMapper::class), $this->mandates);
		return new DebitRunQueryService($this->openItems, $eligibility, $this->debitItems);
	}

	/** Die Mitglieds-ID entspricht der Forderungs-ID, damit withoutMandate() einzelne Forderungen treffen kann. */
	private function claim(int $id, ?string $dueDate, int $amountCents, string $status = 'open'): OpenItem {
		$c = new OpenItem();
		$c->setId($id);
		$c->setMemberId($id);
		$c->setType(OpenItem::TYPE_CONTRIBUTION);
		$c->setStatus($status);
		$c->setAmountCents($amountCents);
		$c->setDueDate($dueDate);
		return $c;
	}

	public function testSummariesGruppiertEinzugsfaehigeOffeneForderungenJeTermin(): void {
		$this->debitItems->method('findOpenItemIdsInLiveBatches')->willReturn([3]);
		$cancelled = $this->claim(5, '2026-11-01', 500);
		$cancelled->setCancelledAt('2026-10-01T00:00:00+00:00');
		$this->openItems->method('findClaims')->willReturn([
			$this->claim(1, '2026-11-01', 1000),
			$this->claim(2, '2026-11-01', 2500),
			$this->claim(3, '2026-11-01', 9999), // schon in einem lebenden Lauf
			$this->claim(4, '2026-12-01', 700),
			$cancelled,
			$this->claim(6, '2026-11-01', 800, 'paid'), // erledigt
			$this->claim(7, null, 100), // ohne Termin
			$this->claim(8, '2026-12-01', 300), // kein einzugsfaehiges Mandat
		]);
		$this->withoutMandate(8);

		$this->assertSame([
			'2026-11-01' => ['count' => 2, 'sumCents' => 3500],
			'2026-12-01' => ['count' => 1, 'sumCents' => 700],
		], $this->service()->summariesByDueDate());
	}

	public function testSummariesStimmenMitSummaryJeTerminUeberein(): void {
		$claims = [$this->claim(1, '2026-11-01', 1000), $this->claim(2, '2026-11-01', 2500), $this->claim(4, '2026-12-01', 700)];
		$this->debitItems->method('findOpenItemIdsInLiveBatches')->willReturn([]);
		$this->openItems->method('findClaims')->willReturn($claims);
		$this->openItems->method('findClaimsDueOn')->willReturnCallback(
			static fn (string $date): array => array_values(array_filter($claims, static fn (OpenItem $c): bool => $c->getDueDate() === $date)),
		);
		$this->withoutMandate();

		$service = $this->service();
		$summaries = $service->summariesByDueDate();
		$this->assertCount(2, $summaries);
		foreach ($summaries as $date => $summary) {
			$this->assertSame($summary, $service->summary($date));
		}
	}

	public function testSummariesOhneForderungenIstLeer(): void {
		$this->assertSame([], $this->service()->summariesByDueDate());
	}
}
