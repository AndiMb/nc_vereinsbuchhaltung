<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Invoice;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Db\InvoiceDocument;
use OCA\Vereinsbuchhaltung\Db\InvoiceMapper;
use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCA\Vereinsbuchhaltung\Service\ClubMailBranding;
use OCA\Vereinsbuchhaltung\Service\EmailValidator;
use OCA\Vereinsbuchhaltung\Service\InvoiceService;
use OCA\Vereinsbuchhaltung\Service\ClubClock;
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
 *
 * Der Testversand (#216) schickt dieselbe Mail an die eigene Adresse, ohne die
 * Rechnung anzufassen: kein „versendet", kein Protokolleintrag, kein abgelegtes
 * PDF. Er geht auch für Entwürfe – genau dort will man Absender, Logo und Text
 * prüfen, bevor etwas an Kunden hinausgeht.
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
		private ClubMailBranding $mailBranding,
		private ClubClock $clock,
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
			'fileName' => $invoice->getNumber() !== null ? $invoice->getNumber() . '.pdf' : $this->l10n->t('Rechnung-Entwurf') . '.pdf',
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
		[$subject, $body] = $this->subjectAndBody($invoiceId, $subject, $body);
		$snapshot = $invoice->debtorSnapshotData() ?? [];
		$this->deliver($invoice, $to, (string)($snapshot['name'] ?? ''), $subject, $body, $this->documents->pdfContent($invoiceId), $documentIds, $board);
		return $this->service->markSent($invoiceId);
	}

	/**
	 * Testversand: dieselbe Mail an die eigene Adresse, Betreff mit „[TEST]“.
	 * Ändert nichts an der Rechnung und legt kein PDF ab.
	 *
	 * @param string $to die Adresse des angemeldeten Nutzers (bestimmt der Controller, nicht der Browser)
	 * @param list<int> $documentIds wie bei send()
	 * @throws DoesNotExistException
	 * @throws \InvalidArgumentException bei stornierter Rechnung oder fehlender Adresse
	 * @throws \RuntimeException wenn der Mailversand scheitert
	 */
	public function sendTest(int $invoiceId, string $to, ?string $subject = null, ?string $body = null, array $documentIds = []): void {
		$invoice = $this->invoices->find($invoiceId);
		if ($invoice->getStatus() === Invoice::STATUS_CANCELLED) {
			throw new \InvalidArgumentException($this->l10n->t('Stornierte Rechnungen lassen sich nicht versenden.'));
		}
		$to = trim($to);
		if (!EmailValidator::isValid($to)) {
			throw new \InvalidArgumentException($this->l10n->t('Für den Testversand fehlt in Ihrem Nextcloud-Konto eine gültige E-Mail-Adresse.'));
		}
		[$subject, $body] = $this->subjectAndBody($invoiceId, $subject, $body);
		// Ohne Anzeigenamen: die Mail geht an die eigene Adresse, nicht an den Kunden.
		$this->deliver($invoice, $to, '', '[TEST] ' . $subject, $body, $this->documents->pdfContentForTest($invoiceId), $documentIds, null);
	}

	/**
	 * Betreff und Text: was im Dialog stand, sonst die Vorgaben.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function subjectAndBody(int $invoiceId, ?string $subject, ?string $body): array {
		$defaults = $this->defaults($invoiceId);
		return [
			$subject !== null && trim($subject) !== '' ? trim($subject) : $defaults['subject'],
			$body !== null && trim($body) !== '' ? trim($body) : $defaults['body'],
		];
	}

	/**
	 * Baut die Mail und verschickt sie; ohne Folgen für die Rechnung.
	 *
	 * @param array{content: string, fileName: string} $pdf
	 * @param list<int> $documentIds
	 * @throws UserFacingException wenn der Mailversand scheitert
	 */
	private function deliver(Invoice $invoice, string $to, string $recipientName, string $subject, string $body, array $pdf, array $documentIds, ?string $board): void {
		$invoiceId = (int)$invoice->getId();
		$number = $invoice->getNumber() ?? $this->l10n->t('Entwurf');

		$template = $this->mailer->createEMailTemplate('vereinsbuchhaltung.invoice');
		$template->setSubject($subject);
		$this->mailBranding->addHeader($template);
		$template->addHeading($invoice->isCredit() ? $this->l10n->t('Stornorechnung %s', [$number]) : $this->l10n->t('Rechnung %s', [$number]));
		foreach (preg_split('/\R{2,}/', $body) ?: [] as $paragraph) {
			$template->addBodyText(nl2br(htmlspecialchars(trim($paragraph), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')), trim($paragraph));
		}
		$this->mailBranding->addFooter($template, $this->l10n->getLanguageCode());

		$message = $this->mailer->createMessage();
		$this->mailBranding->apply($message);
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
			throw new UserFacingException($this->l10n->t('Die E-Mail konnte nicht versendet werden. Bitte prüfen Sie den Mailserver in den Nextcloud-Einstellungen.'), 0, $e);
		}
		if ($failed !== []) {
			throw new UserFacingException($this->l10n->t('Die E-Mail wurde vom Mailserver nicht angenommen.'));
		}
	}

	private function fill(string $text, Invoice $invoice): string {
		$snapshot = $invoice->debtorSnapshotData() ?? ($invoice->isIssued() ? null : $this->documents->debtorSnapshot($invoice)) ?? [];
		$issuer = $this->settings->issuer();
		$due = $this->dueDate($invoice);
		return strtr($text, [
			'{nummer}' => $invoice->getNumber() ?? $this->l10n->t('Entwurf'),
			'{betrag}' => number_format(abs($invoice->getGrossCents()) / 100, 2, ',', '.') . ' €',
			'{faellig}' => $due !== null ? $this->date($due) : '',
			'{verein}' => $issuer['name'],
			'{empfaenger}' => (string)($snapshot['name'] ?? ''),
		]);
	}

	/**
	 * Zahlungsziel; bei Entwürfen ohne eigenes das, was das Festschreiben heute
	 * setzen würde (Rechnungsdatum bzw. heute plus Standard-Zahlungsfrist).
	 */
	private function dueDate(Invoice $invoice): ?string {
		if ($invoice->getDueDate() !== null || $invoice->isIssued()) {
			return $invoice->getDueDate();
		}
		$days = $invoice->isCredit() ? 0 : $this->settings->defaultDueDays();
		return (new \DateTimeImmutable($invoice->getIssueDate() ?? $this->clock->today()))->modify('+' . $days . ' days')->format('Y-m-d');
	}

	private function date(string $iso): string {
		return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m) === 1 ? $m[3] . '.' . $m[2] . '.' . $m[1] : $iso;
	}
}
