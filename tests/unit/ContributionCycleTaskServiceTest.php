<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\Task;
use OCA\Vereinsbuchhaltung\Service\ContributionCycleSettings;
use OCA\Vereinsbuchhaltung\Service\ContributionCycleTaskService;
use OCA\Vereinsbuchhaltung\Service\DirectDebitEligibilityResolver;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Störfälle/Aufgaben des Einzugszyklus (Spec §3.5/§7, Issue #70) – siehe
 * Klassendoc von {@see ContributionCycleTaskService}: nie blockierend, nie
 * quittiert, zwei Schweregrade.
 */
class ContributionCycleTaskServiceTest extends TestCase {

	private OpenItemMapper&MockObject $openItems;
	private AssignmentMapper&MockObject $assignments;
	private MandateMapper&MockObject $mandates;
	private MemberMapper&MockObject $members;
	/** @var array<string,string> */
	private array $configStore = [];

	protected function setUp(): void {
		$this->configStore = [];
		$this->openItems = $this->createMock(OpenItemMapper::class);
		$this->assignments = $this->createMock(AssignmentMapper::class);
		$this->mandates = $this->createMock(MandateMapper::class);
		$this->members = $this->createMock(MemberMapper::class);
		$this->members->method('displayNameOr')->willReturn('Max Mustermann');

		// Bewusst KEINE Default-Rueckgabe fuer findClaims()/
		// findClaimsAwaitingPrenotification()/findActiveAsOf() hier: PHPUnit
		// laesst bei mehrfach konfigurierten method()-Stubs ohne
		// unterscheidendes with() den ZUERST konfigurierten gewinnen - ein
		// Ueberschreiben in einem Einzeltest griffe also nicht. Ohne
		// Konfiguration generiert PHPUnit fuer den deklarierten Rueckgabetyp
		// `array` automatisch `[]`, das genuegt als impliziter Default.
	}

	private function setMandateActive(bool $active): void {
		$this->mandates->method('findLiveByMember')->willReturn($active ? [$this->activeMandate()] : []);
	}

	private function activeMandate(): Mandate {
		$m = new Mandate();
		$m->setStatus(Mandate::STATUS_ACTIVE);
		return $m;
	}

	private function assignment(int $id, int $memberId, string $paymentMethod = Assignment::PAYMENT_METHOD_DIRECT_DEBIT): Assignment {
		$a = new Assignment();
		$a->setId($id);
		$a->setMemberId($memberId);
		$a->setPaymentMethod($paymentMethod);
		$a->setValidFrom('2026-01-01');
		return $a;
	}

	private function claim(int $id, int $memberId, string $dueDate, ?int $assignmentId = null, int $amountCents = 1000): OpenItem {
		$item = new OpenItem();
		$item->setId($id);
		$item->setMemberId($memberId);
		$item->setType(OpenItem::TYPE_CONTRIBUTION);
		$item->setStatus('open');
		$item->setDescription('Basisbeitrag');
		$item->setAmountCents($amountCents);
		$item->setDueDate($dueDate);
		$item->setAssignmentId($assignmentId);
		return $item;
	}

	private function service(string $today): ContributionCycleTaskService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => array_key_exists($key, $this->configStore) ? $this->configStore[$key] : $default,
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$l10n->method('n')->willReturnCallback(static function (string $singular, string $plural, int $count, array $parameters = []): string {
			$text = str_replace('%n', (string)$count, $count === 1 ? $singular : $plural);
			return vsprintf($text, $parameters);
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime($today));

		return new ContributionCycleTaskService(
			$this->openItems,
			$this->assignments,
			$this->members,
			new DirectDebitEligibilityResolver($this->assignments, $this->mandates),
			new ContributionCycleSettings($config),
			$time,
			$l10n,
		);
	}

	public function testKeineAufgabenOhneAuffaelligkeiten(): void {
		$this->assertSame([], $this->service('2026-01-01')->findTasks());
	}

	public function testFehlendesMandatErzeugtHandlungsbedarf(): void {
		$this->setMandateActive(false);
		$this->assignments->method('findActiveAsOf')->willReturn([$this->assignment(1, 7)]);

		$tasks = $this->service('2026-01-01')->findTasks();

		$missingMandateTasks = array_values(array_filter($tasks, fn ($t) => $t['objectType'] === 'assignment'));
		$this->assertCount(1, $missingMandateTasks);
		$this->assertSame(Task::SEVERITY_ACTION_REQUIRED, $missingMandateTasks[0]['severity']);
		$this->assertSame(1, $missingMandateTasks[0]['objectId']);
	}

	public function testAktivesMandatErzeugtKeineAufgabe(): void {
		$this->setMandateActive(true);
		$this->assignments->method('findActiveAsOf')->willReturn([$this->assignment(1, 7)]);

		$this->assertSame([], $this->service('2026-01-01')->findTasks());
	}

	public function testGerisseneVorlauffristEskaliert(): void {
		$this->setMandateActive(true);
		// Faellig in 3 Tagen, Standard-Vorlauffrist 14 Tage laengst verstrichen.
		$claim = $this->claim(5, 7, '2026-01-04');
		$this->openItems->method('findClaimsAwaitingPrenotification')->willReturn([$claim]);

		$tasks = $this->service('2026-01-01')->findTasks();

		$brokenLeadTimeTasks = array_values(array_filter($tasks, fn ($t) => $t['objectType'] === 'claim'));
		$this->assertCount(1, $brokenLeadTimeTasks);
		$this->assertSame(Task::SEVERITY_ACTION_REQUIRED, $brokenLeadTimeTasks[0]['severity']);
		$this->assertSame(5, $brokenLeadTimeTasks[0]['objectId']);
	}

	public function testNochNichtGerisseneVorlauffristErzeugtNichts(): void {
		$this->setMandateActive(true);
		// Faellig in 30 Tagen - die 14-Tage-Frist ist noch laengst nicht angebrochen.
		$claim = $this->claim(5, 7, '2026-01-31');
		$this->openItems->method('findClaimsAwaitingPrenotification')->willReturn([$claim]);

		$this->assertSame([], $this->service('2026-01-01')->findTasks());
	}

	public function testUeberfaelligeUeberweiserForderungenWerdenAggregiert(): void {
		$assignment = $this->assignment(1, 7, Assignment::PAYMENT_METHOD_TRANSFER);
		$this->assignments->method('findAll')->willReturn([$assignment]);
		$overdue1 = $this->claim(10, 7, '2025-12-01', 1, 1500);
		$overdue2 = $this->claim(11, 7, '2025-12-15', 1, 2500);
		$this->openItems->method('findClaims')->willReturn([$overdue1, $overdue2]);

		$tasks = $this->service('2026-01-01')->findTasks();

		$this->assertCount(1, $tasks);
		$this->assertSame(Task::SEVERITY_HINT, $tasks[0]['severity']);
		$this->assertStringContainsString('2', $tasks[0]['message']);
		$this->assertStringContainsString('40,00', $tasks[0]['message']);
	}

	public function testUeberfaelligeUeberweiserForderungenKostenNurEineZuweisungsAbfrage(): void {
		// Viele überfällige Forderungen: die Zuweisungen werden einmal geladen, nicht je Forderung.
		$transfer = $this->assignment(1, 7, Assignment::PAYMENT_METHOD_TRANSFER);
		$claims = [];
		for ($i = 0; $i < 30; $i++) {
			$claims[] = $this->claim(100 + $i, 7, '2025-12-01', 1, 1000);
		}
		$this->openItems->method('findClaims')->willReturn($claims);
		$this->assignments->expects($this->once())->method('findAll')->willReturn([$transfer]);
		$this->assignments->expects($this->never())->method('find');

		$tasks = $this->service('2026-01-01')->findTasks();

		$this->assertCount(1, $tasks);
		$this->assertStringContainsString('300,00', $tasks[0]['message']);
	}

	public function testLastschriftForderungenZaehlenNichtAlsUeberweiserForderung(): void {
		$this->assignments->method('findAll')->willReturn([$this->assignment(1, 7, Assignment::PAYMENT_METHOD_DIRECT_DEBIT)]);
		$this->openItems->method('findClaims')->willReturn([$this->claim(10, 7, '2025-12-01', 1, 1500)]);

		$this->assertSame([], $this->service('2026-01-01')->findTasks());
	}

	// --- „Kein Mandat“ in der Aufgabenliste: nur ohne genauere Mandat-Aufgabe (Issue #117) ---

	private function mandateOf(int $memberId, string $status, string $signatureType = Mandate::SIGNATURE_PAPER): Mandate {
		$m = new Mandate();
		$m->setMemberId($memberId);
		$m->setStatus($status);
		$m->setSignatureType($signatureType);
		return $m;
	}

	/** @return array<string, array{0:Mandate,1:bool}> Mandat des Mitglieds => ob die allgemeine Zeile bleibt */
	public static function explainedProvider(): array {
		$mandate = static function (string $status, string $signatureType = Mandate::SIGNATURE_PAPER): Mandate {
			$m = new Mandate();
			$m->setMemberId(7);
			$m->setStatus($status);
			$m->setSignatureType($signatureType);
			return $m;
		};
		return [
			'Papier-Entwurf: eigene Aufgabe "Unterschrift fehlt"' => [$mandate(Mandate::STATUS_DRAFT), false],
			'gesperrt: eigene Aufgabe "Klärung offen"' => [$mandate(Mandate::STATUS_SUSPENDED), false],
			'erloschen: eigene Aufgabe "Lastschrift weiter gewollt"' => [$mandate(Mandate::STATUS_ENDED), false],
			// Ob ein elektronischer Entwurf eine eigene Aufgabe hat, hängt am Link - die allgemeine Zeile bleibt als Netz.
			'elektronischer Entwurf: allgemeine Zeile bleibt' => [$mandate(Mandate::STATUS_DRAFT, Mandate::SIGNATURE_ELECTRONIC), true],
		];
	}

	/** @dataProvider explainedProvider */
	public function testAllgemeineZeileEntfaelltInDerAufgabenlisteWennEineGenauereAufgabeDieUrsacheNennt(Mandate $mandate, bool $expectGeneric): void {
		$this->mandates->method('findLiveByMember')->willReturn($mandate->getStatus() === Mandate::STATUS_ENDED ? [] : [$mandate]);
		$this->mandates->method('findAll')->willReturn([$mandate]);
		$this->assignments->method('findActiveAsOf')->willReturn([$this->assignment(1, 7)]);

		$tasks = $this->service('2026-01-01')->findTasks(null, true);

		$generic = array_values(array_filter($tasks, fn ($t) => $t['objectType'] === 'assignment'));
		$this->assertCount($expectGeneric ? 1 : 0, $generic);
	}

	public function testAllgemeineZeileBleibtOhneJedesMandat(): void {
		$this->mandates->method('findLiveByMember')->willReturn([]);
		$this->mandates->method('findAll')->willReturn([]);
		$this->assignments->method('findActiveAsOf')->willReturn([$this->assignment(1, 7)]);

		$tasks = $this->service('2026-01-01')->findTasks(null, true);

		$this->assertCount(1, array_filter($tasks, fn ($t) => $t['objectType'] === 'assignment'));
	}

	public function testAllgemeineZeileBleibtStandardmaessigUndInDerLaufVorschauUnveraendert(): void {
		// Forderungsübersicht (Issue #104) und Geisterkarte (Issue #102) lesen weiter die
		// allgemeine Aussage „nicht einzugsfähig“, egal warum.
		$draft = $this->mandateOf(7, Mandate::STATUS_DRAFT);
		$this->mandates->method('findLiveByMember')->willReturn([$draft]);
		$this->mandates->method('findAll')->willReturn([$draft]);
		$this->assignments->method('findActiveAsOf')->willReturn([$this->assignment(1, 7)]);
		$service = $this->service('2026-01-01');

		$this->assertCount(1, array_filter($service->findTasks(), fn ($t) => $t['objectType'] === 'assignment'));
		$this->assertCount(1, array_filter($service->findRunIssues('2026-03-01'), fn ($t) => $t['objectType'] === 'assignment'));
	}

	public function testNaechsterLaufFasstForderungenDesselbenTerminsZusammen(): void {
		$this->setMandateActive(true);
		$dueSoon1 = $this->claim(20, 7, '2026-01-15', null, 1000);
		$dueSoon2 = $this->claim(21, 8, '2026-01-15', null, 2000);
		$dueLater = $this->claim(22, 9, '2026-02-15', null, 3000); // ausserhalb des 21-Tage-Vorwarnfensters
		$this->openItems->method('findClaims')->willReturn([$dueSoon1, $dueSoon2, $dueLater]);

		$tasks = $this->service('2026-01-01')->findTasks();

		$runTasks = array_values(array_filter($tasks, fn ($t) => str_contains($t['message'], 'Nächster Lauf')));
		$this->assertCount(1, $runTasks);
		$this->assertStringContainsString('2026-01-15', $runTasks[0]['message']);
		$this->assertStringContainsString('30,00', $runTasks[0]['message']);
	}

	// --- Störfälle zu einem Termin (Geisterkarte, Issue #102) ----------------------------

	public function testRunIssuesKombiniertKeinMandatMitVorabinfoProblemNurDesTermins(): void {
		// Nur der Vorabinfo-Zweig: zwei Forderungen mit gerissener Frist, aber
		// nur die am abgefragten Termin zaehlt.
		$this->setMandateActive(true);
		$onDate = $this->claim(5, 7, '2026-01-04');
		$otherDate = $this->claim(6, 7, '2026-01-05');
		$this->openItems->method('findClaimsAwaitingPrenotification')->willReturn([$onDate, $otherDate]);

		$issues = $this->service('2026-01-01')->findRunIssues('2026-01-04');

		$this->assertCount(1, $issues);
		$this->assertSame(Task::SEVERITY_ACTION_REQUIRED, $issues[0]['severity']);
		$this->assertSame(5, $issues[0]['objectId']);
	}

	public function testRunIssuesZaehltFehlendeMandateFuerJedenTermin(): void {
		$this->setMandateActive(false);
		$this->assignments->method('findActiveAsOf')->willReturn([$this->assignment(1, 7)]);

		$issues = $this->service('2026-01-01')->findRunIssues('2026-03-01');

		$this->assertCount(1, $issues);
		$this->assertSame('assignment', $issues[0]['objectType']);
		$this->assertSame(Task::SEVERITY_ACTION_REQUIRED, $issues[0]['severity']);
	}

	public function testRunIssuesWeistAufManuelleForderungOhneMandatAlsHinweisHin(): void {
		$this->setMandateActive(false);
		// Manuelle Forderung (kein assignment_id) ohne Mandat: kommt nicht in den
		// Lauf, ist aber kein Handlungsbedarf (Spec §3.3).
		$manual = $this->claim(30, 7, '2026-01-10', null, 4200);
		$this->openItems->method('findClaimsDueOn')->with('2026-01-10')->willReturn([$manual]);

		$issues = $this->service('2026-01-01')->findRunIssues('2026-01-10');

		$this->assertCount(1, $issues);
		$this->assertSame(Task::SEVERITY_HINT, $issues[0]['severity']);
		$this->assertStringContainsString('42,00', $issues[0]['message']);
		$this->assertNull($issues[0]['objectId']);
	}

	public function testRunIssuesIgnoriertUeberweiserForderungenOhneMandat(): void {
		$this->setMandateActive(false);
		// Turnus-Forderung einer Ueberweiser-Zuweisung: "nie Stoerfall" (Spec §3.5).
		$transferClaim = $this->claim(31, 7, '2026-01-10', 1, 4200);
		$this->openItems->method('findClaimsDueOn')->willReturn([$transferClaim]);
		$this->assignments->method('find')->with(1)->willReturn($this->assignment(1, 7, Assignment::PAYMENT_METHOD_TRANSFER));

		$this->assertSame([], $this->service('2026-01-01')->findRunIssues('2026-01-10'));
	}
}
