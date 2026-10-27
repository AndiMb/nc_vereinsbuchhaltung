<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Invoice;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Db\InvoiceDocument;
use OCA\Vereinsbuchhaltung\Db\InvoiceMapper;
use OCA\Vereinsbuchhaltung\Service\EmailValidator;
use OCA\Vereinsbuchhaltung\Service\InvoiceService;
use OCA\Vereinsbuchhaltung\Service\InvoiceSettings;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * Verschickt eine festgeschriebene Rechnung per E-Mail mit dem PDF im Anhang.
 *
 * Betreff und Text lassen sich in den Einstellungen vorgeben; Platzhalter in
 * geschweiften Klammern ({nummer}, {betrag}, {faellig}, {verein},
 * {empfaenger}) werden ersetzt. Beim Versand darf die Bearbeiterin beides noch
 * anpassen. Nach erfolgreichem Versand steht die Rechnung auf „versendet".
 */
class InvoiceMailService {

	public const DEFAULT_SUBJECT = 'Rechnung {nummer} von {verein}';
	public const DEFAULT_BODY = "Guten Tag {empfaenger},\n\nanbei erhalten Sie die Rechnung {nummer} über {betrag}. Bitte überweisen Sie den Betrag bis zum {faellig}.\n\nBei Fragen melden Sie sich gern.\n\nMit freundlichen Grüßen\n{verein}";
	public const DEFAULT_BODY_CREDIT = "Guten Tag {empfaenger},\n\nanbei erhalten Sie die Stornorechnung {nummer} über {betrag}.\n\nMit freundlichen Grüßen\n{verein}";

	public function __construct(
		private IMailer $mailer,
		private InvoiceMapper $invoices,
		private InvoiceService $service,
		private InvoiceDocumentService $documents,
		private InvoiceSettings $settings,
		private LoggerInterface $logger,
		private IL10N $l10n,
	) {
	}

	/**
	 * Vorgaben für den Versanddialog.
	 *
	 * @return array{to: string, subject: string, body: string, fileName: string, boardEmail: string, copyBoard: bool}
	 * @throws DoesNotExistException
	 */
	public function defaults(int $invoiceId): array {
		$invoice = $this->invoices->find($invoiceId);
		$all = $this->settings->all();
		$subject = trim((string)$all[InvoiceSettings::SETTING_MAIL_SUBJECT]) !== '' ? (string)$all[InvoiceSettings::SETTING_MAIL_SUBJECT] : $this->l10n->t(self::DEFAULT_SUBJECT);
		$bodyTemplate = trim((string)$all[InvoiceSettings::SETTING_MAIL_BODY]) !== ''
			? (string)$all[InvoiceSettings::SETTING_MAIL_BODY]
			: $this->l10n->t($invoice->isCredit() ? self::DEFAULT_BODY_CREDIT : self::DEFAULT_BODY);
		$snapshot = $invoice->debtorSnapshotData() ?? [];
		return [
			'to' => (string)($snapshot['email'] ?? ''),
			'subject' => $this->fill($subject, $invoice),
			'body' => $this->fill($bodyTemplate, $invoice),
			'fileName' => ($invoice->getNumber() ?? 'Rechnung') . '.pdf',
			'boardEmail' => (string)$this->settings->boardEmail(),
			// Die Kopie an den Vorstand ist beim Rechnungsversand eine Entscheidung je Mail.
			'copyBoard' => false,
		];
	}

	/**
	 * @throws DoesNotExistException
	 * @throws \InvalidArgumentException wenn die Rechnung nicht versendbar oder die Adresse ungültig ist
	 * @throws \RuntimeException wenn der Mailversand scheitert
	 */
	/**
	 * @param list<int> $documentIds E-Rechnungs-Dokumente, die mitgehen. Ein ZUGFeRD-PDF
	 *                               ersetzt das einfache PDF, XML-Dateien kommen als
	 *                               weitere Anhänge dazu.
	 * @param bool $copyBoard Blindkopie an die Vorstands-Adresse aus den Einstellungen
	 */
	public function send(int $invoiceId, string $to, ?string $subject = null, ?string $body = null, array $documentIds = [], bool $copyBoard = false): Invoice {
		$invoice = $this->invoices->find($invoiceId);
		if (!$invoice->isIssued() || $invoice->getStatus() === Invoice::STATUS_CANCELLED) {
			throw new \InvalidArgumentException($this->l10n->t('Nur festgeschriebene, nicht stornierte Rechnungen lassen sich versenden.'));
		}
		$to = trim($to);
		if (!EmailValidator::isValid($to)) {
			throw new \InvalidArgumentException($this->l10n->t('Bitte eine gültige E-Mail-Adresse angeben.'));
		}
		$board = $copyBoard ? $this->settings->boardEmail() : null;
		if ($copyBoard && $board === null) {
			throw new \InvalidArgumentException($this->l10n->t('Für die Kopie an den Vorstand fehlt die E-Mail-Adresse des Vorstands in den Einstellungen.'));
		}
		$defaults = $this->defaults($invoiceId);
		$subject = $subject !== null && trim($subject) !== '' ? trim($subject) : $defaults['subject'];
		$body = $body !== null && trim($body) !== '' ? trim($body) : $defaults['body'];

		$pdf = $this->documents->pdfContent($invoiceId);
		$snapshot = $invoice->debtorSnapshotData() ?? [];
		$recipientName = (string)($snapshot['name'] ?? '');

		$template = $this->mailer->createEMailTemplate('vereinsbuchhaltung.invoice');
		$template->setSubject($subject);
		$template->addHeader();
		$template->addHeading($invoice->isCredit() ? $this->l10n->t('Stornorechnung %s', [(string)$invoice->getNumber()]) : $this->l10n->t('Rechnung %s', [(string)$invoice->getNumber()]));
		foreach (preg_split('/\R{2,}/', $body) ?: [] as $paragraph) {
			$template->addBodyText(nl2br(htmlspecialchars(trim($paragraph), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')), trim($paragraph));
		}
		$template->addFooter('', $this->l10n->getLanguageCode());

		$message = $this->mailer->createMessage();
		$message->setTo($recipientName !== '' ? [$to => $recipientName] : [$to]);
		$message->setSubject($template->renderSubject());
		$message->useTemplate($template);
		if ($board !== null) {
			$message->setBcc([$board]);
		}
		$extra = [];
		foreach ($documentIds as $documentId) {
			$doc = $this->documents->documentContent($invoiceId, $documentId);
			if ($doc['kind'] === InvoiceDocument::KIND_ZUGFERD_PDF) {
				$pdf = ['content' => $doc['content'], 'fileName' => $doc['fileName']];
			} elseif ($doc['kind'] !== InvoiceDocument::KIND_PDF) {
				$extra[] = $doc;
			}
		}
		$message->attach($this->mailer->createAttachment($pdf['content'], $pdf['fileName'], 'application/pdf'));
		foreach ($extra as $doc) {
			$message->attach($this->mailer->createAttachment($doc['content'], $doc['fileName'], 'application/xml'));
		}

		try {
			$failed = $this->mailer->send($message);
		} catch (\Throwable $e) {
			$this->logger->warning('Rechnungsversand fehlgeschlagen: {message}', ['app' => Application::APP_ID, 'message' => $e->getMessage(), 'exception' => $e]);
			throw new \RuntimeException($this->l10n->t('Die E-Mail konnte nicht versendet werden. Bitte prüfen Sie den Mailserver in den Nextcloud-Einstellungen.'), 0, $e);
		}
		if ($failed !== []) {
			throw new \RuntimeException($this->l10n->t('Die E-Mail wurde vom Mailserver nicht angenommen.'));
		}
		return $this->service->markSent($invoiceId);
	}

	private function fill(string $text, Invoice $invoice): string {
		$snapshot = $invoice->debtorSnapshotData() ?? [];
		$issuer = $this->settings->issuer();
		return strtr($text, [
			'{nummer}' => (string)$invoice->getNumber(),
			'{betrag}' => number_format(abs($invoice->getGrossCents()) / 100, 2, ',', '.') . ' €',
			'{faellig}' => $invoice->getDueDate() !== null ? $this->date($invoice->getDueDate()) : '',
			'{verein}' => $issuer['name'],
			'{empfaenger}' => (string)($snapshot['name'] ?? ''),
		]);
	}

	private function date(string $iso): string {
		return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m) === 1 ? $m[3] . '.' . $m[2] . '.' . $m[1] : $iso;
	}
}
