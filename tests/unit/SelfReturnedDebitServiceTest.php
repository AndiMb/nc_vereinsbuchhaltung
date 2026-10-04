<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\DebitItem;
use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\ReturnedDebit;
use OCA\Vereinsbuchhaltung\Db\ReturnedDebitMapper;
use OCA\Vereinsbuchhaltung\Service\SelfReturnedDebitService;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Die eigenen Rücklastschriften im Klartext (Issue #122, Spec §3.4 Pflicht-UI
 * „Rücklastschriften als Klartext (nie Code)", „niemals andere Mitglieder
 * sichtbar"). Zwei Mitglieder mit je eigener Rücklastschrift: jede Abfrage
 * liefert nur die eigenen Zeilen, und keine Zeile trägt je einen Bankcode,
 * den Freitext der Bank oder Kontodaten.
 */
class SelfReturnedDebitServiceTest extends TestCase {

	private const MEMBER_A = 11;
	private const MEMBER_B = 22;

	private const TEXT_INSUFFICIENT_FUNDS = 'Die Lastschrift konnte mangels Kontodeckung nicht eingezogen werden.';
	private const TEXT_ACCOUNT_UNUSABLE = 'Die Lastschrift konnte nicht eingezogen werden, weil das angegebene Konto nicht erreichbar ist.';
	private const TEXT_DISPUTED = 'Die Lastschrift wurde auf Ihren Widerspruch hin von Ihrer Bank zurückgebucht.';
	private const TEXT_UNKNOWN = 'Die Lastschrift konnte nicht eingezogen werden.';

	private ReturnedDebitMapper&MockObject $returnedDebits;
	private DebitItemMapper&MockObject $debitItems;
	private OpenItemMapper&MockObject $openItems;

	/** @var array<int,ReturnedDebit> Rücklastschrift-ID => Rücklastschrift */
	private array $returned = [];
	/** @var array<int,DebitItem> Posten-ID => Posten */
	private array $items = [];
	/** @var array<int,OpenItem> Forderungs-ID => Forderung */
	private array $claims = [];

	protected function setUp(): void {
		$this->returned = [];
		$this->items = [];
		$this->claims = [];

		$this->returnedDebits = $this->createMock(ReturnedDebitMapper::class);
		$this->debitItems = $this->createMock(DebitItemMapper::class);
		$this->openItems = $this->createMock(OpenItemMapper::class);
		// Wie die Datenbank: nur die angefragten Zeilen kommen zurück.
		$this->debitItems->method('findByIds')->willReturnCallback(
			fn (array $ids): array => array_intersect_key($this->items, array_flip($ids)),
		);
		$this->openItems->method('findByIds')->willReturnCallback(
			fn (array $ids): array => array_intersect_key($this->claims, array_flip($ids)),
		);
	}

	private function service(): SelfReturnedDebitService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		return new SelfReturnedDebitService($this->returnedDebits, $this->debitItems, $this->openItems, $l10n);
	}

	/** Die Datenbankabfrage nach Mitglied: jedes Mitglied bekommt nur die Rücklastschriften seiner Forderungen. */
	private function databaseFiltersByMember(): void {
		$this->returnedDebits->method('findByMember')->willReturnCallback(
			fn (int $memberId): array => array_values(array_filter(
				$this->returned,
				fn (ReturnedDebit $r): bool => $this->claims[$this->items[$r->getDebitItemId()]->getOpenItemId()]->getMemberId() === $memberId,
			)),
		);
	}

	/**
	 * Eine Rücklastschrift samt Posten und Forderung des Mitglieds anlegen.
	 *
	 * @param int $id gemeinsame ID für Rücklastschrift, Posten und Forderung (hält die Zuordnung im Test lesbar)
	 */
	private function returnedDebit(int $id, int $memberId, ?string $code, string $receivedAt = '2026-10-05', int $amountCents = 1250, ?string $description = 'Vollmitglied'): ReturnedDebit {
		$claim = new OpenItem();
		$claim->setId($id);
		$claim->setMemberId($memberId);
		$claim->setType(OpenItem::TYPE_CONTRIBUTION);
		$claim->setDescription($description);
		$claim->setAmountCents($amountCents);
		$claim->setPeriodStart('2026-10-01');
		$claim->setPeriodEnd('2026-12-31');
		$this->claims[$id] = $claim;

		$item = new DebitItem();
		$item->setId($id);
		$item->setOpenItemId($id);
		$item->setAmountCents($amountCents);
		$item->setIban('DE02120300000000202051');
		$item->setAccountHolder('Kontoinhaber Geheim');
		$item->setMandateReference('MREF-' . $id);
		$item->setEndToEndId('E2E-' . $id);
		$this->items[$id] = $item;

		$returned = new ReturnedDebit();
		$returned->setId($id);
		$returned->setDebitItemId($id);
		$returned->setReasonCode($code);
		$returned->setReasonText('Freitext der Bank zu ' . $id);
		$returned->setReceivedAt($receivedAt);
		$returned->setChargesCents(350);
		return $this->returned[$id] = $returned;
	}

	public function testLiefertNurDieRuecklastschriftenDesAngefragtenMitglieds(): void {
		$this->returnedDebit(1, self::MEMBER_A, 'AM04', '2026-10-05', 1250, 'Beitrag von Anna');
		$this->returnedDebit(2, self::MEMBER_B, 'MD01', '2026-10-06', 4000, 'Beitrag von Bruno');
		$this->databaseFiltersByMember();

		$rowsA = $this->service()->findOwn(self::MEMBER_A);
		$rowsB = $this->service()->findOwn(self::MEMBER_B);

		$this->assertCount(1, $rowsA);
		$this->assertSame('Beitrag von Anna', $rowsA[0]['description']);
		$this->assertSame(1250, $rowsA[0]['amountCents']);
		$this->assertCount(1, $rowsB);
		$this->assertSame('Beitrag von Bruno', $rowsB[0]['description']);
		$this->assertSame(4000, $rowsB[0]['amountCents']);
		$this->assertStringNotContainsString('Bruno', (string)json_encode($rowsA));
		$this->assertStringNotContainsString('Anna', (string)json_encode($rowsB));
	}

	public function testFragtDenMapperAusschliesslichMitDerUebergebenenMemberIdAn(): void {
		$this->returnedDebit(1, self::MEMBER_A, 'AM04');
		$this->returnedDebit(2, self::MEMBER_B, 'AM04');
		$this->returnedDebits->expects($this->once())->method('findByMember')->with(self::MEMBER_A)->willReturn([$this->returned[1]]);

		$this->service()->findOwn(self::MEMBER_A);
	}

	public function testLadetPostenUndForderungenNurDerEigenenRuecklastschriften(): void {
		$this->returnedDebit(1, self::MEMBER_A, 'AM04');
		$this->returnedDebit(2, self::MEMBER_B, 'AM04');
		$debitItems = $this->createMock(DebitItemMapper::class);
		$debitItems->expects($this->once())->method('findByIds')->with([1])->willReturn([1 => $this->items[1]]);
		$openItems = $this->createMock(OpenItemMapper::class);
		$openItems->expects($this->once())->method('findByIds')->with([1])->willReturn([1 => $this->claims[1]]);
		$this->databaseFiltersByMember();
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		(new SelfReturnedDebitService($this->returnedDebits, $debitItems, $openItems, $l10n))->findOwn(self::MEMBER_A);
	}

	/**
	 * Zweite Verteidigungslinie: selbst wenn die Abfrage einmal fremde Zeilen
	 * mitliefert (Join vergessen, Filter verrutscht), gibt der Dienst sie nicht
	 * weiter – maßgeblich ist die Forderung der Rücklastschrift.
	 */
	public function testGibtKeineFremdeZeileWeiterAuchWennDieAbfrageSieLiefert(): void {
		$this->returnedDebit(1, self::MEMBER_A, 'AM04', '2026-10-05', 1250, 'Beitrag von Anna');
		$this->returnedDebit(2, self::MEMBER_B, 'MD01', '2026-10-06', 4000, 'Beitrag von Bruno');
		$this->returnedDebits->method('findByMember')->willReturn([$this->returned[1], $this->returned[2]]);

		$rows = $this->service()->findOwn(self::MEMBER_A);

		$this->assertCount(1, $rows);
		$this->assertSame('Beitrag von Anna', $rows[0]['description']);
		$this->assertStringNotContainsString('Bruno', (string)json_encode($rows));
	}

	public function testZeigtKlartextJeRueckgabeKlasseStattBankcode(): void {
		$this->returnedDebit(1, self::MEMBER_A, 'AM04', '2026-10-01');
		$this->returnedDebit(2, self::MEMBER_A, 'AC04', '2026-10-02');
		$this->returnedDebit(3, self::MEMBER_A, 'MD01', '2026-10-03');
		$this->returnedDebit(4, self::MEMBER_A, 'XX99', '2026-10-04');
		$this->returnedDebit(5, self::MEMBER_A, null, '2026-10-05');
		$this->databaseFiltersByMember();

		$reasons = array_column($this->service()->findOwn(self::MEMBER_A), 'reason');

		// neueste zuerst: ohne Code, unbekannter Code, Widerspruch, Konto, Deckung
		$this->assertSame([
			self::TEXT_UNKNOWN,
			self::TEXT_UNKNOWN,
			self::TEXT_DISPUTED,
			self::TEXT_ACCOUNT_UNUSABLE,
			self::TEXT_INSUFFICIENT_FUNDS,
		], $reasons);
	}

	/**
	 * Allow-Liste: genau diese Felder verlassen den Server – nie `reasonCode`,
	 * `reasonText`, Bankgebühr, IBAN, Kontoinhaber oder technische IDs.
	 */
	public function testZeilenTragenNurDieErlaubtenFelderUndNieCodeOderKontodaten(): void {
		$this->returnedDebit(1, self::MEMBER_A, 'AM04');
		$this->databaseFiltersByMember();

		$rows = $this->service()->findOwn(self::MEMBER_A);

		$this->assertSame(['receivedAt', 'amountCents', 'description', 'periodStart', 'periodEnd', 'reason'], array_keys($rows[0]));
		$this->assertSame([
			'receivedAt' => '2026-10-05',
			'amountCents' => 1250,
			'description' => 'Vollmitglied',
			'periodStart' => '2026-10-01',
			'periodEnd' => '2026-12-31',
			'reason' => self::TEXT_INSUFFICIENT_FUNDS,
		], $rows[0]);
		$json = (string)json_encode($rows);
		foreach (['AM04', 'reasonCode', 'reason_code', 'reasonText', 'reason_text', 'Freitext der Bank', 'DE02120300000000202051', 'Kontoinhaber Geheim', 'MREF-1', 'E2E-1', 'chargesCents'] as $forbidden) {
			$this->assertStringNotContainsString($forbidden, $json, $forbidden . ' darf nie ausgeliefert werden');
		}
	}

	public function testSortiertNeuesteZuerstUndBeiGleichemDatumDieZuletztErfasste(): void {
		$this->returnedDebit(1, self::MEMBER_A, 'AM04', '2026-08-12', 1000, 'August');
		$this->returnedDebit(2, self::MEMBER_A, 'AM04', '2026-10-05', 2000, 'Oktober, zuerst erfasst');
		$this->returnedDebit(3, self::MEMBER_A, 'AM04', '2026-09-01', 3000, 'September');
		$this->returnedDebit(4, self::MEMBER_A, 'AM04', '2026-10-05', 4000, 'Oktober, zuletzt erfasst');
		$this->databaseFiltersByMember();

		$descriptions = array_column($this->service()->findOwn(self::MEMBER_A), 'description');

		$this->assertSame(['Oktober, zuletzt erfasst', 'Oktober, zuerst erfasst', 'September', 'August'], $descriptions);
	}

	public function testOhneRuecklastschriftKommtEineLeereListeUndKeineWeitereAbfrage(): void {
		$this->returnedDebits->method('findByMember')->with(self::MEMBER_A)->willReturn([]);
		$debitItems = $this->createMock(DebitItemMapper::class);
		$debitItems->expects($this->never())->method('findByIds');
		$openItems = $this->createMock(OpenItemMapper::class);
		$openItems->expects($this->never())->method('findByIds');
		$l10n = $this->createMock(IL10N::class);

		$rows = (new SelfReturnedDebitService($this->returnedDebits, $debitItems, $openItems, $l10n))->findOwn(self::MEMBER_A);

		$this->assertSame([], $rows);
	}

	public function testEinMitgliedOhneEigeneRuecklastschriftSiehtKeineFremden(): void {
		$this->returnedDebit(2, self::MEMBER_B, 'AM04');
		$this->databaseFiltersByMember();

		$this->assertSame([], $this->service()->findOwn(self::MEMBER_A));
	}

	public function testUeberspringtRuecklastschriftenOhnePostenOderForderung(): void {
		$this->returnedDebit(1, self::MEMBER_A, 'AM04', '2026-10-05', 1250, 'Beitrag mit Posten');
		$ohnePosten = $this->returnedDebit(2, self::MEMBER_A, 'AM04');
		unset($this->items[2]);
		$ohneForderung = $this->returnedDebit(3, self::MEMBER_A, 'AM04');
		unset($this->claims[3]);
		$this->returnedDebits->method('findByMember')->willReturn([$this->returned[1], $ohnePosten, $ohneForderung]);

		$rows = $this->service()->findOwn(self::MEMBER_A);

		$this->assertCount(1, $rows);
		$this->assertSame('Beitrag mit Posten', $rows[0]['description']);
	}

	public function testBetragIstDerEingezogeneBetragDesPostens(): void {
		$returned = $this->returnedDebit(1, self::MEMBER_A, 'AM04', '2026-10-05', 1250);
		// Die Forderung kann sich nach der Freigabe nicht mehr ändern; maßgeblich ist, was die Bank zurückgegeben hat.
		$this->items[1]->setAmountCents(900);
		$this->returnedDebits->method('findByMember')->willReturn([$returned]);

		$this->assertSame(900, $this->service()->findOwn(self::MEMBER_A)[0]['amountCents']);
	}

	public function testDatumIstNurDerTagOhneUhrzeit(): void {
		$returned = $this->returnedDebit(1, self::MEMBER_A, 'AM04', '2026-10-05');
		$returned->setReceivedAt('2026-10-05T08:30:00+00:00');
		$this->returnedDebits->method('findByMember')->willReturn([$returned]);

		$this->assertSame('2026-10-05', $this->service()->findOwn(self::MEMBER_A)[0]['receivedAt']);
	}
}
