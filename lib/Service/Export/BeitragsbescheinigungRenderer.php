<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Export;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Service\ClubClock;
use OCA\Vereinsbuchhaltung\Service\ClubCurrency;
use OCA\Vereinsbuchhaltung\Service\ContributionYearService;
use OCP\IAppConfig;
use OCP\IL10N;

/**
 * Die informelle Beitragsbestätigung eines Mitglieds als druckfertige HTML-
 * Seite (Spec §3.7, Issue #77, T16) – nach demselben Muster wie
 * {@see KassenberichtRenderer}: kein gespeichertes Dokument, keine
 * Sammellauf-Funktion, kein Zustellungs-Tracking. Reine Live-Ansicht,
 * jederzeit neu erzeugt aus dem aktuellen Datenbestand; denselben Inhalt gibt
 * es als PDF ({@see BeitragsbescheinigungPdfRenderer}, Issue #364).
 *
 * **Ausdrücklich KEINE amtliche Zuwendungsbestätigung nach §10b EStG** – das
 * bleibt Sache des separaten Upstream-Issues
 * https://github.com/AndiMb/nc_vereinsbuchhaltung/issues/10. Diese Klasse
 * weist das im gerenderten Text unübersehbar aus (siehe disclaimerSection()).
 *
 * **Zeitraum:** das Beitragsjahr aus {@see ContributionYearService}
 * (`fiscal_year_start_month`), NICHT das `Period`-Geschäftsjahr der
 * Kern-Buchhaltung – ein Verein kann beide unabhängig voneinander wählen.
 *
 * **Zuordnung nach Fälligkeitsperiode, nicht Zahlungsdatum** (Spec §3.7):
 * maßgeblich ist `period_start`/`period_end` der Forderung (siehe
 * {@see \OCA\Vereinsbuchhaltung\Service\ClaimGenerationService}, dort
 * ausdrücklich als „maßgeblich für Beitragsbescheinigungen" dokumentiert) –
 * ein Nachzügler, dessen `due_date` erst im Folgejahr liegt, zählt weiter
 * zum Jahr seiner ursprünglichen Periode. Nur bei manuellen Einzelforderungen
 * (Spec §2.2: `period_start`/`period_end` dort nullable) fällt die
 * Zuordnung auf `due_date` zurück, mangels einer Periode.
 *
 * Nur `type: beitrag` mit `status: paid` fließt ein – Gebühren-Forderungen
 * (`type: gebuehr`) NIE, und ein Erlass (`status: waived`) ist kein bezahlter
 * Beitrag.
 */
class BeitragsbescheinigungRenderer {

	public function __construct(
		private MemberMapper $members,
		private OpenItemMapper $openItems,
		private ContributionYearService $contributionYear,
		private IAppConfig $config,
		private IL10N $l10n,
		private ClubClock $clock,
		private ClubCurrency $currency,
	) {
	}

	/**
	 * Beitragsjahre mit mindestens einer bezahlten Beitrags-Forderung, plus
	 * das laufende Jahr – die Auswahlliste ist dadurch nie leer, auch wenn
	 * noch nichts bezahlt wurde.
	 *
	 * @return list<int> absteigend sortiert
	 * @throws \OCP\AppFramework\Db\DoesNotExistException wenn es das Mitglied nicht gibt
	 */
	public function selectableYears(int $memberId): array {
		$this->members->find($memberId); // wirft, wenn es das Mitglied nicht (mehr) gibt
		$years = [$this->contributionYear->currentAnchorYear()];
		foreach ($this->paidContributions($memberId) as $item) {
			$years[] = $this->contributionYear->anchorYearFor($this->referenceDate($item));
		}
		$years = array_values(array_unique($years));
		rsort($years);
		return $years;
	}

	/**
	 * Alles, was in der Beitragsbestätigung steht – für die HTML-Ansicht und das
	 * PDF ({@see BeitragsbescheinigungPdfRenderer}, Issue #364).
	 *
	 * @param int|null $anchorYear Anker-Kalenderjahr des Beitragsjahres (siehe
	 *                             {@see ContributionYearService::anchorYearFor()}), Standard: laufendes Jahr
	 * @return array{member: Member, anchorYear: int, label: string, clubName: string, heading: string, meta: string, rows: list<OpenItem>, sumCents: int}
	 * @throws \OCP\AppFramework\Db\DoesNotExistException wenn es das Mitglied nicht gibt
	 */
	public function collect(int $memberId, ?int $anchorYear = null): array {
		$member = $this->members->find($memberId);
		$anchorYear ??= $this->contributionYear->currentAnchorYear();
		$label = $this->contributionYear->yearLabel($anchorYear);
		[$from, $to] = $this->contributionYear->yearBounds($anchorYear);

		$rows = array_values(array_filter(
			$this->paidContributions($memberId),
			fn (OpenItem $item): bool => $this->contributionYear->anchorYearFor($this->referenceDate($item)) === $anchorYear,
		));
		usort($rows, static fn (OpenItem $a, OpenItem $b): int => strcmp(
			$a->getPeriodStart() ?? (string)$a->getDueDate(),
			$b->getPeriodStart() ?? (string)$b->getDueDate(),
		));

		return [
			'member' => $member,
			'anchorYear' => $anchorYear,
			'label' => $label,
			'clubName' => $this->config->getValueString(Application::APP_ID, 'club_name', ''),
			'heading' => $this->l10n->t('Beitragsbestätigung %s', [$label]),
			'meta' => $this->l10n->t('Beitragsjahr %s (%s–%s) · erstellt am %s', [
				$label,
				ReportFormat::date($from),
				ReportFormat::date($to),
				ReportFormat::date($this->clock->today()),
			]),
			'rows' => $rows,
			'sumCents' => array_sum(array_map(static fn (OpenItem $i): int => $i->getAmountCents(), $rows)),
		];
	}

	/**
	 * Rendert die Beitragsbestätigung für ein Beitragsjahr.
	 *
	 * @param int|null $anchorYear siehe {@see collect()}
	 * @throws \OCP\AppFramework\Db\DoesNotExistException wenn es das Mitglied nicht gibt
	 */
	public function render(int $memberId, ?int $anchorYear = null): string {
		$d = $this->collect($memberId, $anchorYear);
		$member = $d['member'];
		$title = ($d['clubName'] !== '' ? $d['clubName'] . ' – ' : '') . $d['heading'];

		$h = PrintableReportPage::printHint($this->l10n->t('Zum Drucken oder Als-PDF-Speichern: <strong>Strg+P</strong> (Mac: ⌘P) im Browser.'));
		if (!$member->hasAddress()) {
			// "Adresse jetzt hinterlegen" statt Admin-Aufgabe (Spec §3.7) - der
			// Hinweis blendet beim Drucken aus (.noprint), er richtet sich an die
			// Person vor dem Bildschirm (Mitglied oder Kassenwart), nicht an den
			// Empfänger der ausgedruckten Bestätigung.
			$h .= PrintableReportPage::printHint($this->l10n->t('Adresse jetzt hinterlegen: Für eine Bestätigung mit vollständiger Anschrift fehlen noch Straße, PLZ oder Ort.'));
		}
		$h .= PrintableReportPage::header(null, $d['clubName'], $d['heading'], PrintableReportPage::escape($d['meta']));
		$h .= $this->disclaimerSection();
		$h .= $this->recipientSection($member);
		$h .= $this->rowsSection($d['rows'], $d['sumCents']);

		return PrintableReportPage::document($title, $h);
	}

	/** Der Hinweis, dass dies keine Zuwendungsbestätigung ist. */
	public function disclaimer(): string {
		return $this->l10n->t(
			'Diese Bestätigung ist ein informeller Beleg über gezahlte Mitgliedsbeiträge. Sie ist KEINE amtliche Zuwendungsbestätigung nach § 10b EStG und hat keine steuerliche Wirkung.',
		);
	}

	/**
	 * Anschriftzeilen des Mitglieds: Name, Straße und Ort (falls hinterlegt), Mitgliedsnummer.
	 *
	 * @return list<string>
	 */
	public function recipientLines(Member $member): array {
		$lines = [$member->displayName()];
		if ($member->hasAddress()) {
			$lines[] = (string)$member->getStreet();
			$lines[] = trim($member->getPostalCode() . ' ' . $member->getCity());
		}
		if ($member->getMemberNumber() !== null && $member->getMemberNumber() !== '') {
			$lines[] = $this->l10n->t('Mitgliedsnummer %s', [(string)$member->getMemberNumber()]);
		}
		return $lines;
	}

	/**
	 * Bezahlte Beitrags-Forderungen eines Mitglieds, unabhängig vom
	 * Beitragsjahr – Gebühren (`type: gebuehr`), stornierte und lediglich
	 * erlassene (`status: waived`) Forderungen fließen nie ein.
	 *
	 * @return list<OpenItem>
	 */
	private function paidContributions(int $memberId): array {
		return array_values(array_filter(
			$this->openItems->findByMember($memberId),
			static fn (OpenItem $item): bool => $item->getType() === OpenItem::TYPE_CONTRIBUTION
				&& $item->getStatus() === 'paid'
				&& $item->getCancelledAt() === null,
		));
	}

	/**
	 * Das für die Jahreszuordnung maßgebliche Datum: die Fälligkeitsperiode
	 * (`period_start`), sonst (nur bei manuellen Einzelforderungen ohne
	 * Periode) `due_date`, sonst als letzter Rückfall das Anlagedatum.
	 */
	private function referenceDate(OpenItem $item): string {
		return $item->getPeriodStart() ?? $item->getDueDate() ?? ClubClock::localDate($item->getCreatedAt());
	}

	private function disclaimerSection(): string {
		return '<section><p>' . PrintableReportPage::escape($this->disclaimer()) . '</p></section>';
	}

	private function recipientSection(Member $member): string {
		$lines = $this->recipientLines($member);
		$h = '<section><p><strong>' . PrintableReportPage::escape(array_shift($lines)) . '</strong>';
		foreach ($lines as $line) {
			$h .= '<br>' . PrintableReportPage::escape($line);
		}
		return $h . '</p></section>';
	}

	/** @param list<OpenItem> $rows */
	private function rowsSection(array $rows, int $sumCents): string {
		if ($rows === []) {
			return '<section><p>' . $this->l10n->t('Keine bezahlten Beitrags-Forderungen in diesem Beitragsjahr.') . '</p></section>';
		}
		$h = '<section><h2>' . $this->l10n->t('Bezahlte Beiträge') . '</h2><table>';
		$h .= '<tr><th>' . $this->l10n->t('Fälligkeitsperiode') . '</th><th>' . $this->l10n->t('Beschreibung') . '</th><th class="num">' . $this->l10n->t('Betrag') . '</th></tr>';
		foreach ($rows as $item) {
			$h .= '<tr><td>' . PrintableReportPage::escape($this->periodLabel($item)) . '</td>'
				. '<td>' . PrintableReportPage::escape((string)($item->getDescription() ?? '')) . '</td>'
				. '<td class="num">' . ReportFormat::cents($item->getAmountCents(), $this->currency->symbol()) . '</td></tr>';
		}
		$h .= '<tr class="sum"><td colspan="2">' . $this->l10n->t('Summe') . '</td><td class="num">' . ReportFormat::cents($sumCents, $this->currency->symbol()) . '</td></tr>';
		return $h . '</table></section>';
	}

	/** Fälligkeitsperiode einer Zeile: der Periodenzeitraum, sonst (manuelle Forderung) das Fälligkeitsdatum allein. */
	public function periodLabel(OpenItem $item): string {
		if ($item->getPeriodStart() !== null && $item->getPeriodEnd() !== null) {
			return ReportFormat::date($item->getPeriodStart()) . '–' . ReportFormat::date($item->getPeriodEnd());
		}
		return ReportFormat::date($item->getDueDate());
	}
}
