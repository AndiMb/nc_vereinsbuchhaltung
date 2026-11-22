<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\Reimbursement;
use OCA\Vereinsbuchhaltung\Db\ReimbursementDraft;
use OCA\Vereinsbuchhaltung\Db\ReimbursementDraftFile;
use OCA\Vereinsbuchhaltung\Db\ReimbursementDraftFileMapper;
use OCA\Vereinsbuchhaltung\Db\ReimbursementDraftMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCA\Vereinsbuchhaltung\Service\Text\Utf8;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\NotFoundException;
use OCP\IL10N;

/**
 * Entwürfe von Auslagenanträgen (Issue #356).
 *
 * Ein Entwurf ist die private Vorarbeit eines Mitglieds: alle Felder sind
 * optional, Belege lassen sich schon anhängen. Verwalter sehen davon nichts –
 * es gibt keinen Lesepfad außer über die eigene member_id, die Dateien liegen
 * in der app-internen Ablage (`reimbursement-drafts/<Datei-ID>`) und nie im
 * Wächter-Ordner.
 *
 * Eingereicht wird über {@see ReimbursementService::submit()}, unverändert:
 * dort entstehen wie bei jedem Antrag Nummer, Erklärung, Historie und
 * Benachrichtigung. In derselben Transaktion verschwindet der Entwurf; seine
 * Dateien werden erst nach dem Commit gelöscht.
 */
class ReimbursementDraftService {

	private const MAX_PURPOSE_LENGTH = ReimbursementService::MAX_PURPOSE_LENGTH;
	private const MAX_INVOICE_NUMBER_LENGTH = 100;
	private const MAX_SUPPLIER_LENGTH = 255;
	private const MAX_ACCOUNT_HOLDER_LENGTH = 255;
	private const MAX_IBAN_LENGTH = 64;

	public function __construct(
		private ReimbursementDraftMapper $drafts,
		private ReimbursementDraftFileMapper $files,
		private ReimbursementService $reimbursements,
		private TransactionRunner $transaction,
		private ITimeFactory $time,
		private IL10N $l10n,
		private ReimbursementDraftCleaner $cleaner,
	) {
	}

	/**
	 * Die Entwürfe des Mitglieds samt ihren Belegen, zuletzt bearbeitete zuerst.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function listForMember(int $memberId): array {
		$drafts = $this->drafts->findByMember($memberId);
		$byDraft = [];
		foreach ($this->files->findByDrafts(array_map(static fn (ReimbursementDraft $d): int => $d->getId(), $drafts)) as $file) {
			$byDraft[$file->getDraftId()][] = $file;
		}
		return array_values(array_map(fn (ReimbursementDraft $d): array => $this->present($d, $byDraft[$d->getId()] ?? []), $drafts));
	}

	/**
	 * @return array<string, mixed>
	 * @throws \OCP\AppFramework\Db\DoesNotExistException wenn es keinen eigenen Entwurf mit der ID gibt
	 */
	public function findForMember(int $id, int $memberId): array {
		$draft = $this->drafts->findForMember($id, $memberId);
		return $this->present($draft, $this->files->findByDraft($id));
	}

	/**
	 * Legt einen Entwurf an oder speichert ihn neu.
	 *
	 * @param int|null $id null für einen neuen Entwurf
	 * @param array{amountCents: ?int, expenseDate: ?string, purpose: ?string, invoiceNumber: ?string, supplier: ?string, iban: ?string, accountHolder: ?string, kind?: string, travel?: mixed} $fields
	 * @param list<int> $keepFileIds die schon gespeicherten Belege, die bleiben; alle anderen verschwinden
	 * @param list<array{name: string, content: string}> $uploads neue Belege
	 * @return array<string, mixed> der gespeicherte Entwurf
	 * @throws \InvalidArgumentException bei unbrauchbaren Angaben
	 * @throws \OCP\AppFramework\Db\DoesNotExistException wenn es den Entwurf nicht (mehr) gibt
	 */
	public function save(?int $id, int $memberId, array $fields, array $keepFileIds, array $uploads): array {
		$clean = $this->cleanFields($fields);
		$checked = $this->checkUploads($uploads);
		$now = $this->now();

		$purge = [];
		$draft = $this->transaction->run(function () use ($id, $memberId, $clean, $keepFileIds, $checked, $now, &$purge): ReimbursementDraft {
			if ($id === null) {
				$draft = new ReimbursementDraft();
				$draft->setMemberId($memberId);
				$draft->setCreatedAt($now);
			} else {
				$draft = $this->drafts->findForMember($id, $memberId);
			}
			$draft->setAmountCents($clean['amountCents']);
			$draft->setExpenseDate($clean['expenseDate']);
			$draft->setPurpose($clean['purpose']);
			$draft->setInvoiceNumber($clean['invoiceNumber']);
			$draft->setSupplier($clean['supplier']);
			$draft->setIban($clean['iban']);
			$draft->setAccountHolder($clean['accountHolder']);
			$draft->setKind($clean['kind']);
			$draft->setTravelDetails($clean['travelDetails']);
			$draft->setUpdatedAt($now);
			$draft = $id === null ? $this->drafts->insert($draft) : $this->drafts->update($draft);

			$kept = 0;
			if ($id !== null) {
				foreach ($this->files->findByDraft($draft->getId()) as $file) {
					if (in_array($file->getId(), $keepFileIds, true)) {
						$kept++;
						continue;
					}
					$this->files->delete($file);
					$purge[] = $file->getId();
				}
			}
			if ($kept + count($checked) > ReimbursementService::MAX_FILES) {
				throw new \InvalidArgumentException($this->l10n->t('Zu viele Dateien (höchstens %d je Antrag).', [ReimbursementService::MAX_FILES]));
			}
			foreach ($checked as $upload) {
				$this->storeFile($draft->getId(), $upload, $now);
			}
			return $draft;
		});
		$this->cleaner->purge($purge);

		return $this->findForMember($draft->getId(), $memberId);
	}

	/**
	 * Reicht den Entwurf ein: die übergebenen Angaben (das Formular, wie es
	 * gerade aussieht) plus die behaltenen Belege des Entwurfs und neue
	 * Uploads. Scheitert das Einreichen, bleibt der Entwurf unverändert.
	 *
	 * @param array{amountCents: ?int, expenseDate: ?string, purpose: ?string, invoiceNumber: ?string, supplier: ?string, iban: ?string, accountHolder: ?string, kind?: string, travel?: mixed} $fields
	 * @param list<int> $keepFileIds
	 * @param list<array{name: string, content: string}> $uploads
	 * @throws \InvalidArgumentException bei unvollständigen oder ungültigen Angaben
	 * @throws \OCP\AppFramework\Db\DoesNotExistException wenn es den Entwurf nicht (mehr) gibt
	 */
	public function submit(int $id, Member $member, array $fields, bool $declarationAccepted, array $keepFileIds, array $uploads, string $actorUid): Reimbursement {
		$draft = $this->drafts->findForMember($id, $member->getId());
		$draftFiles = $this->files->findByDraft($id);

		$all = [];
		foreach ($draftFiles as $file) {
			if (in_array($file->getId(), $keepFileIds, true)) {
				$all[] = ['name' => $file->getFileName(), 'content' => $this->content($file)];
			}
		}
		foreach ($uploads as $upload) {
			$all[] = $upload;
		}

		// Reisekostenabrechnung (#353): Betrag und Datum ergeben sich aus den Reiseangaben.
		$travel = ($fields['kind'] ?? 'expense') === 'travel'
			? TravelDetails::fromInput($fields['travel'] ?? null, $this->l10n, true)
			: null;

		$reimbursement = $this->transaction->run(function () use ($draft, $draftFiles, $member, $fields, $declarationAccepted, $all, $actorUid, $travel): Reimbursement {
			$reimbursement = $this->reimbursements->submit(
				$member,
				$travel !== null ? $travel->totalCents() : ($fields['amountCents'] ?? 0),
				$travel !== null ? (string)$travel->startDate() : (string)($fields['expenseDate'] ?? ''),
				(string)($fields['purpose'] ?? ''),
				$fields['invoiceNumber'] ?? null,
				$fields['supplier'] ?? null,
				(string)($fields['iban'] ?? ''),
				$fields['accountHolder'] ?? null,
				$declarationAccepted,
				$all,
				$actorUid,
				$travel,
			);
			foreach ($draftFiles as $file) {
				$this->files->delete($file);
			}
			$this->drafts->delete($draft);
			return $reimbursement;
		});
		$this->cleaner->purge(array_map(static fn (ReimbursementDraftFile $f): int => $f->getId(), $draftFiles));
		return $reimbursement;
	}

	/** @throws \OCP\AppFramework\Db\DoesNotExistException wenn es keinen eigenen Entwurf mit der ID gibt */
	public function delete(int $id, int $memberId): void {
		$this->cleaner->deleteDrafts([$this->drafts->findForMember($id, $memberId)]);
	}

	/**
	 * Inhalt eines Belegs aus einem eigenen Entwurf.
	 *
	 * @return array{file: ReimbursementDraftFile, content: string}
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 */
	public function fileForMember(int $id, int $fileId, int $memberId): array {
		$this->drafts->findForMember($id, $memberId);
		$file = $this->files->findForDraft($id, $fileId);
		return ['file' => $file, 'content' => $this->content($file)];
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array{amountCents: ?int, expenseDate: ?string, purpose: ?string, invoiceNumber: ?string, supplier: ?string, iban: ?string, accountHolder: ?string, kind: string, travelDetails: ?string}
	 */
	private function cleanFields(array $fields): array {
		if (($fields['kind'] ?? 'expense') === 'travel') {
			// Entwurf einer Reisekostenabrechnung (#353): unvollständig erlaubt; Betrag und Datum
			// folgen aus den Positionen, damit die Entwurfsliste sie zeigen kann.
			$travel = TravelDetails::fromInput($fields['travel'] ?? ['items' => []], $this->l10n, false);
			$fields['amountCents'] = $travel->items !== [] ? $travel->totalCents() : null;
			$fields['expenseDate'] = $travel->startDate();
			$fields['invoiceNumber'] = null;
			$fields['supplier'] = null;
		}
		$amount = $fields['amountCents'] ?? null;
		if ($amount !== null && (!is_int($amount) || $amount < 0)) {
			throw new \InvalidArgumentException($this->l10n->t('Bitte einen gültigen Betrag angeben, z. B. 12,50.'));
		}
		$date = $this->text($fields['expenseDate'] ?? null, 10);
		if ($date !== null) {
			$parsed = \DateTime::createFromFormat('!Y-m-d', $date);
			if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
				throw new \InvalidArgumentException($this->l10n->t('Bitte ein gültiges Datum angeben (JJJJ-MM-TT).'));
			}
		}
		$iban = $this->text($fields['iban'] ?? null, self::MAX_IBAN_LENGTH);
		$accountHolder = $this->text($fields['accountHolder'] ?? null, self::MAX_ACCOUNT_HOLDER_LENGTH);
		// Wie beim Einreichen ({@see ReimbursementService::submit()}): ein
		// Zeilenumbruch im Kontoinhaber könnte im GiroCode eine fremde IBAN
		// vortäuschen. Schon der Entwurf lehnt ihn ab.
		if ($accountHolder !== null && preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $accountHolder) === 1) {
			throw new \InvalidArgumentException($this->l10n->t('Der Kontoinhaber darf keine Zeilenumbrüche oder Steuerzeichen enthalten.'));
		}
		return [
			'amountCents' => $amount,
			'expenseDate' => $date,
			'purpose' => $this->text($fields['purpose'] ?? null, self::MAX_PURPOSE_LENGTH),
			'invoiceNumber' => $this->text($fields['invoiceNumber'] ?? null, self::MAX_INVOICE_NUMBER_LENGTH),
			'supplier' => $this->text($fields['supplier'] ?? null, self::MAX_SUPPLIER_LENGTH),
			'iban' => $iban !== null ? strtoupper((string)preg_replace('/\s+/', '', $iban)) : null,
			'accountHolder' => $accountHolder,
			'kind' => isset($travel) ? Reimbursement::KIND_TRAVEL : Reimbursement::KIND_EXPENSE,
			'travelDetails' => isset($travel) ? $travel->toJson() : null,
		];
	}

	/** Leerer Text wird null; zu langer ist ein Fehler, damit nichts stillschweigend abgeschnitten wird. */
	private function text(mixed $value, int $max): ?string {
		if ($value === null) {
			return null;
		}
		$value = Utf8::wellFormed(trim((string)$value), $this->l10n);
		if ($value === '') {
			return null;
		}
		if (mb_strlen($value) > $max) {
			throw new \InvalidArgumentException($this->l10n->t('Eine Angabe ist zu lang (höchstens %d Zeichen).', [$max]));
		}
		return $value;
	}

	/**
	 * Dieselben Grenzen wie für Belege eines Antrags ({@see ReimbursementFileService::add()}).
	 *
	 * @param list<array{name: string, content: string}> $uploads
	 * @return list<array{name: string, content: string, mime: string}>
	 */
	private function checkUploads(array $uploads): array {
		$checked = [];
		foreach ($uploads as $upload) {
			$size = strlen($upload['content']);
			if ($size === 0) {
				throw new \InvalidArgumentException($this->l10n->t('Die Datei ist leer.'));
			}
			if ($size > ReimbursementFileService::MAX_SIZE) {
				throw new \InvalidArgumentException($this->l10n->t('Datei zu groß (max. 20 MB)'));
			}
			$mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($upload['content']);
			if ($mime === false || !AttachmentWatchFolderService::isReceipt($mime)) {
				throw new \InvalidArgumentException($this->l10n->t('Nur Bilder (JPG/PNG/GIF/WebP) und PDFs erlaubt'));
			}
			$checked[] = ['name' => $upload['name'], 'content' => $upload['content'], 'mime' => $mime];
		}
		return $checked;
	}

	/** @param array{name: string, content: string, mime: string} $upload */
	private function storeFile(int $draftId, array $upload, string $now): void {
		$file = new ReimbursementDraftFile();
		$file->setDraftId($draftId);
		$name = basename($upload['name']);
		$file->setFileName(mb_substr($name !== '' ? $name : 'beleg', 0, 255));
		$file->setMimeType($upload['mime']);
		$file->setFileSize(strlen($upload['content']));
		$file->setUploadedAt($now);
		$file = $this->files->insert($file);
		$fileId = $file->getId();
		$this->cleaner->store($fileId, $upload['content']);
		$this->transaction->afterRollback(fn () => $this->cleaner->purgeNow([$fileId]));
	}

	private function content(ReimbursementDraftFile $file): string {
		try {
			return $this->cleaner->content($file->getId());
		} catch (NotFoundException $e) {
			throw new UserFacingException($this->l10n->t('Die Datei zu diesem Beleg wurde nicht gefunden.'), 0, $e);
		}
	}

	/**
	 * @param ReimbursementDraftFile[] $files
	 * @return array<string, mixed>
	 */
	private function present(ReimbursementDraft $draft, array $files): array {
		return [
			'id' => $draft->getId(),
			'amountCents' => $draft->getAmountCents(),
			'expenseDate' => $draft->getExpenseDate(),
			'purpose' => $draft->getPurpose() ?? '',
			'invoiceNumber' => $draft->getInvoiceNumber() ?? '',
			'supplier' => $draft->getSupplier() ?? '',
			'iban' => $draft->getIban() ?? '',
			'accountHolder' => $draft->getAccountHolder() ?? '',
			'kind' => $draft->getKind() ?? Reimbursement::KIND_EXPENSE,
			'travel' => TravelDetails::decode($draft->getTravelDetails()),
			'createdAt' => $draft->getCreatedAt(),
			'updatedAt' => $draft->getUpdatedAt(),
			'files' => array_values($files),
		];
	}

	private function now(): string {
		return $this->time->getDateTime()->format('Y-m-d H:i:s');
	}
}
