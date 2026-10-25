<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Export;

use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Db\InvoiceDocumentMapper;
use OCA\Vereinsbuchhaltung\Db\InvoiceDunningMapper;
use OCA\Vereinsbuchhaltung\Db\InvoiceMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Service\CsvFormatter;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoiceDocumentStorage;
use OCA\Vereinsbuchhaltung\Service\InvoiceSettings;
use OCA\Vereinsbuchhaltung\Service\SafeFileName;
use OCP\IL10N;

/**
 * Die Rechnungen eines Geschäftsjahres für die Kassenprüfung (Epic #168, Stufe 3):
 * im Ordner `rechnungen/` des Beleg-ZIPs je Rechnung ein Unterordner mit allen Dokumenten
 * (PDF, ZUGFeRD-PDF, XRechnung-XML, Mahnschreiben), dazu eine Übersicht als CSV mit
 * Zahlungsstand und eine Prüfsummenliste. Letztere zeigt, ob eine Datei seit dem
 * Erzeugen verändert wurde. Eine fehlende oder nicht lesbare Datei bricht den Export
 * nicht ab, sondern steht als Befund in der Prüfsummenliste.
 */
class InvoiceArchive {

	public const FOLDER = 'rechnungen';

	public function __construct(
		private InvoiceMapper $invoices,
		private InvoiceDocumentMapper $documents,
		private InvoiceDocumentStorage $storage,
		private OpenItemMapper $openItems,
		private InvoiceDunningMapper $dunnings,
		private InvoiceSettings $settings,
		private IL10N $l10n,
	) {
	}

	/**
	 * Fügt die Rechnungen des Zeitraums dem Archiv hinzu.
	 *
	 * @param string|null $from Beginn des Zeitraums (Y-m-d); ohne Angabe alle Rechnungen
	 * @param string|null $to Ende des Zeitraums (Y-m-d)
	 * @return int Anzahl der Rechnungen
	 */
	public function addTo(\ZipArchive $zip, ?string $from, ?string $to): int {
		if (!$this->settings->isActive()) {
			return 0;
		}
		$invoices = $this->invoices->findIssuedBetween($from, $to);
		if ($invoices === []) {
			return 0;
		}
		$ids = array_map(static fn (Invoice $i): int => (int)$i->getId(), $invoices);
		$items = $this->openItems->findByIds(array_values(array_filter(array_map(static fn (Invoice $i) => $i->getOpenItemId(), $invoices))));
		$lastDunning = $this->dunnings->lastByInvoices($ids);
		$docsByInvoice = [];
		foreach ($this->documents->findAll() as $doc) {
			$docsByInvoice[$doc->getInvoiceId()][] = $doc;
		}

		$overview = CsvFormatter::line([
			$this->l10n->t('Nummer'), $this->l10n->t('Art'), $this->l10n->t('Rechnungsdatum'), $this->l10n->t('Fällig'),
			$this->l10n->t('Empfänger'), $this->l10n->t('Netto'), $this->l10n->t('Steuer'), $this->l10n->t('Brutto'),
			$this->l10n->t('Bezahlt'), $this->l10n->t('Offen'), $this->l10n->t('Status'), $this->l10n->t('Mahnstufe'),
			$this->l10n->t('Dokumente'),
		]);
		$checksums = [];

		foreach ($invoices as $invoice) {
			$id = (int)$invoice->getId();
			$item = $items[$invoice->getOpenItemId() ?? 0] ?? null;
			$settled = $item?->getSettledCents() ?? 0;
			$remaining = $invoice->getStatus() === Invoice::STATUS_CANCELLED ? 0 : ($item?->remainingCents() ?? 0);
			$folder = self::FOLDER . '/' . SafeFileName::of((string)$invoice->getNumber(), 60);
			$names = [];
			foreach ($docsByInvoice[$id] ?? [] as $doc) {
				$content = $this->storage->readOrNull($doc);
				$path = $folder . '/' . SafeFileName::of($doc->getFileName(), 100);
				if ($content === null) {
					$checksums[] = sprintf('%s  %s  missing', $doc->getSha256(), $path);
					continue;
				}
				$zip->addFromString($path, $content);
				$names[] = basename($path);
				$state = hash_equals($doc->getSha256(), hash('sha256', $content)) ? 'ok' : 'changed';
				$checksums[] = sprintf('%s  %s  %s', $doc->getSha256(), $path, $state);
			}
			$overview .= CsvFormatter::line([
				(string)$invoice->getNumber(),
				$invoice->isCredit() ? $this->l10n->t('Stornorechnung') : $this->l10n->t('Rechnung'),
				(string)$invoice->getIssueDate(),
				(string)($invoice->getDueDate() ?? ''),
				(string)(($invoice->debtorSnapshotData() ?? [])['name'] ?? ''),
				self::money($invoice->getNetCents()),
				self::money($invoice->getTaxCents()),
				self::money($invoice->getGrossCents()),
				$invoice->isCredit() ? '' : self::money($settled),
				$invoice->isCredit() ? '' : self::money($remaining),
				$this->statusLabel($invoice),
				(string)($lastDunning[$id]['level'] ?? 0),
				implode(', ', $names),
			]);
		}

		$zip->addFromString(self::FOLDER . '/uebersicht.csv', "\xEF\xBB\xBF" . $overview);
		$zip->addFromString(self::FOLDER . '/pruefsummen.txt', implode("\n", $checksums) . "\n");
		return count($invoices);
	}

	private function statusLabel(Invoice $invoice): string {
		if ($invoice->isCredit()) {
			return $this->l10n->t('Stornorechnung');
		}
		return match ($invoice->getStatus()) {
			Invoice::STATUS_PAID => $this->l10n->t('Bezahlt'),
			Invoice::STATUS_CANCELLED => $this->l10n->t('Storniert'),
			Invoice::STATUS_SENT => $this->l10n->t('Versendet'),
			default => $this->l10n->t('Festgeschrieben'),
		};
	}

	private static function money(int $cents): string {
		return number_format($cents / 100, 2, ',', '');
	}
}
