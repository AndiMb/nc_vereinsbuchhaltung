<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\DonationReceipt;

use OCA\Vereinsbuchhaltung\Service\Export\ReportFormat;
use OCA\Vereinsbuchhaltung\Service\Pdf\PdfLogo;
use OCA\Vereinsbuchhaltung\Service\Pdf\ReportPdf;
use OCA\Vereinsbuchhaltung\Service\Pdf\ReportPdfFactory;

/**
 * Die Sammelbestätigung über Mitgliedsbeiträge als PDF (Issue #10), nach dem
 * amtlichen Muster „Sammelbestätigung über Geldzuwendungen/Mitgliedsbeiträge
 * an eine der in § 5 Abs. 1 Nr. 9 KStG bezeichneten Körperschaften“
 * (BMF-Schreiben vom 07.11.2013, BStBl I 2013, 1333).
 *
 * Das BMF verlangt Wortwahl und Reihenfolge des Musters (Nr. 2); übernommen
 * werden nur die im Einzelfall einschlägigen Passagen (Nr. 1), hier also die
 * Grundlage der Steuerbegünstigung, die der Verein eingestellt hat. Die Texte
 * sind deshalb deutsch und nicht übersetzt. Seite 1 ist die Bestätigung
 * (höchstens eine DIN-A4-Seite, Nr. 2), danach folgt die „Anlage zur
 * Sammelbestätigung“ mit jeder einzelnen Zahlung (Nr. 9).
 */
class DonationReceiptPdfRenderer {

	public const TITLE = 'Sammelbestätigung über Geldzuwendungen/Mitgliedsbeiträge';
	/** Ein deutsches Steuerdokument gibt es nur in Euro (die Einstellungen verlangen einen Euro-Verein). */
	private const CURRENCY = 'EUR'; // Zuwendungsbestätigung
	private const SUBTITLE = 'im Sinne des § 10b des Einkommensteuergesetzes an eine der in § 5 Abs. 1 Nr. 9 des Körperschaftsteuergesetzes bezeichneten Körperschaften, Personenvereinigungen oder Vermögensmassen';

	public function __construct(
		private ReportPdfFactory $factory,
	) {
	}

	/**
	 * @param array{number: string, issueDate: string, periodFrom: string, periodTo: string, amountCents: int,
	 *              donorLines: list<string>, donorLabel: string,
	 *              items: list<array{date: string, amountCents: int}>} $receipt
	 * @param array{basis: string, taxOffice: string, taxNumber: string, noticeDate: string, assessmentPeriod: string,
	 *              purposes: string, signatory: string, place: string, machineSigned: bool, issuer: array<string, string>} $club
	 * @param bool $compress false erzeugt lesbare Streams (nur für Tests)
	 * @return string PDF-Bytes
	 */
	public function render(array $receipt, array $club, bool $compress = true): string {
		$issuer = $club['issuer'];
		$pdf = $this->factory->create(self::TITLE . ' ' . $receipt['number'], 'Nr. ' . $receipt['number'], $compress);
		$doc = $pdf->document();

		// Aussteller, rechts daneben das Logo (Nr. 3: Briefpapier mit Logo ist zulässig).
		$pdf->boxes([[
			'label' => 'Aussteller (Bezeichnung und Anschrift der steuerbegünstigten Einrichtung)',
			'value' => implode("\n", array_values(array_filter([
				$issuer['name'],
				$issuer['street'],
				trim($issuer['postalCode'] . ' ' . $issuer['city']),
			], static fn (string $line): bool => $line !== ''))),
			'width' => 1.0,
		]], 20);
		$y = $doc->GetY();
		PdfLogo::place($doc, $this->factory->logo(), ReportPdf::MARGIN_RIGHT + 2, 22, 34, 14);
		$doc->SetXY(ReportPdf::MARGIN_LEFT, $y);

		$doc->SetFont(ReportPdf::FONT, 'B', 12, '', true);
		$doc->MultiCell(0, 6, self::TITLE, 0, 'C', false, 1, null, null, true, 0, false, true, 0, 'T');
		$doc->SetFont(ReportPdf::FONT, 'B', 8.5, '', true);
		$doc->MultiCell(0, 4, self::SUBTITLE, 0, 'C', false, 1, null, null, true, 0, false, true, 0, 'T');
		$doc->SetFont(ReportPdf::FONT, '', 7.5, '', true);
		$doc->MultiCell(0, 4, 'Nr. ' . $receipt['number'], 0, 'R', false, 1, null, null, true, 0, false, true, 0, 'T');
		$pdf->space(1);

		$pdf->boxes([[
			'label' => 'Name und Anschrift des Zuwendenden',
			'value' => implode("\n", $receipt['donorLines']),
			'width' => 1.0,
		]], 18);
		$pdf->boxes([
			['label' => 'Gesamtbetrag der Zuwendung – in Ziffern –', 'value' => ReportFormat::cents($receipt['amountCents'], self::CURRENCY), 'width' => 0.24],
			['label' => '– in Buchstaben –', 'value' => AmountInWords::euros($receipt['amountCents']), 'width' => 0.48],
			['label' => 'Zeitraum der Sammelbestätigung', 'value' => ReportFormat::date($receipt['periodFrom']) . ' – ' . ReportFormat::date($receipt['periodTo']), 'width' => 0.28],
		]);

		$pdf->paragraph($this->basisSentence($club));
		$pdf->paragraph('Es wird bestätigt, dass die Zuwendung nur zur Förderung ' . $club['purposes'] . ' verwendet wird.');
		$pdf->paragraph('Es wird bestätigt, dass über die in der Gesamtsumme enthaltenen Zuwendungen keine weiteren Bestätigungen, weder formelle Zuwendungsbestätigungen noch Beitragsquittungen oder Ähnliches ausgestellt wurden und werden.');
		$pdf->paragraph('Ob es sich um den Verzicht auf Erstattung von Aufwendungen handelt, ist der Anlage zur Sammelbestätigung zu entnehmen.');
		$pdf->space(2);

		$placeDate = trim(($club['place'] !== '' ? $club['place'] : $issuer['city']) . ', ' . ReportFormat::date($receipt['issueDate']), ', ');
		$pdf->boxes([
			['label' => 'Ort, Datum', 'value' => $placeDate, 'width' => 0.45],
			['label' => 'Unterschrift des Zuwendungsempfängers', 'value' => $club['machineSigned']
				? 'Maschinell erstellte Zuwendungsbestätigung ohne Unterschrift (R 10b.1 Abs. 4 EStR)'
				: "\n\n" . $club['signatory'], 'width' => 0.55],
		], 22);

		$pdf->paragraph('Hinweis:', true, true);
		$pdf->paragraph('Wer vorsätzlich oder grob fahrlässig eine unrichtige Zuwendungsbestätigung erstellt oder veranlasst, dass Zuwendungen nicht zu den in der Zuwendungsbestätigung angegebenen steuerbegünstigten Zwecken verwendet werden, haftet für die entgangene Steuer (§ 10b Abs. 4 EStG, § 9 Abs. 3 KStG, § 9 Nr. 5 GewStG).', true);
		$pdf->paragraph('Diese Bestätigung wird nicht als Nachweis für die steuerliche Berücksichtigung der Zuwendung anerkannt, wenn das Datum des Freistellungsbescheides länger als 5 Jahre bzw. das Datum der Feststellung der Einhaltung der satzungsmäßigen Voraussetzungen nach § 60a Abs. 1 AO länger als 3 Jahre seit Ausstellung des Bescheides zurückliegt (§ 63 Abs. 5 AO).', true);

		// Anlage (Nr. 9): jede einzelne Zahlung, Summe gleich der Bestätigung.
		$doc->AddPage();
		$pdf->heading('Anlage zur Sammelbestätigung vom ' . ReportFormat::date($receipt['issueDate']));
		$pdf->paragraph('Nr. ' . $receipt['number'] . ' · Zuwendender: ' . $receipt['donorLabel']);
		$rows = array_map(static fn (array $item): array => ['cells' => [
			ReportFormat::date($item['date']),
			'Mitgliedsbeitrag',
			'nein',
			ReportFormat::cents($item['amountCents'], self::CURRENCY),
		]], $receipt['items']);
		$rows[] = ['style' => ReportPdf::ROW_RESULT, 'span' => [3, 1], 'cells' => ['Gesamtsumme', ReportFormat::cents($receipt['amountCents'], self::CURRENCY)]];
		$pdf->table([
			['label' => 'Datum der Zuwendung', 'width' => 0.22],
			['label' => 'Art der Zuwendung', 'width' => 0.26],
			['label' => 'Verzicht auf die Erstattung von Aufwendungen', 'width' => 0.3],
			['label' => 'Betrag', 'width' => 0.22, 'align' => 'R'],
		], $rows);

		return $pdf->output();
	}

	/** Der Satz zur Steuerbegünstigung, je nach Grundlage (Freistellungsbescheid oder § 60a AO). */
	public function basisSentence(array $club): string {
		$office = $club['taxOffice'];
		$number = $club['taxNumber'];
		$date = ReportFormat::date($club['noticeDate']);
		if ($club['basis'] === DonationReceiptSettings::BASIS_ASSESSMENT) {
			return 'Die Einhaltung der satzungsmäßigen Voraussetzungen nach den §§ 51, 59, 60 und 61 AO wurde vom Finanzamt '
				. $office . ', StNr. ' . $number . ', mit Bescheid vom ' . $date
				. ' nach § 60a AO gesondert festgestellt. Wir fördern nach unserer Satzung ' . $club['purposes'] . '.';
		}
		return 'Wir sind wegen Förderung ' . $club['purposes']
			. ' nach dem Freistellungsbescheid bzw. nach der Anlage zum Körperschaftsteuerbescheid des Finanzamtes '
			. $office . ', StNr. ' . $number . ', vom ' . $date . ' für den letzten Veranlagungszeitraum ' . $club['assessmentPeriod']
			. ' nach § 5 Abs. 1 Nr. 9 des Körperschaftsteuergesetzes von der Körperschaftsteuer und nach § 3 Nr. 6 des Gewerbesteuergesetzes von der Gewerbesteuer befreit.';
	}
}
