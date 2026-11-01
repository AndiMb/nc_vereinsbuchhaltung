<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Journal;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\Reimbursement;
use OCA\Vereinsbuchhaltung\Db\ReimbursementEvent;
use OCA\Vereinsbuchhaltung\Db\ReimbursementEventMapper;
use OCA\Vereinsbuchhaltung\Db\ReimbursementFile;
use OCA\Vereinsbuchhaltung\Db\ReimbursementFileMapper;
use OCA\Vereinsbuchhaltung\Db\ReimbursementMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Exception\ForbiddenException;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\IbanValidator;
use OCA\Vereinsbuchhaltung\Service\ReimbursementEventLog;
use OCA\Vereinsbuchhaltung\Service\ReimbursementFileService;
use OCA\Vereinsbuchhaltung\Service\ReimbursementMailService;
use OCA\Vereinsbuchhaltung\Service\ReimbursementService;
use OCA\Vereinsbuchhaltung\Service\ReimbursementSettings;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Statusmaschine, Rechte und Zählung der Auslagenerstattung
 * (docs/reimbursement-design.md §4).
 *
 * Mapper sind durch einfache Ablagen im Speicher ersetzt, die Historie läuft
 * über den echten {@see ReimbursementEventLog}: so prüft jeder Test auch, dass
 * die Hash-Kette am Ende stimmt.
 */
class ReimbursementServiceTest extends TestCase {

	private const IBAN = 'DE89370400440532013000';

	/** @var array<int, Reimbursement> */
	private array $reimbursements = [];
	/** @var list<ReimbursementEvent> */
	private array $events = [];
	/** @var list<OpenItem> */
	private array $openItems = [];

	private ReimbursementSettings&MockObject $settings;
	private ReimbursementFileService&MockObject $files;
	private ReimbursementFileMapper&MockObject $fileMapper;
	private ReimbursementMailService&MockObject $mail;
	private JournalMapper&MockObject $journals;
	private AuditService&MockObject $audit;

	/** @var list<string> Verwalter, die nach Einstellung freigeben dürfen */
	private array $approvers = ['vera', 'viktor', 'valentin'];
	private int $required = 2;

	protected function setUp(): void {
		$this->reimbursements = [];
		$this->events = [];
		$this->openItems = [];
		$this->approvers = ['vera', 'viktor', 'valentin'];
		$this->required = 2;

		$this->settings = $this->createMock(ReimbursementSettings::class);
		$this->settings->method('isEnabled')->willReturn(true);
		$this->settings->method('requiredApprovals')->willReturnCallback(fn (): int => $this->required);
		$this->settings->method('approverUids')->willReturnCallback(fn (): array => $this->approvers);
		$this->settings->method('approversFor')->willReturnCallback(
			fn (string $uid): array => array_values(array_filter($this->approvers, static fn (string $a): bool => $a !== $uid)),
		);

		$this->files = $this->createMock(ReimbursementFileService::class);
		$this->files->method('add')->willReturnCallback(function (Reimbursement $r, string $name): ReimbursementFile {
			$file = new ReimbursementFile();
			$file->setId(1);
			$file->setReimbursementId($r->getId());
			$file->setFileName($name);
			return $file;
		});
		$this->files->method('duplicatesOf')->willReturn([]);

		$this->fileMapper = $this->createMock(ReimbursementFileMapper::class);
		$this->mail = $this->createMock(ReimbursementMailService::class);
		$this->journals = $this->createMock(JournalMapper::class);
		$this->journals->method('find')->willReturn(new Journal());
		$this->audit = $this->createMock(AuditService::class);
	}

	private function service(): ReimbursementService {
		$mapper = $this->createMock(ReimbursementMapper::class);
		$mapper->method('insert')->willReturnCallback(function (Reimbursement $r): Reimbursement {
			$r->setId(count($this->reimbursements) + 1);
			$this->reimbursements[$r->getId()] = $r;
			return $r;
		});
		$mapper->method('find')->willReturnCallback(function (int $id): Reimbursement {
			return $this->reimbursements[$id] ?? throw new DoesNotExistException('nope');
		});
		$mapper->method('update')->willReturnArgument(0);

		$eventMapper = $this->createMock(ReimbursementEventMapper::class);
		$eventMapper->method('findLast')->willReturnCallback(function (int $id): ?ReimbursementEvent {
			$mine = array_values(array_filter($this->events, static fn (ReimbursementEvent $e): bool => $e->getReimbursementId() === $id));
			return $mine === [] ? null : $mine[count($mine) - 1];
		});
		$eventMapper->method('insert')->willReturnCallback(function (ReimbursementEvent $e): ReimbursementEvent {
			$e->setId(count($this->events) + 1);
			$this->events[] = $e;
			return $e;
		});
		$eventMapper->method('findByReimbursement')->willReturnCallback(
			fn (int $id): array => array_values(array_filter($this->events, static fn (ReimbursementEvent $e): bool => $e->getReimbursementId() === $id)),
		);
		$eventMapper->method('approverUids')->willReturnCallback(function (int $id): array {
			$uids = [];
			foreach ($this->events as $e) {
				if ($e->getReimbursementId() === $id && $e->getAction() === ReimbursementEvent::ACTION_APPROVED) {
					$uids[] = $e->getActorUid();
				}
			}
			return array_values(array_unique($uids));
		});

		$openItemMapper = $this->createMock(OpenItemMapper::class);
		$openItemMapper->method('insert')->willReturnCallback(function (OpenItem $item): OpenItem {
			$item->setId(count($this->openItems) + 77);
			$this->openItems[$item->getId()] = $item;
			return $item;
		});
		$openItemMapper->method('find')->willReturnCallback(fn (int $id): OpenItem => $this->openItems[$id]);
		$openItemMapper->method('update')->willReturnArgument(0);

		$memberMapper = $this->createMock(MemberMapper::class);
		$memberMapper->method('find')->willReturnCallback(fn (int $id): Member => $this->member($id));

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): \DateTime => new \DateTime('2026-11-01 10:00:00'));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new ReimbursementService(
			$mapper,
			$this->fileMapper,
			$openItemMapper,
			$memberMapper,
			$this->journals,
			new ReimbursementEventLog($eventMapper, $time),
			$this->settings,
			$this->files,
			$this->mail,
			new IbanValidator($l10n),
			new TransactionRunner($this->createMock(IDBConnection::class)),
			$this->audit,
			$time,
			$l10n,
		);
	}

	private function member(int $id = 1): Member {
		$member = new Member();
		$member->setId($id);
		$member->setFirstName('Anna');
		$member->setLastName('Musterfrau');
		return $member;
	}

	private function submit(ReimbursementService $service, string $actor = 'anna'): Reimbursement {
		return $this->submitWith($service, [], $actor);
	}

	/**
	 * Reicht mit gültigen Standardangaben ein; `$override` ersetzt einzelne davon (Schlüssel wie die
	 * Parameter von `ReimbursementService::submit()`).
	 *
	 * @param array<string,mixed> $override
	 */
	private function submitWith(ReimbursementService $service, array $override, string $actor = 'anna'): Reimbursement {
		return $service->submit(...array_replace([
			'member' => $this->member(),
			'amountCents' => 1250,
			'expenseDate' => '2026-10-15',
			'purpose' => 'Material Jugendfreizeit',
			'invoiceNumber' => 'R-2026-17',
			'supplier' => 'Bastelshop',
			'iban' => self::IBAN,
			'accountHolder' => 'Anna Musterfrau',
			'declarationAccepted' => true,
			'uploads' => [['name' => 'beleg.pdf', 'content' => '%PDF']],
			'actorUid' => $actor,
		], $override));
	}

	private function assertChainIsValid(ReimbursementService $service, int $id): void {
		$this->assertTrue(ReimbursementEventLog::verify($service->history($id)), 'Hash-Kette der Historie ist gebrochen');
	}

	// --- Einreichen ---------------------------------------------------------

	public function testEinreichenLegtAntragMitSchwellenSnapshotAnUndProtokolliert(): void {
		$service = $this->service();
		$r = $this->submit($service);

		$this->assertSame(Reimbursement::STATUS_SUBMITTED, $r->getStatus());
		$this->assertSame(2, $r->getRequiredApprovals());
		$this->assertSame(1250, $r->getAmountCents());
		$this->assertSame(self::IBAN, $r->getIban());
		$this->assertSame('anna', $r->getSubmittedBy());

		$history = $service->history($r->getId());
		$this->assertCount(1, $history);
		$this->assertSame(ReimbursementEvent::ACTION_SUBMITTED, $history[0]->getAction());
		$this->assertSame(['beleg.pdf'], $history[0]->decodedData()['receipts']);
		$this->assertChainIsValid($service, $r->getId());
	}

	public function testEinreichenNormalisiertDieIban(): void {
		$service = $this->service();
		$r = $this->submitWith($service, ['iban' => 'de89 3704 0044 0532 0130 00']);

		$this->assertSame(self::IBAN, $r->getIban());
	}

	public function testEinreichenOhneAktiveFunktionScheitert(): void {
		$settings = $this->createMock(ReimbursementSettings::class);
		$settings->method('isEnabled')->willReturn(false);
		$this->settings = $settings;

		$this->expectException(\InvalidArgumentException::class);
		$this->submit($this->service());
	}

	/** @param array<string,mixed> $override Abweichungen vom gültigen Antrag (siehe submitWith) */
	#[DataProvider('ungueltigeAntraege')]
	public function testEinreichenPrueftDieAngaben(array $override): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->submitWith($this->service(), $override);
	}

	public static function ungueltigeAntraege(): array {
		return [
			'Betrag null' => [['amountCents' => 0]],
			'Betrag negativ' => [['amountCents' => -5]],
			'Datum in der Zukunft' => [['expenseDate' => '2026-12-24']],
			'Datum kein Datum' => [['expenseDate' => 'gestern']],
			'Zweck leer' => [['purpose' => '   ']],
			'Zweck zu lang' => [['purpose' => str_repeat('x', 501)]],
			'Rechnungsnummer zu lang' => [['invoiceNumber' => str_repeat('1', 101)]],
			'Lieferant zu lang' => [['supplier' => str_repeat('x', 256)]],
			'Kontoinhaber zu lang' => [['accountHolder' => str_repeat('x', 256)]],
			'IBAN leer' => [['iban' => '  ']],
			'IBAN kaputt' => [['iban' => 'kein iban']],
			'Ohne Erklärung' => [['declarationAccepted' => false]],
			'Ohne Beleg' => [['uploads' => []]],
		];
	}

	public function testRechnungsnummerUndLieferantWerdenGespeichertLeereAlsNull(): void {
		$r = $this->submit($this->service());
		$this->assertSame('R-2026-17', $r->getInvoiceNumber());
		$this->assertSame('Bastelshop', $r->getSupplier());

		$r = $this->submitWith($this->service(), ['invoiceNumber' => '  ', 'supplier' => null]);
		$this->assertNull($r->getInvoiceNumber(), 'leere Angaben werden nicht als leerer Text gespeichert');
		$this->assertNull($r->getSupplier());
	}

	public function testKontoinhaberFehltDannGiltDerNameDesMitglieds(): void {
		$r = $this->submitWith($this->service(), ['accountHolder' => '  ']);

		$this->assertSame('Anna Musterfrau', $r->getAccountHolder());
	}

	public function testKontoinhaberWirdGetrimmtGespeichert(): void {
		$r = $this->submitWith($this->service(), ['accountHolder' => ' Familie Musterfrau ']);

		$this->assertSame('Familie Musterfrau', $r->getAccountHolder());
	}

	public function testDieBestaetigteErklaerungStehtInDerHistorie(): void {
		$service = $this->service();
		$r = $this->submit($service);

		$this->assertSame($service->declarationText(), $service->history($r->getId())[0]->decodedData()['declaration']);
		$this->assertStringContainsString('im Auftrag des Vereins', $service->declarationText());
		$this->assertChainIsValid($service, $r->getId());
	}

	public function testMehrereRechnungenSindMehrereUnabhaengigeAntraege(): void {
		$service = $this->service();
		$first = $this->submitWith($service, ['purpose' => 'Noten', 'amountCents' => 1000]);
		$second = $this->submitWith($service, ['purpose' => 'Porto', 'amountCents' => 550]);

		$service->approve($first->getId(), 'vera');
		$service->reject($second->getId(), 'viktor', 'Privat');

		$this->assertSame(Reimbursement::STATUS_PARTIALLY_APPROVED, $first->getStatus());
		$this->assertSame(Reimbursement::STATUS_REJECTED, $second->getStatus());
		$this->assertNotSame($first->getId(), $second->getId());
	}

	public function testEinreichenScheitertWennNichtGenugFreigeberUebrigSind(): void {
		// Zwei Freigaben nötig, aber ohne den einreichenden Verwalter bleibt nur einer.
		$this->approvers = ['vera', 'viktor'];

		$this->expectException(\InvalidArgumentException::class);
		$this->submit($this->service(), 'vera');
	}

	// --- Freigeben ------------------------------------------------------------

	public function testErsteFreigabeMachtDenAntragTeilweiseFreigegebenOhnePosten(): void {
		$service = $this->service();
		$r = $this->submit($service);

		$r = $service->approve($r->getId(), 'vera');

		$this->assertSame(Reimbursement::STATUS_PARTIALLY_APPROVED, $r->getStatus());
		$this->assertNull($r->getOpenItemId());
		$this->assertSame([], $this->openItems);
		$this->assertSame(['approved' => 1, 'required' => 2], $service->progress($r));
	}

	public function testLetzteNoetigeFreigabeLegtDenOffenenPostenAn(): void {
		$service = $this->service();
		$r = $this->submit($service);

		$service->approve($r->getId(), 'vera');
		$r = $service->approve($r->getId(), 'viktor');

		$this->assertSame(Reimbursement::STATUS_APPROVED, $r->getStatus());
		$this->assertNotNull($r->getDecidedAt());
		$this->assertCount(1, $this->openItems);
		$item = $this->openItems[$r->getOpenItemId()];
		$this->assertNull($item->getType(), 'Ein Typ machte den Posten zum Teil des Beitragsmoduls');
		$this->assertSame($r->getId(), $item->getReimbursementId());
		$this->assertSame(1250, $item->getAmountCents());
		$this->assertSame('open', $item->getStatus());
		$this->assertSame('Anna Musterfrau', $item->getDebtor());
		$this->assertSame('AUSL-' . $r->getId(), $item->getReference());
		$this->assertAuslageIstKeineForderung($item);
		$this->assertChainIsValid($service, $r->getId());
	}

	private function assertAuslageIstKeineForderung(OpenItem $item): void {
		$this->assertNull($item->getMemberId(), 'memberId bliebe gesetzt, würde der Posten als Beitragsforderung gelten');
		$this->assertFalse($item->isClaim());
		$this->assertTrue($item->belongsToReimbursement());
		$this->assertNull($item->getDueDate());
		$this->assertFalse($item->isOverdue());
	}

	public function testEineFreigabeGenuegtBeiSchwelleEins(): void {
		$this->required = 1;
		$service = $this->service();
		$r = $this->submit($service);

		$r = $service->approve($r->getId(), 'vera');

		$this->assertSame(Reimbursement::STATUS_APPROVED, $r->getStatus());
		$this->assertCount(1, $this->openItems);
	}

	public function testDerEinreicherGibtSeinenAntragNichtFrei(): void {
		$service = $this->service();
		$r = $this->submit($service, 'vera');

		$this->expectException(ForbiddenException::class);
		$service->approve($r->getId(), 'vera');
	}

	public function testNurVerwalterGebenFrei(): void {
		$service = $this->service();
		$r = $this->submit($service);

		$this->expectException(ForbiddenException::class);
		$service->approve($r->getId(), 'buchhalter');
	}

	public function testJederVerwalterZaehltHoechstensEinmal(): void {
		$service = $this->service();
		$r = $this->submit($service);
		$service->approve($r->getId(), 'vera');

		try {
			$service->approve($r->getId(), 'vera');
			$this->fail('Zweite Freigabe derselben Person muss scheitern');
		} catch (ForbiddenException) {
		}
		$this->assertSame(Reimbursement::STATUS_PARTIALLY_APPROVED, $this->reimbursements[$r->getId()]->getStatus());
		$this->assertSame(['approved' => 1, 'required' => 2], $service->progress($r));
	}

	public function testBereitsFreigegebenerAntragStehtNichtMehrZurEntscheidung(): void {
		$service = $this->service();
		$r = $this->submit($service);
		$service->approve($r->getId(), 'vera');
		$service->approve($r->getId(), 'viktor');

		$this->expectException(\InvalidArgumentException::class);
		$service->approve($r->getId(), 'valentin');
	}

	public function testDieSchwelleBleibtAmAntragAuchWennDieEinstellungSpaeterSinkt(): void {
		$service = $this->service();
		$r = $this->submit($service); // N = 2 wird am Antrag festgehalten
		$this->required = 1;

		$r = $service->approve($r->getId(), 'vera');

		$this->assertSame(2, $r->getRequiredApprovals());
		$this->assertSame(Reimbursement::STATUS_PARTIALLY_APPROVED, $r->getStatus());
		$this->assertSame([], $this->openItems);
	}

	public function testDieZaehlungStehtInDerHistorie(): void {
		$service = $this->service();
		$r = $this->submit($service);
		$service->approve($r->getId(), 'vera');
		$service->approve($r->getId(), 'viktor');

		$approvals = array_values(array_filter(
			$service->history($r->getId()),
			static fn (ReimbursementEvent $e): bool => $e->getAction() === ReimbursementEvent::ACTION_APPROVED,
		));
		$this->assertSame(['count' => 1, 'required' => 2, 'final' => false], $approvals[0]->decodedData());
		$this->assertSame(['count' => 2, 'required' => 2, 'final' => true], $approvals[1]->decodedData());
		$this->assertSame('vera', $approvals[0]->getActorUid());
	}

	// --- Ablehnen -------------------------------------------------------------

	public function testEinVerwalterLehntSofortEndgueltigAb(): void {
		$service = $this->service();
		$r = $this->submit($service);

		$r = $service->reject($r->getId(), 'vera', '  Beleg unleserlich ');

		$this->assertSame(Reimbursement::STATUS_REJECTED, $r->getStatus());
		$this->assertSame('Beleg unleserlich', $r->getRejectionReason());
		$this->assertSame([], $this->openItems);
		$this->assertChainIsValid($service, $r->getId());
	}

	public function testAblehnenIstAuchNachTeilfreigabeMoeglich(): void {
		$service = $this->service();
		$r = $this->submit($service);
		$service->approve($r->getId(), 'vera');

		$r = $service->reject($r->getId(), 'viktor', 'Nicht vereinsbezogen');

		$this->assertSame(Reimbursement::STATUS_REJECTED, $r->getStatus());
	}

	public function testAblehnenVerlangtEineBegruendung(): void {
		$service = $this->service();
		$r = $this->submit($service);

		$this->expectException(\InvalidArgumentException::class);
		$service->reject($r->getId(), 'vera', '   ');
	}

	public function testDenEigenenAntragLehntNiemandAb(): void {
		$service = $this->service();
		$r = $this->submit($service, 'vera');

		$this->expectException(ForbiddenException::class);
		$service->reject($r->getId(), 'vera', 'Doch nicht');
	}

	public function testAbgelehnterAntragLaesstSichNichtMehrFreigeben(): void {
		$service = $this->service();
		$r = $this->submit($service);
		$service->reject($r->getId(), 'vera', 'Nein');

		$this->expectException(\InvalidArgumentException::class);
		$service->approve($r->getId(), 'viktor');
	}

	// --- Zurückziehen -----------------------------------------------------------

	public function testDasMitgliedZiehtDenAntragVorDerErstenFreigabeZurueck(): void {
		$service = $this->service();
		$r = $this->submit($service);

		$r = $service->withdraw($r->getId(), 1, 'anna');

		$this->assertSame(Reimbursement::STATUS_WITHDRAWN, $r->getStatus());
		$this->assertChainIsValid($service, $r->getId());
	}

	public function testNachEinerFreigabeLaesstSichNichtMehrZurueckziehen(): void {
		$service = $this->service();
		$r = $this->submit($service);
		$service->approve($r->getId(), 'vera');

		$this->expectException(\InvalidArgumentException::class);
		$service->withdraw($r->getId(), 1, 'anna');
	}

	public function testEinFremderAntragLaesstSichNichtZurueckziehen(): void {
		$service = $this->service();
		$r = $this->submit($service);

		$this->expectException(DoesNotExistException::class);
		$service->withdraw($r->getId(), 2, 'bernd');
	}

	public function testZurueckgezogenerAntragLaesstSichNichtFreigeben(): void {
		$service = $this->service();
		$r = $this->submit($service);
		$service->withdraw($r->getId(), 1, 'anna');

		$this->expectException(\InvalidArgumentException::class);
		$service->approve($r->getId(), 'vera');
	}

	// --- Belege -----------------------------------------------------------------

	public function testBelegNachreichenIstNurVorDerErstenFreigabeMoeglich(): void {
		$service = $this->service();
		$r = $this->submit($service);
		$service->approve($r->getId(), 'vera');

		$this->expectException(\InvalidArgumentException::class);
		$service->addReceipt($r->getId(), 1, 'anna', 'zweiter.pdf', 'x');
	}

	public function testBelegNachreichenStehtInDerHistorie(): void {
		$service = $this->service();
		$r = $this->submit($service);

		$service->addReceipt($r->getId(), 1, 'anna', 'zweiter.pdf', 'x');

		$actions = array_map(static fn (ReimbursementEvent $e): string => $e->getAction(), $service->history($r->getId()));
		$this->assertSame([ReimbursementEvent::ACTION_SUBMITTED, ReimbursementEvent::ACTION_RECEIPT_ADDED], $actions);
	}

	public function testDerLetzteBelegBleibt(): void {
		$service = $this->service();
		$r = $this->submit($service);
		$file = new ReimbursementFile();
		$file->setId(5);
		$file->setReimbursementId($r->getId());
		$this->files->method('findActiveFor')->willReturn($file);
		$this->fileMapper->method('countActive')->willReturn(1);

		$this->expectException(\InvalidArgumentException::class);
		$service->removeReceipt($r->getId(), 5, 1, 'anna');
	}

	public function testBelegEntfernenWennNochAndereDaSind(): void {
		$service = $this->service();
		$r = $this->submit($service);
		$file = new ReimbursementFile();
		$file->setId(5);
		$file->setReimbursementId($r->getId());
		$file->setFileName('alt.pdf');
		$this->files->method('findActiveFor')->willReturn($file);
		$this->fileMapper->method('countActive')->willReturn(2);
		$this->files->expects($this->once())->method('remove')->with($file);

		$service->removeReceipt($r->getId(), 5, 1, 'anna');

		$history = $service->history($r->getId());
		$this->assertSame(ReimbursementEvent::ACTION_RECEIPT_REMOVED, $history[count($history) - 1]->getAction());
		$this->assertSame(['file' => 'alt.pdf'], $history[count($history) - 1]->decodedData());
	}

	public function testDateiEinesAnderenAntragsLaesstSichNichtEntfernen(): void {
		$service = $this->service();
		$r = $this->submit($service);
		$this->files->method('findActiveFor')->willThrowException(new DoesNotExistException('nope'));
		$this->files->expects($this->never())->method('remove');

		$this->expectException(DoesNotExistException::class);
		$service->removeReceipt($r->getId(), 5, 1, 'anna');
	}

	// --- Auszahlen ---------------------------------------------------------------

	private function approved(ReimbursementService $service): Reimbursement {
		$r = $this->submit($service);
		$service->approve($r->getId(), 'vera');
		return $service->approve($r->getId(), 'viktor');
	}

	public function testAuszahlenErledigtAntragUndOffenenPostenUndHaengtDieBelegeAn(): void {
		$service = $this->service();
		$r = $this->approved($service);
		$this->files->expects($this->once())->method('linkToJournal')->with($r, 42);

		$r = $service->markPaid($r->getId(), 42, '2026-11-01', 'berta');

		$this->assertSame(Reimbursement::STATUS_PAID, $r->getStatus());
		$this->assertSame('2026-11-01', $r->getPaidAt());
		$this->assertSame(42, $r->getJournalId());
		$item = $this->openItems[$r->getOpenItemId()];
		$this->assertSame('paid', $item->getStatus());
		$this->assertSame(42, $item->getPaidJournalId());
		$this->assertSame(1250, $item->getSettledCents());
		$this->assertSame('berta', $item->getSettledBy());
		$this->assertChainIsValid($service, $r->getId());
	}

	public function testNurEinFreigegebenerAntragLaesstSichAuszahlen(): void {
		$service = $this->service();
		$r = $this->submit($service);
		$service->approve($r->getId(), 'vera');

		$this->expectException(\InvalidArgumentException::class);
		$service->markPaid($r->getId(), 42, '2026-11-01', 'berta');
	}

	public function testZweimalAuszahlenGehtNicht(): void {
		$service = $this->service();
		$r = $this->approved($service);
		$service->markPaid($r->getId(), 42, '2026-11-01', 'berta');

		$this->expectException(\InvalidArgumentException::class);
		$service->markPaid($r->getId(), 43, '2026-11-02', 'berta');
	}

	public function testAuszahlenVerlangtEineVorhandeneBuchung(): void {
		$this->journals = $this->createMock(JournalMapper::class);
		$this->journals->method('find')->willThrowException(new DoesNotExistException('nope'));
		$service = $this->service();
		$r = $this->approved($service);
		$this->files->expects($this->never())->method('linkToJournal');

		try {
			$service->markPaid($r->getId(), 999, '2026-11-01', 'berta');
			$this->fail('Eine nicht vorhandene Buchung muss abgelehnt werden');
		} catch (\InvalidArgumentException) {
		}
		$this->assertSame(Reimbursement::STATUS_APPROVED, $this->reimbursements[$r->getId()]->getStatus());
	}

	public function testAuszahlungsdatumDarfNichtInDerZukunftLiegen(): void {
		$service = $this->service();
		$r = $this->approved($service);

		$this->expectException(\InvalidArgumentException::class);
		$service->markPaid($r->getId(), 42, '2027-01-01', 'berta');
	}

	// --- Mitgliedersicht ------------------------------------------------------------

	public function testEinFremderAntragVerhaeltSichWieNichtVorhanden(): void {
		$service = $this->service();
		$r = $this->submit($service);

		$this->expectException(DoesNotExistException::class);
		$service->findForMember($r->getId(), 2);
	}

	// --- Mails ------------------------------------------------------------------------

	public function testNeuerAntragBenachrichtigtDieVerwalter(): void {
		$this->mail->expects($this->once())->method('submitted')->with($this->isInstanceOf(Reimbursement::class));
		$this->mail->expects($this->never())->method('approved');

		$this->submit($this->service());
	}

	public function testTeilfreigabeSchicktKeineMailLetzteFreigabeSchon(): void {
		$service = $this->service();
		$r = $this->submit($service);
		$this->mail->expects($this->once())->method('approved');

		$service->approve($r->getId(), 'vera');
		$service->approve($r->getId(), 'viktor');
	}

	public function testAblehnungUndAuszahlungSchickenJeEineMail(): void {
		$service = $this->service();
		$rejected = $this->submit($service);
		$this->mail->expects($this->once())->method('rejected');
		$this->mail->expects($this->once())->method('paid');

		$service->reject($rejected->getId(), 'vera', 'Nein');
		$approved = $this->approved($service);
		$service->markPaid($approved->getId(), 42, '2026-11-01', 'berta');
	}

	public function testScheiterndeEinreichungSchicktKeineMail(): void {
		$this->mail->expects($this->never())->method('submitted');

		try {
			$this->submitWith($this->service(), ['amountCents' => 0]);
		} catch (\InvalidArgumentException) {
		}
	}

	public function testDerKomplettverlaufHinterlaesstEineLueckenloseKette(): void {
		$service = $this->service();
		$r = $this->approved($service);
		$service->markPaid($r->getId(), 42, '2026-11-01', 'berta');

		$actions = array_map(static fn (ReimbursementEvent $e): string => $e->getAction(), $service->history($r->getId()));
		$this->assertSame(['submitted', 'approved', 'approved', 'paid'], $actions);
		$this->assertChainIsValid($service, $r->getId());
	}
}
