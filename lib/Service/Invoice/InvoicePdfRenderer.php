<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Invoice;

use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Db\InvoiceLine;
use OCA\Vereinsbuchhaltung\Service\EpcQrCodeGenerator;
use OCA\Vereinsbuchhaltung\Service\InvoiceCalculator;
use OCP\IL10N;

/**
 * Setzt eine Rechnung als PDF (DIN 5008, Form B). Festgeschriebene Rechnungen
 * erzeugt der Renderer als PDF/A-3b mit eingebetteter Schrift, damit sie
 * archivierbar sind und sich in Stufe 2 als ZUGFeRD-Hybrid mit XML verbinden
 * lassen. Entwürfe bekommen ein Wasserzeichen und sind kein PDF/A.
 *
 * Das PDF enthält alle Pflichtangaben nach § 14 Abs. 4 UStG: Anschrift von
 * Verein und Empfänger, Steuernummer oder USt-IdNr., Rechnungsdatum und
 * -nummer, Leistungsdatum oder -zeitraum, Menge und Art der Leistung, Entgelt,
 * den Hinweis auf die Steuerbefreiung beziehungsweise die Kleinunternehmer-
 * regelung. Der GiroCode ist eine Vektorgrafik (kein Bild, keine Transparenz,
 * deshalb PDF/A-tauglich).
 *
 * Die Schrift ist DejaVu Sans aus dem TCPDF-Paket. PDF/A verlangt eingebettete
 * Schriften, die Standardschriften (Helvetica) scheiden deshalb aus.
 */
class InvoicePdfRenderer {

	private const FONT = 'dejavusans';
	private const MARGIN_LEFT = 25;
	private const MARGIN_RIGHT = 20;

	/** Einheitencode (UN/ECE Recommendation 20) => Kürzel im Dokument. */
	private const UNIT_LABELS = [
		'C62' => 'Stk.', 'HUR' => 'Std.', 'DAY' => 'Tag(e)', 'MON' => 'Monat(e)', 'ANN' => 'Jahr(e)',
		'LS' => 'pauschal', 'KMT' => 'km', 'MTR' => 'm', 'MTK' => 'm²', 'LTR' => 'l', 'KGM' => 'kg', 'P1' => '%',
	];

	public function __construct(
		private EpcQrCodeGenerator $epc,
		private ?IL10N $l10n = null,
	) {
	}

	/**
	 * @param bool $compress false erzeugt lesbare Streams (nur für Tests)
	 * @return string PDF-Bytes
	 * @throws \RuntimeException wenn die PDF-Bibliothek nicht verfügbar ist
	 */
	public function render(InvoicePdfData $data, bool $compress = true): string {
		if (!class_exists(\TCPDF::class)) {
			throw new \RuntimeException($this->msg('Die PDF-Bibliothek (TCPDF) ist nicht installiert.'));
		}
		$invoice = $data->invoice;
		$issuer = $data->issuer;

		$pdf = new InvoicePdfDocument('P', 'mm', 'A4', true, 'UTF-8', false, $data->preview ? false : 3);
		$pdf->setCompression($compress);
		$pdf->SetCreator('Vereinsbuchhaltung für Nextcloud');
		$pdf->SetAuthor((string)($issuer['name'] ?? ''));
		$pdf->SetTitle($this->title($invoice));
		$pdf->SetSubject($this->title($invoice));
		$pdf->SetMargins(self::MARGIN_LEFT, 20, self::MARGIN_RIGHT);
		$pdf->SetAutoPageBreak(true, 30);
		$pdf->setPrintHeader(false);
		$pdf->setPrintFooter(true);
		$pdf->setFooterMargin(10);
		$pdf->setFontSubsetting(true);
		$pdf->pageLabel = $this->msg('Seite {page} von {pages}');
		[$pdf->footerLeft, $pdf->footerMiddle, $pdf->footerRight] = $this->footer($data);

		$pdf->AddPage();
		if ($data->preview) {
			$this->watermark($pdf);
		}
		$this->letterhead($pdf, $data);
		$this->infoBlock($pdf, $data);

		$pdf->SetY(98);
		$pdf->SetFont(self::FONT, 'B', 14, '', true);
		$pdf->Cell(0, 7, $this->title($invoice) . ($invoice->getNumber() !== null ? ' ' . $invoice->getNumber() : ''), 0, 1, 'L');
		$pdf->Ln(2);
		$pdf->SetFont(self::FONT, '', 9, '', true);

		if ($data->precedingNumber !== null) {
			$this->paragraph($pdf, $this->msg('Diese Stornorechnung hebt die Rechnung %1$s vom %2$s auf.', [$data->precedingNumber, $this->date($data->precedingDate)]));
		}
		if ($invoice->getNoteHead() !== null && $data->precedingNumber === null) {
			$this->paragraph($pdf, (string)$invoice->getNoteHead());
		}
		$this->linesTable($pdf, $data);
		$this->totals($pdf, $data);
		$this->notices($pdf, $data);
		$this->payment($pdf, $data);
		if ($invoice->getNoteFoot() !== null && $data->precedingNumber === null) {
			$pdf->Ln(3);
			$this->paragraph($pdf, (string)$invoice->getNoteFoot());
		}

		return $pdf->Output('', 'S');
	}

	/**
	 * Das Schreiben einer Zahlungserinnerung oder Mahnung zu einer Rechnung: Anschrift,
	 * Text, die offene Rechnung als Zeile und der Zahlungsblock mit GiroCode über den Restbetrag.
	 *
	 * @param int $level 1 Zahlungserinnerung, 2 erste Mahnung, 3 zweite und letzte Mahnung
	 * @param string $body Text des Schreibens (Absätze durch Leerzeilen getrennt)
	 * @param int $remainingCents offener Betrag zum Zeitpunkt des Schreibens
	 * @param string $letterDate Datum des Schreibens (Y-m-d)
	 * @param bool $compress false erzeugt lesbare Streams (nur für Tests)
	 * @throws \RuntimeException wenn die PDF-Bibliothek nicht verfügbar ist
	 */
	public function renderDunning(InvoicePdfData $data, int $level, string $body, int $remainingCents, string $letterDate, bool $compress = true): string {
		if (!class_exists(\TCPDF::class)) {
			throw new \RuntimeException($this->msg('Die PDF-Bibliothek (TCPDF) ist nicht installiert.'));
		}
		$invoice = $data->invoice;
		$issuer = $data->issuer;
		$title = $this->dunningTitle($level);

		$pdf = new InvoicePdfDocument('P', 'mm', 'A4', true, 'UTF-8', false, 3);
		$pdf->setCompression($compress);
		$pdf->SetCreator('Vereinsbuchhaltung für Nextcloud');
		$pdf->SetAuthor((string)($issuer['name'] ?? ''));
		$pdf->SetTitle($title . ' ' . $invoice->getNumber());
		$pdf->SetSubject($title);
		$pdf->SetMargins(self::MARGIN_LEFT, 20, self::MARGIN_RIGHT);
		$pdf->SetAutoPageBreak(true, 30);
		$pdf->setPrintHeader(false);
		$pdf->setPrintFooter(true);
		$pdf->setFooterMargin(10);
		$pdf->setFontSubsetting(true);
		$pdf->pageLabel = $this->msg('Seite {page} von {pages}');
		[$pdf->footerLeft, $pdf->footerMiddle, $pdf->footerRight] = $this->footer($data);

		$pdf->AddPage();
		$this->letterhead($pdf, $data);

		$x = 125;
		$y = 45;
		$pdf->SetFont(self::FONT, '', 8.5, '', true);
		$rows = [[$this->msg('Datum'), $this->date($letterDate)], [$this->msg('Rechnungs-Nr.'), (string)$invoice->getNumber()]];
		$customerNo = (string)($data->debtor['customerNo'] ?? '');
		if ($customerNo !== '') {
			$rows[] = [$this->msg('Kundennummer'), $customerNo];
		}
		foreach ($rows as [$label, $value]) {
			$pdf->SetXY($x, $y);
			$pdf->SetTextColor(100, 100, 100);
			$pdf->Cell(28, 4.4, $label, 0, 0, 'L');
			$pdf->SetTextColor(0, 0, 0);
			$pdf->Cell(37, 4.4, $value, 0, 0, 'L');
			$y += 4.6;
		}

		$pdf->SetY(98);
		$pdf->SetFont(self::FONT, 'B', 14, '', true);
		$pdf->Cell(0, 7, $title . ' ' . $this->msg('zu Rechnung %s', [(string)$invoice->getNumber()]), 0, 1, 'L');
		$pdf->Ln(2);
		$pdf->SetFont(self::FONT, '', 9, '', true);
		foreach (preg_split('/\R{2,}/', trim($body)) ?: [] as $paragraph) {
			$this->paragraph($pdf, trim($paragraph));
		}

		// Die offene Rechnung als eine Zeile.
		$pdf->Ln(2);
		$pdf->SetFont(self::FONT, 'B', 8.5, '', true);
		$pdf->SetFillColor(240, 240, 240);
		$widths = [30, 24, 24, 30, 30, 32];
		$heads = [$this->msg('Rechnung'), $this->msg('Datum'), $this->msg('Fällig'), $this->msg('Betrag'), $this->msg('Bereits gezahlt'), $this->msg('Offen')];
		foreach ($heads as $i => $head) {
			$pdf->Cell($widths[$i], 6, $head, 0, 0, $i < 3 ? 'L' : 'R', true);
		}
		$pdf->Ln();
		$pdf->SetFont(self::FONT, '', 8.5, '', true);
		$cells = [
			(string)$invoice->getNumber(),
			$this->date($invoice->getIssueDate()),
			$this->date($invoice->getDueDate()),
			$this->money($invoice->getGrossCents()),
			$this->money(max(0, $invoice->getGrossCents() - $remainingCents)),
			$this->money($remainingCents),
		];
		foreach ($cells as $i => $cell) {
			$pdf->Cell($widths[$i], 6, $cell, 'B', 0, $i < 3 ? 'L' : 'R');
		}
		$pdf->Ln(8);

		$this->payment($pdf, $data, $remainingCents, $this->msg('Bitte überweisen Sie den offenen Betrag von %s auf das folgende Konto:', [$this->money($remainingCents)]));

		return $pdf->Output('', 'S');
	}

	public function dunningTitle(int $level): string {
		return match ($level) {
			1 => $this->msg('Zahlungserinnerung'),
			2 => $this->msg('1. Mahnung'),
			default => $this->msg('2. Mahnung'),
		};
	}

	// ------------------------------------------------------------------

	private function letterhead(InvoicePdfDocument $pdf, InvoicePdfData $data): void {
		$issuer = $data->issuer;
		$pdf->SetFont(self::FONT, 'B', 13, '', true);
		$pdf->SetXY(self::MARGIN_LEFT, 14);
		$pdf->Cell(95, 6, (string)($issuer['name'] ?? ''), 0, 1, 'L');
		$this->logo($pdf, $data->logo);

		// Absenderzeile über dem Anschriftenfeld (DIN 5008: im Fensterbriefumschlag sichtbar)
		$pdf->SetFont(self::FONT, '', 6.5, '', true);
		$pdf->SetXY(self::MARGIN_LEFT, 45);
		$sender = implode(' · ', array_filter([
			$issuer['name'] ?? '',
			$issuer['street'] ?? '',
			trim(($issuer['postalCode'] ?? '') . ' ' . ($issuer['city'] ?? '')),
		]));
		$pdf->Cell(85, 3.5, $sender, 'B', 1, 'L');

		$debtor = $data->debtor;
		$lines = array_filter([
			(string)($debtor['name'] ?? ''),
			(string)($debtor['name2'] ?? ''),
			(string)($debtor['street'] ?? ''),
			trim((string)($debtor['postalCode'] ?? '') . ' ' . (string)($debtor['city'] ?? '')),
		], static fn (string $l): bool => $l !== '');
		$country = strtoupper((string)($debtor['country'] ?? 'DE'));
		if ($country !== '' && $country !== 'DE') {
			$lines[] = $this->countryName($country);
		}
		$pdf->SetFont(self::FONT, '', 10, '', true);
		$pdf->SetXY(self::MARGIN_LEFT, 50);
		$pdf->MultiCell(85, 4.6, implode("\n", $lines), 0, 'L', false, 1, null, null, true, 0, false, true, 36, 'T');
	}

	/**
	 * Das Vereinslogo oben rechts, höchstens 55 mm breit und 24 mm hoch. Es wird über GD
	 * auf weißen Grund gebracht und als PNG ohne Transparenz eingebettet: so nimmt es auch
	 * PDF/A an, und WebP, das TCPDF nicht kennt, geht ebenfalls. Gelingt das nicht (kein GD,
	 * unlesbare Datei), bleibt der Briefkopf ohne Logo; die Rechnung scheitert deshalb nicht.
	 */
	private function logo(InvoicePdfDocument $pdf, ?string $bytes): void {
		if ($bytes === null || $bytes === '' || !function_exists('imagecreatefromstring')) {
			return;
		}
		$source = @imagecreatefromstring($bytes);
		if ($source === false) {
			return;
		}
		$w = imagesx($source);
		$h = imagesy($source);
		if ($w > 900) {
			$scaled = imagescale($source, 900);
			if ($scaled !== false) {
				$source = $scaled;
				$w = imagesx($source);
				$h = imagesy($source);
			}
		}
		$canvas = imagecreatetruecolor($w, $h);
		imagefill($canvas, 0, 0, (int)imagecolorallocate($canvas, 255, 255, 255));
		imagecopy($canvas, $source, 0, 0, 0, 0, $w, $h);
		ob_start();
		imagepng($canvas);
		$png = (string)ob_get_clean();
		if ($png === '') {
			return;
		}
		$scale = min(55 / $w, 24 / $h);
		$dw = $w * $scale;
		$dh = $h * $scale;
		$pdf->Image('@' . $png, $pdf->getPageWidth() - self::MARGIN_RIGHT - $dw, 12, $dw, $dh, 'PNG');
	}

	private function infoBlock(InvoicePdfDocument $pdf, InvoicePdfData $data): void {
		$invoice = $data->invoice;
		$rows = [];
		$rows[] = [$invoice->isCredit() ? $this->msg('Storno-Nr.') : $this->msg('Rechnungs-Nr.'), (string)($invoice->getNumber() ?? $this->msg('Entwurf'))];
		$rows[] = [$this->msg('Datum'), $this->date($invoice->getIssueDate() ?? date('Y-m-d'))];
		if ($invoice->getPeriodStart() !== null && $invoice->getPeriodEnd() !== null) {
			$rows[] = [$this->msg('Leistungszeitraum'), $this->date($invoice->getPeriodStart()) . ' – ' . $this->date($invoice->getPeriodEnd())];
		} elseif ($invoice->getDeliveryDate() !== null) {
			$rows[] = [$this->msg('Leistungsdatum'), $this->date($invoice->getDeliveryDate())];
		}
		$customerNo = (string)($data->debtor['customerNo'] ?? '');
		if ($customerNo !== '') {
			$rows[] = [$this->msg('Kundennummer'), $customerNo];
		}
		if ($invoice->getBuyerReference() !== null) {
			$rows[] = [$this->msg('Ihre Referenz'), (string)$invoice->getBuyerReference()];
		}
		if ($invoice->getOrderReference() !== null) {
			$rows[] = [$this->msg('Bestellnummer'), (string)$invoice->getOrderReference()];
		}
		if (!$invoice->isCredit() && $invoice->getDueDate() !== null) {
			$rows[] = [$this->msg('Zahlbar bis'), $this->date($invoice->getDueDate())];
		}
		$issuer = $data->issuer;
		$contact = array_filter([(string)($issuer['phone'] ?? ''), (string)($issuer['email'] ?? '')]);

		$x = 125;
		$y = 45;
		$pdf->SetFont(self::FONT, '', 8.5, '', true);
		foreach ($rows as [$label, $value]) {
			$pdf->SetXY($x, $y);
			$pdf->SetTextColor(100, 100, 100);
			$pdf->Cell(28, 4.4, $label, 0, 0, 'L');
			$pdf->SetTextColor(0, 0, 0);
			$pdf->Cell(37, 4.4, $value, 0, 0, 'L');
			$y += 4.6;
		}
		if ($contact !== []) {
			$y += 2;
			$pdf->SetFont(self::FONT, '', 7.5, '', true);
			$pdf->SetTextColor(100, 100, 100);
			foreach ($contact as $line) {
				$pdf->SetXY($x, $y);
				$pdf->Cell(65, 3.8, $line, 0, 0, 'L');
				$y += 3.8;
			}
			$pdf->SetTextColor(0, 0, 0);
		}
	}

	private function linesTable(InvoicePdfDocument $pdf, InvoicePdfData $data): void {
		$regular = $data->invoice->getTaxMode() === Invoice::TAX_REGULAR;
		// Spaltenbreiten in Prozent; bei Regelbesteuerung kommt die Spalte „USt" dazu.
		$w = $regular ? [8, 26, 13, 15, 11, 9, 18] : [8, 38, 14, 14, 9, 0, 17];
		$th = 'style="background-color:#eeeeee;font-weight:bold;"';
		$td = 'style="border-bottom:0.3px solid #cccccc;"';
		$html = '<table cellpadding="3" cellspacing="0" border="0" width="100%">'
			. '<tr nobr="true">'
			. '<td ' . $th . ' width="' . $w[0] . '%">' . $this->e($this->msg('Pos.')) . '</td>'
			. '<td ' . $th . ' width="' . $w[1] . '%">' . $this->e($this->msg('Bezeichnung')) . '</td>'
			. '<td ' . $th . ' width="' . $w[2] . '%" align="right">' . $this->e($this->msg('Menge')) . '</td>'
			. '<td ' . $th . ' width="' . $w[3] . '%" align="right">' . $this->e($this->msg('Einzelpreis')) . '</td>'
			. '<td ' . $th . ' width="' . $w[4] . '%" align="right">' . $this->e($this->msg('Rabatt')) . '</td>'
			. ($regular ? '<td ' . $th . ' width="' . $w[5] . '%" align="right">' . $this->e($this->msg('USt')) . '</td>' : '')
			. '<td ' . $th . ' width="' . $w[6] . '%" align="right">' . $this->e($this->msg('Gesamt')) . '</td>'
			. '</tr>';
		foreach ($data->lines as $line) {
			$name = '<b>' . $this->e($line->getName()) . '</b>';
			if ($line->getDescription() !== null && $line->getDescription() !== '') {
				$name .= '<br/><span style="font-size:7.5pt;color:#555555;">' . nl2br($this->e((string)$line->getDescription())) . '</span>';
			}
			$discount = $line->getDiscountBp() > 0 ? $this->percent($line->getDiscountBp()) : '';
			$html .= '<tr nobr="true">'
				. '<td ' . $td . ' width="' . $w[0] . '%">' . $line->getPosition() . '</td>'
				. '<td ' . $td . ' width="' . $w[1] . '%">' . $name . '</td>'
				. '<td ' . $td . ' width="' . $w[2] . '%" align="right">' . $this->e($this->quantity($line)) . '</td>'
				. '<td ' . $td . ' width="' . $w[3] . '%" align="right">' . $this->e($this->money($line->getUnitPriceCents())) . '</td>'
				. '<td ' . $td . ' width="' . $w[4] . '%" align="right">' . $this->e($discount) . '</td>'
				. ($regular ? '<td ' . $td . ' width="' . $w[5] . '%" align="right">' . $this->e($this->taxLabel($line)) . '</td>' : '')
				. '<td ' . $td . ' width="' . $w[6] . '%" align="right">' . $this->e($this->money($line->getLineNetCents())) . '</td>'
				. '</tr>';
		}
		$html .= '</table>';
		$pdf->writeHTML($html, true, false, true, false, '');
	}

	/** Steuersatz der Position für die Tabelle: „19 %", „7 %" oder „frei". */
	private function taxLabel(InvoiceLine $line): string {
		if ($line->getTaxCategory() === InvoiceLine::TAX_STANDARD) {
			return $this->percent($line->getTaxRateBp());
		}
		return $line->getTaxCategory() === InvoiceLine::TAX_ZERO ? '0 %' : $this->msg('frei');
	}

	private function totals(InvoicePdfDocument $pdf, InvoicePdfData $data): void {
		$invoice = $data->invoice;
		$pdf->Ln(2);
		$labelWidth = 62;
		$width = 28;
		$x = $pdf->getPageWidth() - self::MARGIN_RIGHT - $labelWidth - $width;
		$row = function (string $label, string $value, bool $bold, bool $line) use ($pdf, $x, $labelWidth, $width): void {
			$pdf->SetFont(self::FONT, $bold ? 'B' : '', $bold ? 10 : 9, '', true);
			$pdf->SetX($x);
			$pdf->Cell($labelWidth, $bold ? 6 : 5, $label, $line ? 'T' : 0, 0, 'L');
			$pdf->Cell($width, $bold ? 6 : 5, $value, $line ? 'T' : 0, 1, 'R');
		};
		if ($invoice->getTaxMode() === Invoice::TAX_REGULAR) {
			$totals = InvoiceCalculator::totals(array_map(static fn (InvoiceLine $l): array => [
				'lineNetCents' => $l->getLineNetCents(),
				'taxRateBp' => $l->getTaxRateBp(),
				'taxCategory' => $l->getTaxCategory(),
			], $data->lines), Invoice::TAX_REGULAR);
			$row($this->msg('Summe netto'), $this->money($totals['netCents']), false, true);
			foreach ($totals['taxBreakdown'] as $group) {
				if ($group['category'] === InvoiceLine::TAX_STANDARD) {
					$row($this->msg('zzgl. %1$s USt auf %2$s', [$this->percent($group['rateBp']), $this->money($group['baseCents'])]), $this->money($group['taxCents']), false, false);
				}
			}
		}
		$row($invoice->isCredit() ? $this->msg('Erstattungsbetrag') : $this->msg('Rechnungsbetrag'), $this->money($invoice->getGrossCents()), true, true);
		$pdf->SetFont(self::FONT, '', 9, '', true);
	}

	private function notices(InvoicePdfDocument $pdf, InvoicePdfData $data): void {
		$invoice = $data->invoice;
		$text = trim((string)$invoice->getExemptionText());
		if ($text !== '') {
			$pdf->Ln(2);
			$this->paragraph($pdf, $text);
		}
		if ($invoice->getPaymentTerms() !== null && !$invoice->isCredit()) {
			$this->paragraph($pdf, (string)$invoice->getPaymentTerms());
		}
		// Optionaler Hinweis auf die Gemeinnützigkeit; er ersetzt keinen Steuerhinweis.
		$nonprofit = trim((string)($data->issuer['nonprofitNote'] ?? ''));
		if ($nonprofit !== '') {
			$this->paragraph($pdf, $nonprofit);
		}
	}

	/**
	 * @param int|null $amountCents zu zahlender Betrag für den GiroCode; sonst der Rechnungsbetrag
	 * @param string|null $intro Einleitung des Blocks; sonst der Standardsatz zur Rechnung
	 */
	private function payment(InvoicePdfDocument $pdf, InvoicePdfData $data, ?int $amountCents = null, ?string $intro = null): void {
		$invoice = $data->invoice;
		if ($invoice->isCredit() || $data->bank === null || $data->bank['iban'] === '') {
			return;
		}
		$bank = $data->bank;
		// Der Zahlungsblock bleibt zusammen: lieber eine neue Seite als ein zerrissener Block.
		if ($pdf->GetY() > $pdf->getPageHeight() - 85) {
			$pdf->AddPage();
		}
		$pdf->Ln(4);
		$y = $pdf->GetY();
		$pdf->SetFont(self::FONT, 'B', 9, '', true);
		$pdf->Cell(110, 5, $this->msg('Zahlung'), 0, 1, 'L');
		$pdf->SetFont(self::FONT, '', 9, '', true);
		$due = $invoice->getDueDate() !== null ? $this->date($invoice->getDueDate()) : '';
		$pdf->MultiCell(84, 4.5, $intro ?? ($due !== ''
			? $this->msg('Bitte überweisen Sie den Rechnungsbetrag bis zum %s auf das folgende Konto:', [$due])
			: $this->msg('Bitte überweisen Sie den Rechnungsbetrag auf das folgende Konto:')), 0, 'L', false, 1);
		$rows = [
			[$this->msg('Kontoinhaber'), $bank['holder']],
			[$this->msg('IBAN'), $this->formatIban($bank['iban'])],
		];
		if ($bank['bic'] !== '') {
			$rows[] = [$this->msg('BIC'), $bank['bic']];
		}
		$rows[] = [$this->msg('Verwendungszweck'), (string)$invoice->getNumber()];
		if ($data->paymentReference !== null) {
			$rows[] = [$this->msg('Zahlungsreferenz'), CreditorReference::format($data->paymentReference)];
		}
		foreach ($rows as [$label, $value]) {
			$pdf->SetTextColor(100, 100, 100);
			$pdf->Cell(34, 4.8, $label, 0, 0, 'L');
			$pdf->SetTextColor(0, 0, 0);
			$pdf->Cell(50, 4.8, $value, 0, 1, 'L', false, '', 1);
		}

		// GiroCode als Kasten rechts neben dem Zahlungsblock: kurzer Text, Code daneben.
		$payload = $this->giroCodePayload($data, $amountCents);
		if ($payload !== null) {
			$boxW = 78;
			$boxH = 31;
			$qr = 24;
			$x = $pdf->getPageWidth() - self::MARGIN_RIGHT - $boxW;
			$pdf->SetDrawColor(205, 205, 205);
			$pdf->RoundedRect($x, $y, $boxW, $boxH, 2, '1111', 'D');
			$pdf->write2DBarcode($payload, 'QRCODE,M', $x + $boxW - $qr - 4, $y + ($boxH - $qr) / 2, $qr, $qr, ['border' => false, 'padding' => 0, 'fgcolor' => [0, 0, 0], 'bgcolor' => false], 'N');
			$textW = $boxW - $qr - 11;
			$pdf->SetXY($x + 4, $y + 4);
			$pdf->SetFont(self::FONT, 'B', 9, '', true);
			$pdf->MultiCell($textW, 4.4, $this->msg('Überweisen per Code'), 0, 'L', false, 1);
			$pdf->SetX($x + 4);
			$pdf->SetFont(self::FONT, '', 8.5, '', true);
			$pdf->MultiCell($textW, 4.2, $this->msg('Ganz bequem Code mit der Banking-App scannen.'), 0, 'L', false, 1);
			$pdf->SetFont(self::FONT, '', 9, '', true);
			$pdf->SetY(max($pdf->GetY(), $y + $boxH));
		}
	}

	private function giroCodePayload(InvoicePdfData $data, ?int $amountCents = null): ?string {
		$bank = $data->bank;
		$invoice = $data->invoice;
		$amount = $amountCents ?? $invoice->getGrossCents();
		if ($bank === null || $bank['iban'] === '' || $amount <= 0 || $invoice->getNumber() === null) {
			return null;
		}
		try {
			return $this->epc->buildPayload($bank['holder'], $bank['iban'], $bank['bic'] !== '' ? $bank['bic'] : null, $amount, (string)$invoice->getNumber(), $data->paymentReference);
		} catch (\InvalidArgumentException) {
			return null;
		}
	}

	/**
	 * @return array{0: list<string>, 1: list<string>, 2: list<string>}
	 */
	private function footer(InvoicePdfData $data): array {
		$issuer = $data->issuer;
		$left = array_values(array_filter([
			$issuer['name'] ?? '',
			$issuer['street'] ?? '',
			trim(($issuer['postalCode'] ?? '') . ' ' . ($issuer['city'] ?? '')),
		]));
		$middle = [];
		if (($issuer['taxNumber'] ?? '') !== '') {
			$middle[] = $this->msg('Steuernummer: %s', [$issuer['taxNumber']]);
		}
		if (($issuer['vatId'] ?? '') !== '') {
			$middle[] = $this->msg('USt-IdNr.: %s', [$issuer['vatId']]);
		}
		if (($issuer['registerCourt'] ?? '') !== '' || ($issuer['registerNo'] ?? '') !== '') {
			$middle[] = trim($this->msg('Vereinsregister: %s', [trim(($issuer['registerCourt'] ?? '') . ' ' . ($issuer['registerNo'] ?? ''))]));
		}
		if (($issuer['representatives'] ?? '') !== '') {
			$middle[] = $this->msg('Vertretungsberechtigt: %s', [$issuer['representatives']]);
		}
		$right = [];
		if ($data->bank !== null && $data->bank['iban'] !== '') {
			$right[] = $this->msg('Bankverbindung');
			$right[] = 'IBAN ' . $this->formatIban($data->bank['iban']);
			if ($data->bank['bic'] !== '') {
				$right[] = 'BIC ' . $data->bank['bic'];
			}
		}
		return [$left, $middle, $right];
	}

	private function watermark(InvoicePdfDocument $pdf): void {
		$pdf->SetFont(self::FONT, 'B', 70, '', true);
		$pdf->SetTextColor(235, 235, 235);
		$pdf->StartTransform();
		$pdf->Rotate(45, 105, 150);
		$pdf->Text(35, 150, $this->msg('ENTWURF'));
		$pdf->StopTransform();
		$pdf->SetTextColor(0, 0, 0);
	}

	private function paragraph(InvoicePdfDocument $pdf, string $text): void {
		$pdf->SetFont(self::FONT, '', 9, '', true);
		$pdf->MultiCell(0, 4.5, $text, 0, 'L', false, 1);
		$pdf->Ln(1.5);
	}

	private function title(Invoice $invoice): string {
		return $invoice->isCredit() ? $this->msg('Stornorechnung') : $this->msg('Rechnung');
	}

	private function quantity(InvoiceLine $line): string {
		$q = $line->getQuantityE4() / 10000;
		$text = rtrim(rtrim(number_format($q, 4, ',', '.'), '0'), ',');
		$unit = self::UNIT_LABELS[$line->getUnitCode()] ?? '';
		return trim($text . ' ' . ($unit !== '' ? $this->msg($unit) : ''));
	}

	private function percent(int $bp): string {
		return rtrim(rtrim(number_format($bp / 100, 2, ',', '.'), '0'), ',') . ' %';
	}

	private function money(int $cents): string {
		return number_format($cents / 100, 2, ',', '.') . ' €';
	}

	private function date(?string $iso): string {
		if ($iso === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m) !== 1) {
			return (string)$iso;
		}
		return $m[3] . '.' . $m[2] . '.' . $m[1];
	}

	private function formatIban(string $iban): string {
		return trim(chunk_split(strtoupper(str_replace(' ', '', $iban)), 4, ' '));
	}

	private function countryName(string $code): string {
		if (class_exists(\Locale::class)) {
			$name = \Locale::getDisplayRegion('-' . $code, 'de');
			if ($name !== '' && $name !== $code) {
				return mb_strtoupper($name);
			}
		}
		return $code;
	}

	private function e(string $text): string {
		return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	/** @param list<int|string> $params */
	private function msg(string $text, array $params = []): string {
		return $this->l10n !== null ? $this->l10n->t($text, $params) : vsprintf($text, $params);
	}
}
