<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Export;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Service\ContributionYearService;
use OCP\IConfig;
use OCP\IL10N;

/**
 * Die informelle Beitragsbestätigung eines Mitglieds als druckfertige HTML-
 * Seite (Spec §3.7, Issue #77, T16) – nach demselben Muster wie
 * {@see KassenberichtRenderer}: kein Server-PDF, kein gespeichertes
 * Dokument, keine Sammellauf-Funktion, kein Zustellungs-Tracking. Reine
 * Live-Ansicht, jederzeit neu erzeugt aus dem aktuellen Datenbestand.
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
		private IConfig $config,
		private IL10N $l10n,
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
	 * Rendert die Beitragsbestätigung für ein Beitragsjahr.
	 *
	 * @param int|null $anchorYear Anker-Kalenderjahr des Beitragsjahres (siehe
	 *                             {@see ContributionYearService::anchorYearFor()}), Standard: laufendes Jahr
	 * @throws \OCP\AppFramework\Db\DoesNotExistException wenn es das Mitglied nicht gibt
	 */
	public function render(int $memberId, ?int $anchorYear = null): string {
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
		$sumCents = array_sum(array_map(static fn (OpenItem $i): int => $i->getAmountCents(), $rows));

		$clubName = $this->config->getAppValue(Application::APP_ID, 'club_name', '');
		$heading = $this->l10n->t('Beitragsbestätigung %s', [$label]);
		$title = ($clubName !== '' ? $clubName . ' – ' : '') . $heading;
		$meta = $this->l10n->t('Beitragsjahr %s (%s–%s) · erstellt am %s', [
			$label,
			ReportFormat::date($from),
			ReportFormat::date($to),
			ReportFormat::date(date('Y-m-d')),
		]);

		$h = PrintableReportPage::printHint($this->l10n->t('Zum Drucken oder Als-PDF-Speichern: <strong>Strg+P</strong> (Mac: ⌘P) im Browser.'));
		if (!$member->hasAddress()) {
			// "Adresse jetzt hinterlegen" statt Admin-Aufgabe (Spec §3.7) - der
			// Hinweis blendet beim Drucken aus (.noprint), er richtet sich an die
			// Person vor dem Bildschirm (Mitglied oder Kassenwart), nicht an den
			// Empfänger der ausgedruckten Bestätigung.
			$h .= PrintableReportPage::printHint($this->l10n->t('Adresse jetzt hinterlegen: Für eine Bestätigung mit vollständiger Anschrift fehlen noch Straße, PLZ oder Ort.'));
		}
		$h .= PrintableReportPage::header(null, $clubName, $heading, PrintableReportPage::escape($meta));
		$h .= $this->disclaimerSection();
		$h .= $this->recipientSection($member);
		$h .= $this->rowsSection($rows, $sumCents);

		return PrintableReportPage::document($title, $h);
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
		return $item->getPeriodStart() ?? $item->getDueDate() ?? substr($item->getCreatedAt(), 0, 10);
	}

	private function disclaimerSection(): string {
		return '<section><p>' . PrintableReportPage::escape($this->l10n->t(
			'Diese Bestätigung ist ein informeller Beleg über gezahlte Mitgliedsbeiträge. Sie ist KEINE amtliche Zuwendungsbestätigung nach § 10b EStG (siehe Issue #10) und hat keine steuerliche Wirkung.',
		)) . '</p></section>';
	}

	private function recipientSection(Member $member): string {
		$h = '<section><p><strong>' . PrintableReportPage::escape($member->displayName()) . '</strong>';
		if ($member->hasAddress()) {
			$h .= '<br>' . PrintableReportPage::escape((string)$member->getStreet())
				. '<br>' . PrintableReportPage::escape(trim($member->getPostalCode() . ' ' . $member->getCity()));
		}
		if ($member->getMemberNumber() !== null && $member->getMemberNumber() !== '') {
			$h .= '<br>' . PrintableReportPage::escape($this->l10n->t('Mitgliedsnummer %s', [(string)$member->getMemberNumber()]));
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
				. '<td class="num">' . ReportFormat::cents($item->getAmountCents()) . '</td></tr>';
		}
		$h .= '<tr class="sum"><td colspan="2">' . $this->l10n->t('Summe') . '</td><td class="num">' . ReportFormat::cents($sumCents) . '</td></tr>';
		return $h . '</table></section>';
	}

	/** Fälligkeitsperiode einer Zeile: der Periodenzeitraum, sonst (manuelle Forderung) das Fälligkeitsdatum allein. */
	private function periodLabel(OpenItem $item): string {
		if ($item->getPeriodStart() !== null && $item->getPeriodEnd() !== null) {
			return ReportFormat::date($item->getPeriodStart()) . '–' . ReportFormat::date($item->getPeriodEnd());
		}
		return ReportFormat::date($item->getDueDate());
	}
}
