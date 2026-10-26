<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Invoice;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Db\InvoiceDunning;
use OCA\Vereinsbuchhaltung\Db\InvoiceDunningMapper;
use OCA\Vereinsbuchhaltung\Db\InvoiceMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\EmailValidator;
use OCA\Vereinsbuchhaltung\Service\InvoiceSettings;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * Zahlungserinnerung und Mahnungen zu Rechnungen (Epic #168, Stufe 3).
 *
 * Drei Stufen: Zahlungserinnerung, erste und zweite Mahnung. Jede Mahnung ist ein
 * PDF mit GiroCode über den Restbetrag, das als Dokument der Rechnung abgelegt
 * und per Mail verschickt wird. Fristen und Texte stehen in den Einstellungen.
 *
 * Der Verwalter mahnt von Hand aus der Rechnung. Wer will, lässt den täglichen
 * Hintergrundjob fällige Stufen selbst verschicken ({@see runDaily()}); das ist
 * ausgeschaltet, bis der Verein es einschaltet. Bezahlte, stornierte und
 * noch nicht fällige Rechnungen werden nie gemahnt.
 */
class InvoiceDunningService {

	public const DEFAULT_SUBJECT = '{stufe} zu Rechnung {nummer} von {verein}';

	/** Die Standardtexte je Stufe, mit Platzhaltern. */
	public const DEFAULT_BODIES = [
		1 => "Guten Tag {empfaenger},\n\nzu unserer Rechnung {nummer} vom {rechnungsdatum} über {betrag} konnten wir bisher keinen Zahlungseingang feststellen. Vielleicht hat sich Ihre Zahlung mit diesem Schreiben überschnitten, dann betrachten Sie es bitte als gegenstandslos.\n\nBitte überweisen Sie den offenen Betrag von {rest} bis zum {frist}.\n\nMit freundlichen Grüßen\n{verein}",
		2 => "Guten Tag {empfaenger},\n\ntrotz unserer Zahlungserinnerung ist die Rechnung {nummer} vom {rechnungsdatum} noch offen. Wir bitten Sie, den Betrag von {rest} bis zum {frist} zu überweisen.\n\nSollten Sie bereits gezahlt haben, melden Sie sich bitte kurz bei uns.\n\nMit freundlichen Grüßen\n{verein}",
		3 => "Guten Tag {empfaenger},\n\nleider haben wir auch auf unsere Mahnung keinen Zahlungseingang für die Rechnung {nummer} vom {rechnungsdatum} festgestellt. Dies ist unsere letzte Mahnung: Bitte überweisen Sie {rest} bis zum {frist}. Andernfalls behalten wir uns weitere Schritte vor.\n\nMit freundlichen Grüßen\n{verein}",
	];

	public function __construct(
		private IMailer $mailer,
		private InvoiceMapper $invoices,
		private OpenItemMapper $openItems,
		private InvoiceDunningMapper $dunnings,
		private InvoiceDocumentService $documents,
		private InvoiceSettings $settings,
		private AuditService $audit,
		private LoggerInterface $logger,
		private IUserSession $userSession,
		private IL10N $l10n,
	) {
	}

	// ------------------------------------------------------------------
	// Lesen
	// ------------------------------------------------------------------

	/**
	 * Ergänzt Zeilen der Rechnungsliste um den Mahnstand (`dunning`).
	 *
	 * @param list<array<string, mixed>> $rows Zeilen aus {@see \OCA\Vereinsbuchhaltung\Service\InvoiceService::list()}
	 * @return list<array<string, mixed>>
	 */
	public function annotate(array $rows, ?string $today = null): array {
		$ids = array_values(array_map(static fn (array $r): int => (int)$r['id'], $rows));
		$last = $this->dunnings->lastByInvoices($ids);
		$today ??= date('Y-m-d');
		foreach ($rows as &$row) {
			$row['dunning'] = $this->stateFromRow($row, $last[(int)$row['id']] ?? null, $today);
		}
		unset($row);
		return $rows;
	}

	/**
	 * Mahnstand und Verlauf einer Rechnung für das Detail.
	 *
	 * @param array<string, mixed> $row Zeile wie in {@see annotate()} (Status, Rest, Zahlungsziel)
	 * @return array{state: array<string, mixed>, history: list<InvoiceDunning>}
	 */
	public function forDetail(array $row, ?string $today = null): array {
		$history = $this->dunnings->findByInvoice((int)$row['id']);
		$last = $history === [] ? null : ['level' => max(array_map(static fn (InvoiceDunning $d): int => $d->getLevel(), $history)), 'sentAt' => end($history)->getSentAt()];
		return ['state' => $this->stateFromRow($row, $last, $today ?? date('Y-m-d')), 'history' => $history];
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array{level: int, sentAt: string}|null $last
	 * @return array{level: int, lastSentAt: string|null, next: int|null, dueOn: string|null, due: bool, blocked: string|null}
	 */
	private function stateFromRow(array $row, ?array $last, string $today): array {
		$level = $last['level'] ?? 0;
		$state = ['level' => $level, 'lastSentAt' => $last['sentAt'] ?? null, 'next' => null, 'dueOn' => null, 'due' => false, 'blocked' => null];
		$blocked = $this->blockReason((string)$row['kind'], (string)$row['status'], (int)($row['remainingCents'] ?? 0), isset($row['dueDate']) ? (string)$row['dueDate'] : null, $today);
		if ($blocked !== null) {
			$state['blocked'] = $blocked;
			return $state;
		}
		$next = InvoiceDunningSchedule::next((string)$row['dueDate'], $level, $last !== null ? substr($last['sentAt'], 0, 10) : null, $this->settings->dunningDays());
		if ($next !== null) {
			$state['next'] = $next['level'];
			$state['dueOn'] = $next['dueOn'];
			$state['due'] = $next['dueOn'] <= $today;
		}
		return $state;
	}

	/**
	 * Warum die Rechnung nicht gemahnt wird; null, wenn sie gemahnt werden darf.
	 * Die Schlüssel sind für die Oberfläche stabil: paid, cancelled, credit, draft, not_due.
	 */
	private function blockReason(string $kind, string $status, int $remainingCents, ?string $dueDate, string $today): ?string {
		return match (true) {
			$kind === Invoice::KIND_CREDIT => 'credit',
			$status === Invoice::STATUS_DRAFT => 'draft',
			$status === Invoice::STATUS_CANCELLED => 'cancelled',
			$status === Invoice::STATUS_PAID || $remainingCents <= 0 => 'paid',
			$dueDate === null || $dueDate >= $today => 'not_due',
			default => null,
		};
	}

	/**
	 * Vorgaben für den Mahndialog.
	 *
	 * @return array{level: int, to: string, subject: string, body: string, remainingCents: int}
	 * @throws DoesNotExistException
	 * @throws \InvalidArgumentException wenn die Rechnung nicht gemahnt werden darf
	 */
	public function defaults(int $invoiceId, ?int $level = null): array {
		[$invoice, $remaining] = $this->mahnable($invoiceId);
		$level ??= $this->nextLevel($invoiceId);
		$this->assertLevel($invoiceId, $level);
		$snapshot = $invoice->debtorSnapshotData() ?? [];
		return [
			'level' => $level,
			'to' => (string)($snapshot['email'] ?? ''),
			'subject' => $this->fill($this->subjectTemplate(), $invoice, $level, $remaining, date('Y-m-d')),
			'body' => $this->fill($this->bodyTemplate($level), $invoice, $level, $remaining, date('Y-m-d')),
			'remainingCents' => $remaining,
		];
	}

	// ------------------------------------------------------------------
	// Versand
	// ------------------------------------------------------------------

	/**
	 * Erzeugt das Mahnschreiben, verschickt es per Mail und merkt die Mahnung vor.
	 *
	 * @param int|null $level Stufe; ohne Angabe die nächste
	 * @throws DoesNotExistException
	 * @throws \InvalidArgumentException wenn die Rechnung nicht gemahnt werden darf, die Stufe nicht passt oder die Adresse ungültig ist
	 * @throws \RuntimeException wenn PDF oder Mailversand scheitern
	 */
	public function send(int $invoiceId, string $to, ?string $subject = null, ?string $body = null, ?int $level = null, bool $auto = false, ?string $today = null): InvoiceDunning {
		$today ??= date('Y-m-d');
		[$invoice, $remaining] = $this->mahnable($invoiceId, $today);
		$level ??= $this->nextLevel($invoiceId);
		$this->assertLevel($invoiceId, $level);
		$to = trim($to);
		if (!EmailValidator::isValid($to)) {
			throw new \InvalidArgumentException($this->l10n->t('Bitte eine gültige E-Mail-Adresse angeben.'));
		}
		$defaults = [
			'subject' => $this->fill($this->subjectTemplate(), $invoice, $level, $remaining, $today),
			'body' => $this->fill($this->bodyTemplate($level), $invoice, $level, $remaining, $today),
		];
		$subject = $subject !== null && trim($subject) !== '' ? trim($subject) : $defaults['subject'];
		$body = $body !== null && trim($body) !== '' ? trim($body) : $defaults['body'];

		$doc = $this->documents->createDunningLetter($invoice, $level, $body, $remaining, $today);
		$content = $this->documents->documentContent($invoiceId, (int)$doc->getId());

		$snapshot = $invoice->debtorSnapshotData() ?? [];
		$recipientName = (string)($snapshot['name'] ?? '');
		$template = $this->mailer->createEMailTemplate('vereinsbuchhaltung.invoice_dunning');
		$template->setSubject($subject);
		$template->addHeader();
		$template->addHeading($this->l10n->t('%1$s zu Rechnung %2$s', [$this->dunningTitle($level), (string)$invoice->getNumber()]));
		foreach (preg_split('/\R{2,}/', $body) ?: [] as $paragraph) {
			$template->addBodyText(nl2br(htmlspecialchars(trim($paragraph), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')), trim($paragraph));
		}
		$template->addFooter('', $this->l10n->getLanguageCode());

		$message = $this->mailer->createMessage();
		$message->setTo($recipientName !== '' ? [$to => $recipientName] : [$to]);
		$message->setSubject($template->renderSubject());
		$message->useTemplate($template);
		$message->attach($this->mailer->createAttachment($content['content'], $content['fileName'], 'application/pdf'));

		try {
			$failed = $this->mailer->send($message);
		} catch (\Throwable $e) {
			$this->logger->warning('Mahnversand fehlgeschlagen: {message}', ['app' => Application::APP_ID, 'message' => $e->getMessage(), 'exception' => $e]);
			throw new \RuntimeException($this->l10n->t('Die E-Mail konnte nicht versendet werden. Bitte prüfen Sie den Mailserver in den Nextcloud-Einstellungen.'), 0, $e);
		}
		if ($failed !== []) {
			throw new \RuntimeException($this->l10n->t('Die E-Mail wurde vom Mailserver nicht angenommen.'));
		}

		$dunning = new InvoiceDunning();
		$dunning->setInvoiceId($invoiceId);
		$dunning->setLevel($level);
		$dunning->setSentAt((new \DateTimeImmutable())->format(\DateTimeInterface::ATOM));
		$dunning->setSentTo($to);
		$dunning->setRemainingCents($remaining);
		$dunning->setDocumentId((int)$doc->getId());
		$dunning->setAuto($auto ? 1 : 0);
		$dunning->setCreatedBy($auto ? 'system' : $this->actor());
		$dunning = $this->dunnings->insert($dunning);
		$this->audit->log($auto ? 'Rechnung automatisch gemahnt' : 'Rechnung gemahnt', 'invoice', $invoiceId, ['number' => $invoice->getNumber(), 'level' => $level, 'to' => $to]);
		return $dunning;
	}

	/**
	 * Der tägliche Lauf: verschickt fällige Stufen, wenn die Einstellung es vorsieht.
	 * Rechnungen ohne E-Mail-Adresse des Empfängers werden übersprungen und tauchen in
	 * der Liste als „Mahnung fällig" auf.
	 *
	 * @return array{sent: int, skipped: int, failed: int}
	 */
	public function runDaily(?string $today = null): array {
		$result = ['sent' => 0, 'skipped' => 0, 'failed' => 0];
		if (!$this->settings->dunningAuto()) {
			return $result;
		}
		$today ??= date('Y-m-d');
		$candidates = [];
		foreach ([Invoice::STATUS_ISSUED, Invoice::STATUS_SENT] as $status) {
			foreach ($this->invoices->findFiltered($status, null, null) as $invoice) {
				if (!$invoice->isCredit() && $invoice->getDueDate() !== null && $invoice->getDueDate() < $today) {
					$candidates[(int)$invoice->getId()] = $invoice;
				}
			}
		}
		if ($candidates === []) {
			return $result;
		}
		$items = $this->openItems->findByIds(array_values(array_filter(array_map(static fn (Invoice $i) => $i->getOpenItemId(), $candidates))));
		$last = $this->dunnings->lastByInvoices(array_keys($candidates));
		foreach ($candidates as $id => $invoice) {
			$item = $items[$invoice->getOpenItemId() ?? 0] ?? null;
			$state = $this->stateFromRow([
				'id' => $id,
				'kind' => $invoice->getKind(),
				'status' => $invoice->getStatus(),
				'remainingCents' => $item?->remainingCents() ?? 0,
				'dueDate' => $invoice->getDueDate(),
			], $last[$id] ?? null, $today);
			if (!$state['due'] || $state['next'] === null) {
				continue;
			}
			$to = (string)(($invoice->debtorSnapshotData() ?? [])['email'] ?? '');
			if (!EmailValidator::isValid($to)) {
				$result['skipped']++;
				continue;
			}
			try {
				$this->send($id, $to, null, null, $state['next'], true, $today);
				$result['sent']++;
			} catch (\Throwable $e) {
				$result['failed']++;
				$this->logger->warning('Automatische Mahnung für {number} fehlgeschlagen: {message}', ['app' => Application::APP_ID, 'number' => $invoice->getNumber(), 'message' => $e->getMessage(), 'exception' => $e]);
			}
		}
		return $result;
	}

	// ------------------------------------------------------------------

	/**
	 * @return array{0: Invoice, 1: int} die Rechnung und ihr Rest
	 * @throws DoesNotExistException
	 * @throws \InvalidArgumentException
	 */
	private function mahnable(int $invoiceId, ?string $today = null): array {
		$invoice = $this->invoices->find($invoiceId);
		$item = $invoice->getOpenItemId() !== null ? ($this->openItems->findByIds([$invoice->getOpenItemId()])[$invoice->getOpenItemId()] ?? null) : null;
		$remaining = $item?->remainingCents() ?? 0;
		$reason = $this->blockReason($invoice->getKind(), $invoice->getStatus(), $remaining, $invoice->getDueDate(), $today ?? date('Y-m-d'));
		if ($reason !== null) {
			throw new \InvalidArgumentException(match ($reason) {
				'paid' => $this->l10n->t('Die Rechnung ist bezahlt.'),
				'cancelled' => $this->l10n->t('Die Rechnung ist storniert.'),
				'credit' => $this->l10n->t('Eine Stornorechnung wird nicht gemahnt.'),
				'draft' => $this->l10n->t('Ein Entwurf wird nicht gemahnt.'),
				default => $this->l10n->t('Die Rechnung ist noch nicht fällig.'),
			});
		}
		return [$invoice, $remaining];
	}

	private function nextLevel(int $invoiceId): int {
		$last = $this->dunnings->lastByInvoices([$invoiceId])[$invoiceId]['level'] ?? 0;
		if ($last >= InvoiceDunningSchedule::MAX_LEVEL) {
			throw new \InvalidArgumentException($this->l10n->t('Die letzte Mahnstufe ist schon erreicht.'));
		}
		return $last + 1;
	}

	/** Eine Stufe lässt sich nicht wiederholen; überspringen ist erlaubt (gleich mit der ersten Mahnung beginnen). */
	private function assertLevel(int $invoiceId, int $level): void {
		if (!in_array($level, InvoiceDunning::LEVELS, true)) {
			throw new \InvalidArgumentException($this->l10n->t('Unbekannte Mahnstufe.'));
		}
		$last = $this->dunnings->lastByInvoices([$invoiceId])[$invoiceId]['level'] ?? 0;
		if ($level <= $last) {
			throw new \InvalidArgumentException($this->l10n->t('Diese Mahnstufe wurde schon verschickt.'));
		}
	}

	private function dunningTitle(int $level): string {
		return match ($level) {
			1 => $this->l10n->t('Zahlungserinnerung'),
			2 => $this->l10n->t('1. Mahnung'),
			default => $this->l10n->t('2. Mahnung'),
		};
	}

	private function subjectTemplate(): string {
		$custom = trim((string)($this->settings->all()[InvoiceSettings::SETTING_DUNNING_SUBJECT] ?? ''));
		return $custom !== '' ? $custom : $this->l10n->t(self::DEFAULT_SUBJECT);
	}

	private function bodyTemplate(int $level): string {
		$custom = trim((string)($this->settings->all()['invoice_dunning_body_' . $level] ?? ''));
		return $custom !== '' ? $custom : $this->l10n->t(self::DEFAULT_BODIES[$level]);
	}

	private function fill(string $text, Invoice $invoice, int $level, int $remaining, string $today): string {
		$snapshot = $invoice->debtorSnapshotData() ?? [];
		$deadline = (new \DateTimeImmutable($today))->modify('+' . $this->settings->dunningGraceDays() . ' days')->format('Y-m-d');
		$money = static fn (int $cents): string => number_format($cents / 100, 2, ',', '.') . ' €';
		$date = static fn (?string $iso): string => $iso !== null && $iso !== '' ? (new \DateTimeImmutable(substr($iso, 0, 10)))->format('d.m.Y') : '';
		return strtr($text, [
			'{stufe}' => $this->dunningTitle($level),
			'{nummer}' => (string)$invoice->getNumber(),
			'{rechnungsdatum}' => $date($invoice->getIssueDate()),
			'{betrag}' => $money(abs($invoice->getGrossCents())),
			'{rest}' => $money($remaining),
			'{faellig}' => $date($invoice->getDueDate()),
			'{frist}' => $date($deadline),
			'{verein}' => $this->settings->issuer()['name'],
			'{empfaenger}' => (string)($snapshot['name'] ?? ''),
		]);
	}

	private function actor(): string {
		return $this->userSession->getUser()?->getUID() ?? 'system';
	}
}
