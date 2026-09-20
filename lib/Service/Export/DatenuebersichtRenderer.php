<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Export;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\ContributionGroupMapper;
use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\ReturnedDebitMapper;
use OCA\Vereinsbuchhaltung\Service\ClaimStateResolver;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IL10N;

/**
 * Die „Datenübersicht" eines Mitglieds als druckfertige Live-Ansicht (Spec
 * §3.8, Issue #78, T30) – deckt die Auskunftspflicht nach Art. 15 DSGVO ab,
 * nach demselben Muster wie {@see KassenberichtRenderer}/
 * {@see BeitragsbescheinigungRenderer}: kein Server-PDF, kein gespeichertes
 * Dokument, jederzeit neu aus dem aktuellen Datenbestand erzeugt.
 *
 * **Ausdrücklich KEIN strukturierter Export nach Art. 20 DSGVO** – reine
 * Lese-/Druckansicht, keine maschinenlesbare Datei (Spec §3.8: „kein
 * strukturierter Export in v1").
 *
 * Zugang: {@see \OCA\Vereinsbuchhaltung\Controller\ExportController::datenuebersicht()}
 * für `buchhalter` über die Admin-Akte, {@see \OCA\Vereinsbuchhaltung\Controller\SelfController::dataOverview()}
 * im Self-Service unter „Meine Daten" – dieselbe Renderer-Instanz, nur der
 * Zugriffspfad unterscheidet sich (siehe dortige Klassendocs zum
 * IDOR-Schutz über {@see \OCA\Vereinsbuchhaltung\Service\ActorContextService}).
 *
 * Zeigt bewusst auch nach einer DSGVO-Anonymisierung ({@see Member::isRedacted()})
 * weiterhin alle Abschnitte an – nur eben mit den bereits geschwärzten
 * Werten (Platzhalter-Name, keine IBAN, keine Freitexte). Genau das macht
 * die Anonymisierung für Auftraggeber und Mitglied nachprüfbar: „hier ist,
 * was noch übrig ist".
 */
class DatenuebersichtRenderer {

	public function __construct(
		private MemberMapper $members,
		private MandateMapper $mandates,
		private DebitItemMapper $debitItems,
		private ReturnedDebitMapper $returnedDebits,
		private OpenItemMapper $openItems,
		private AssignmentMapper $assignments,
		private ContributionGroupMapper $groups,
		private IConfig $config,
		private IL10N $l10n,
	) {
	}

	/** @throws DoesNotExistException wenn es das Mitglied nicht gibt */
	public function render(int $memberId): string {
		$member = $this->members->find($memberId);

		$clubName = $this->config->getAppValue(Application::APP_ID, 'club_name', '');
		$heading = $this->l10n->t('Datenübersicht');
		$title = ($clubName !== '' ? $clubName . ' – ' : '') . $heading;
		$meta = $this->l10n->t('Auskunft nach Art. 15 DSGVO (kein strukturierter Export nach Art. 20) · erstellt am %s', [ReportFormat::date(date('Y-m-d'))]);

		$h = PrintableReportPage::printHint($this->l10n->t('Zum Drucken oder Als-PDF-Speichern: <strong>Strg+P</strong> (Mac: ⌘P) im Browser.'));
		$h .= PrintableReportPage::header(null, $clubName, $heading, PrintableReportPage::escape($meta));
		$h .= $this->memberSection($member);
		$h .= $this->mandatesSection($memberId);
		$h .= $this->claimsSection($memberId);
		$h .= $this->assignmentsSection($memberId);
		return PrintableReportPage::document($title, $h);
	}

	private function row(string $label, string $value): string {
		return '<tr><th>' . PrintableReportPage::escape($label) . '</th><td>' . PrintableReportPage::escape($value) . '</td></tr>';
	}

	private function memberSection(Member $member): string {
		$h = '<section><h2>' . $this->l10n->t('Stammdaten') . '</h2>';
		if ($member->isRedacted()) {
			$h .= '<p>' . PrintableReportPage::escape($this->l10n->t(
				'Diese Mitgliedsakte wurde am %s im Rahmen der gesetzlich vorgesehenen DSGVO-Anonymisierung geschwärzt. Name, Kontaktdaten, Bankverbindungen und personenbezogene Freitexte sind unwiderruflich entfernt; nur strukturierte Angaben (Beträge, Daten, Status) bleiben aus buchhalterischen Gründen erhalten.',
				[ReportFormat::date(substr((string)$member->getRedactedAt(), 0, 10))],
			)) . '</p>';
		}
		$h .= '<table>';
		$h .= $this->row($this->l10n->t('Anzeigename'), $member->displayName());
		if (!$member->isRedacted()) {
			if ($member->getEmail() !== null && $member->getEmail() !== '') {
				$h .= $this->row($this->l10n->t('E-Mail'), (string)$member->getEmail());
			}
			if ($member->getPhone() !== null && $member->getPhone() !== '') {
				$h .= $this->row($this->l10n->t('Telefon'), (string)$member->getPhone());
			}
			if ($member->hasAddress()) {
				$h .= $this->row($this->l10n->t('Adresse'), $member->getStreet() . ', ' . trim($member->getPostalCode() . ' ' . $member->getCity()));
			}
		}
		if ($member->getMemberNumber() !== null && $member->getMemberNumber() !== '') {
			$h .= $this->row($this->l10n->t('Mitgliedsnummer'), (string)$member->getMemberNumber());
		}
		$h .= $this->row($this->l10n->t('Mitglied seit'), ReportFormat::date($member->getJoinedAt()));
		if ($member->getLeftAt() !== null) {
			$h .= $this->row($this->l10n->t('Austritt zum'), ReportFormat::date($member->getLeftAt()));
		}
		return $h . '</table></section>';
	}

	/** @return list<Mandate> */
	private function mandatesOf(int $memberId): array {
		return $this->mandates->findByMember($memberId);
	}

	private function mandatesSection(int $memberId): string {
		$mandates = $this->mandatesOf($memberId);
		$h = '<section><h2>' . $this->l10n->t('SEPA-Lastschriftmandate') . '</h2>';
		if ($mandates === []) {
			return $h . '<p>' . $this->l10n->t('Kein Mandat hinterlegt.') . '</p></section>';
		}
		$h .= '<table><tr><th>' . $this->l10n->t('Referenz') . '</th><th>' . $this->l10n->t('IBAN') . '</th>'
			. '<th>' . $this->l10n->t('Kontoinhaber') . '</th><th>' . $this->l10n->t('Status') . '</th>'
			. '<th>' . $this->l10n->t('Aktiviert am') . '</th><th>' . $this->l10n->t('Beendet am') . '</th></tr>';
		foreach ($mandates as $mandate) {
			$h .= '<tr>'
				. '<td>' . PrintableReportPage::escape($mandate->getMandateReference()) . '</td>'
				. '<td>' . PrintableReportPage::escape((string)($mandate->maskedIban() ?? '–')) . '</td>'
				. '<td>' . PrintableReportPage::escape($mandate->getAccountHolder()) . '</td>'
				. '<td>' . PrintableReportPage::escape($mandate->getStatus()) . '</td>'
				. '<td>' . ReportFormat::date($mandate->getActivatedAt()) . '</td>'
				. '<td>' . ReportFormat::date($mandate->getEndedAt()) . '</td>'
				. '</tr>';
		}
		$h .= '</table>';
		$h .= $this->returnedDebitsSection($mandates);
		return $h . '</section>';
	}

	/** @param list<Mandate> $mandates */
	private function returnedDebitsSection(array $mandates): string {
		$rows = [];
		foreach ($mandates as $mandate) {
			foreach ($this->debitItems->findByMandate((int)$mandate->getId()) as $debitItem) {
				$returnedDebit = $this->returnedDebits->findByDebitItem((int)$debitItem->getId());
				if ($returnedDebit !== null) {
					$rows[] = $returnedDebit;
				}
			}
		}
		if ($rows === []) {
			return '';
		}
		$h = '<h3>' . $this->l10n->t('Rücklastschriften') . '</h3><table><tr><th>' . $this->l10n->t('Eingegangen am')
			. '</th><th>' . $this->l10n->t('Grund') . '</th><th class="num">' . $this->l10n->t('Gebühr') . '</th></tr>';
		foreach ($rows as $returnedDebit) {
			$h .= '<tr><td>' . ReportFormat::date(substr($returnedDebit->getReceivedAt(), 0, 10)) . '</td>'
				. '<td>' . PrintableReportPage::escape((string)($returnedDebit->getReasonCode() ?? '–')) . '</td>'
				. '<td class="num">' . ($returnedDebit->getChargesCents() !== null ? ReportFormat::cents($returnedDebit->getChargesCents()) : '–') . '</td></tr>';
		}
		return $h . '</table>';
	}

	private function claimsSection(int $memberId): string {
		$items = array_values(array_filter(
			$this->openItems->findByMember($memberId),
			static fn (OpenItem $item): bool => $item->isClaim(),
		));
		usort($items, static fn (OpenItem $a, OpenItem $b): int => strcmp(
			$a->getPeriodStart() ?? (string)$a->getDueDate(),
			$b->getPeriodStart() ?? (string)$b->getDueDate(),
		));
		$h = '<section><h2>' . $this->l10n->t('Forderungen') . '</h2>';
		if ($items === []) {
			return $h . '<p>' . $this->l10n->t('Keine Forderungen vorhanden.') . '</p></section>';
		}
		$h .= '<table><tr><th>' . $this->l10n->t('Zeitraum/Fälligkeit') . '</th><th>' . $this->l10n->t('Beschreibung')
			. '</th><th class="num">' . $this->l10n->t('Betrag') . '</th><th>' . $this->l10n->t('Zustand') . '</th></tr>';
		foreach ($items as $item) {
			$period = $item->getPeriodStart() !== null && $item->getPeriodEnd() !== null
				? ReportFormat::date($item->getPeriodStart()) . '–' . ReportFormat::date($item->getPeriodEnd())
				: ReportFormat::date($item->getDueDate());
			$h .= '<tr>'
				. '<td>' . PrintableReportPage::escape($period) . '</td>'
				. '<td>' . PrintableReportPage::escape((string)($item->getDescription() ?? '')) . '</td>'
				. '<td class="num">' . ReportFormat::cents($item->getAmountCents()) . '</td>'
				. '<td>' . PrintableReportPage::escape(ClaimStateResolver::resolveForItem($item)) . '</td>'
				. '</tr>';
		}
		return $h . '</table></section>';
	}

	private function assignmentsSection(int $memberId): string {
		$assignments = $this->assignments->findByMember($memberId);
		$h = '<section><h2>' . $this->l10n->t('Beitragszuweisungen') . '</h2>';
		if ($assignments === []) {
			return $h . '<p>' . $this->l10n->t('Keine Beitragszuweisung vorhanden.') . '</p></section>';
		}
		$h .= '<table><tr><th>' . $this->l10n->t('Beitragsgruppe') . '</th><th>' . $this->l10n->t('Turnus')
			. '</th><th class="num">' . $this->l10n->t('Monatsbetrag') . '</th><th>' . $this->l10n->t('Zahlungsart')
			. '</th><th>' . $this->l10n->t('Gültig von') . '</th><th>' . $this->l10n->t('Gültig bis') . '</th></tr>';
		foreach ($assignments as $assignment) {
			$h .= '<tr>'
				. '<td>' . PrintableReportPage::escape($this->groupName($assignment)) . '</td>'
				. '<td>' . $assignment->getIntervalMonths() . '</td>'
				. '<td class="num">' . ReportFormat::cents($assignment->getMonthlyAmountCents()) . '</td>'
				. '<td>' . PrintableReportPage::escape($assignment->getPaymentMethod()) . '</td>'
				. '<td>' . ReportFormat::date($assignment->getValidFrom()) . '</td>'
				. '<td>' . ReportFormat::date($assignment->getValidTo()) . '</td>'
				. '</tr>';
		}
		return $h . '</table></section>';
	}

	private function groupName(Assignment $assignment): string {
		try {
			return $this->groups->find($assignment->getGroupId())->getName();
		} catch (DoesNotExistException) {
			return $this->l10n->t('(gelöschte Beitragsgruppe)');
		}
	}
}
