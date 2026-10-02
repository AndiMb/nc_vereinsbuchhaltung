<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Service\ContributionYearService;
use OCA\Vereinsbuchhaltung\Service\Export\BeitragsbescheinigungRenderer;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Informelle Beitragsbestätigung (Spec §3.7, Issue #77): nur bezahlte
 * Beitrags-Forderungen fließen ein, Gebühren nie; die Zuordnung zum
 * Beitragsjahr folgt der Fälligkeitsperiode, nicht dem Zahlungsdatum
 * (Nachzügler bleiben beim alten Jahr).
 */
class BeitragsbescheinigungRendererTest extends TestCase {

	private MemberMapper&MockObject $memberMapper;
	private OpenItemMapper&MockObject $openItems;
	private IConfig&MockObject $config;
	private int $startMonth = 1;

	protected function setUp(): void {
		$this->memberMapper = $this->createMock(MemberMapper::class);
		$this->openItems = $this->createMock(OpenItemMapper::class);
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getAppValue')->willReturnCallback(
			fn (string $appId, string $key, string $default): string => match ($key) {
				'fiscal_year_start_month' => (string)$this->startMonth,
				'club_name' => 'Testverein e.V.',
				default => $default,
			},
		);
	}

	private function renderer(): BeitragsbescheinigungRenderer {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		return new BeitragsbescheinigungRenderer(
			$this->memberMapper,
			$this->openItems,
			new ContributionYearService($this->config),
			$this->config,
			$l10n,
		);
	}

	private function member(bool $withAddress = true): Member {
		$member = new Member();
		$member->setId(1);
		$member->setMemberType(Member::TYPE_PERSON);
		$member->setFirstName('Katrin');
		$member->setLastName('Brunner');
		if ($withAddress) {
			$member->setStreet('Musterstraße 1');
			$member->setPostalCode('12345');
			$member->setCity('Berlin');
		}
		return $member;
	}

	private function claim(
		string $type,
		string $status,
		int $amountCents,
		?string $periodStart = null,
		?string $periodEnd = null,
		?string $dueDate = null,
		?string $cancelledAt = null,
		?string $description = null,
	): OpenItem {
		$item = new OpenItem();
		$item->setId(random_int(1, 1000000));
		$item->setMemberId(1);
		$item->setType($type);
		$item->setStatus($status);
		$item->setAmountCents($amountCents);
		$item->setPeriodStart($periodStart);
		$item->setPeriodEnd($periodEnd);
		$item->setDueDate($dueDate);
		$item->setCancelledAt($cancelledAt);
		$item->setDescription($description);
		$item->setCreatedAt('2026-01-01T00:00:00+00:00');
		return $item;
	}

	public function testUnbekanntesMitgliedWirftDoesNotExistException(): void {
		$this->memberMapper->method('find')->willThrowException(new DoesNotExistException('weg'));
		$this->expectException(DoesNotExistException::class);
		$this->renderer()->render(999);
	}

	/**
	 * Kernanforderung des Akzeptanzkriteriums (Issue #77): eine gemischte
	 * Beitrags-/Gebühren-Lage zeigt nur die Beitragsposten, korrekt summiert.
	 */
	public function testNurBeitragsForderungenFliessenEinGebuehrenNie(): void {
		$this->memberMapper->method('find')->willReturn($this->member());
		$this->openItems->method('findByMember')->willReturn([
			$this->claim(OpenItem::TYPE_CONTRIBUTION, 'paid', 1500, '2026-01-01', '2026-03-31', description: 'Quartalsbeitrag'),
			$this->claim(OpenItem::TYPE_CONTRIBUTION, 'paid', 1500, '2026-04-01', '2026-06-30', description: 'Quartalsbeitrag'),
			$this->claim(OpenItem::TYPE_FEE, 'paid', 5000, '2026-02-01', '2026-02-01', description: 'Startgebühr'),
		]);

		$html = $this->renderer()->render(1, 2026);

		$this->assertStringContainsString('Quartalsbeitrag', $html);
		$this->assertStringNotContainsString('Startgebühr', $html);
		// Summe: nur die zwei Beitragsposten (15,00 € + 15,00 € = 30,00 €),
		// die 50,00 € Gebühr bleibt außen vor.
		$this->assertStringContainsString('30,00 €', $html);
		$this->assertStringNotContainsString('50,00 €', $html);
	}

	public function testErlasseneForderungZaehltNichtAlsBezahlt(): void {
		$this->memberMapper->method('find')->willReturn($this->member());
		$this->openItems->method('findByMember')->willReturn([
			$this->claim(OpenItem::TYPE_CONTRIBUTION, 'waived', 1500, '2026-01-01', '2026-03-31'),
		]);

		$html = $this->renderer()->render(1, 2026);

		$this->assertStringContainsString('Keine bezahlten Beitrags-Forderungen', $html);
	}

	public function testStorniertePaidForderungZaehltNicht(): void {
		// Theoretisch inkonsistenter Zustand (Storno setzt sonst status
		// 'cancelled'), die Renderer-Filterung ist trotzdem defensiv auf
		// cancelled_at geprüft.
		$this->memberMapper->method('find')->willReturn($this->member());
		$this->openItems->method('findByMember')->willReturn([
			$this->claim(OpenItem::TYPE_CONTRIBUTION, 'paid', 1500, '2026-01-01', '2026-03-31', cancelledAt: '2026-02-01T00:00:00+00:00'),
		]);

		$html = $this->renderer()->render(1, 2026);

		$this->assertStringContainsString('Keine bezahlten Beitrags-Forderungen', $html);
	}

	/**
	 * Nachzügler (Spec §3.7): die Periode zählt, nicht das Fälligkeitsdatum.
	 * Eine Ende-2025-Periode, deren Einzug erst 2026 stattfand, gehört zur
	 * Bestätigung 2025 - nicht zu 2026.
	 */
	public function testNachzueglerZaehltZumJahrDerPeriodeNichtDerFaelligkeit(): void {
		$this->memberMapper->method('find')->willReturn($this->member());
		$this->openItems->method('findByMember')->willReturn([
			$this->claim(OpenItem::TYPE_CONTRIBUTION, 'paid', 1000, '2025-10-01', '2025-12-31', dueDate: '2026-01-15', description: 'Q4-Beitrag'),
		]);

		$html2025 = $this->renderer()->render(1, 2025);
		$html2026 = $this->renderer()->render(1, 2026);

		$this->assertStringContainsString('Q4-Beitrag', $html2025);
		$this->assertStringNotContainsString('Q4-Beitrag', $html2026);
	}

	/** Manuelle Einzelforderungen (Spec §2.2) haben keine Periode - Rückfall auf das Fälligkeitsdatum. */
	public function testManuelleForderungOhnePeriodeFaelltAufFaelligkeitsdatumZurueck(): void {
		$this->memberMapper->method('find')->willReturn($this->member());
		$this->openItems->method('findByMember')->willReturn([
			$this->claim(OpenItem::TYPE_CONTRIBUTION, 'paid', 2000, periodStart: null, periodEnd: null, dueDate: '2026-03-01', description: 'Nachzahlung'),
		]);

		$html = $this->renderer()->render(1, 2026);

		$this->assertStringContainsString('Nachzahlung', $html);
		$this->assertStringContainsString('01.03.2026', $html);
	}

	public function testDisclaimerVerweistAufIssue10UndParagraf10bEstg(): void {
		$this->memberMapper->method('find')->willReturn($this->member());
		$this->openItems->method('findByMember')->willReturn([]);

		$html = $this->renderer()->render(1, 2026);

		$this->assertStringContainsString('§ 10b EStG', $html);
		$this->assertStringContainsString('#10', $html);
	}

	public function testFehlendeAdresseZeigtHinweisbanner(): void {
		$this->memberMapper->method('find')->willReturn($this->member(withAddress: false));
		$this->openItems->method('findByMember')->willReturn([]);

		$html = $this->renderer()->render(1, 2026);

		$this->assertStringContainsString('Adresse jetzt hinterlegen', $html);
	}

	public function testVorhandeneAdresseWirdGezeigtOhneBanner(): void {
		$this->memberMapper->method('find')->willReturn($this->member(withAddress: true));
		$this->openItems->method('findByMember')->willReturn([]);

		$html = $this->renderer()->render(1, 2026);

		$this->assertStringNotContainsString('Adresse jetzt hinterlegen', $html);
		$this->assertStringContainsString('Musterstraße 1', $html);
	}

	public function testOhneAngegebenesJahrGiltDasLaufendeBeitragsjahr(): void {
		$this->memberMapper->method('find')->willReturn($this->member());
		$this->openItems->method('findByMember')->willReturn([
			$this->claim(OpenItem::TYPE_CONTRIBUTION, 'paid', 1200, date('Y') . '-01-01', date('Y') . '-12-31', description: 'Jahresbeitrag'),
		]);

		$html = $this->renderer()->render(1);

		$this->assertStringContainsString('Jahresbeitrag', $html);
	}

	public function testSelectableYearsEnthaeltImmerDasLaufendeJahr(): void {
		$this->memberMapper->method('find')->willReturn($this->member());
		$this->openItems->method('findByMember')->willReturn([]);

		$years = $this->renderer()->selectableYears(1);

		$this->assertSame([(int)date('Y')], $years);
	}

	public function testSelectableYearsListetJahreMitBezahltenForderungenAbsteigend(): void {
		$this->memberMapper->method('find')->willReturn($this->member());
		$this->openItems->method('findByMember')->willReturn([
			$this->claim(OpenItem::TYPE_CONTRIBUTION, 'paid', 1000, '2023-01-01', '2023-12-31'),
			$this->claim(OpenItem::TYPE_CONTRIBUTION, 'paid', 1000, '2025-01-01', '2025-12-31'),
			$this->claim(OpenItem::TYPE_FEE, 'paid', 1000, '2024-01-01', '2024-12-31'), // Gebühr zählt nicht
		]);

		$years = $this->renderer()->selectableYears(1);

		// Absteigend sortiert, ohne Dopplung mit dem stets enthaltenen
		// laufenden Jahr, und ohne das Gebühren-only-Jahr 2024.
		$expected = array_values(array_unique([(int)date('Y'), 2025, 2023]));
		rsort($expected);
		$this->assertSame($expected, $years);
	}
}
