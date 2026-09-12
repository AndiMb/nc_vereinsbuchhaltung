<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Attachment;
use OCA\Vereinsbuchhaltung\Db\AttachmentMapper;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;

/**
 * Der Wächter-Ordner für Belege: welche Dateien liegen darin, welche davon
 * hängen schon an einer Buchung, und welche Belege zeigen ins Leere.
 *
 * Gedacht als Eingangskorb, der zugleich das Archiv ist: Rechnungen kommen
 * per Scanner, Mail oder Handy-Upload in den Ordner, die Übersicht meldet,
 * was noch keiner Buchung zugeordnet ist – so geht keine Rechnung unter, die
 * noch zu überweisen wäre. Die App liest hier nur; verschoben oder gelöscht
 * wird nichts.
 */
class AttachmentWatchFolderService {

	/** Dieselben Typen, die auch der Upload annimmt. */
	public const ALLOWED_MIMES = [
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
		'application/pdf',
	];

	/**
	 * Obergrenze des Ordnerscans. Ein Vereinsjahr hat ein paar hundert Belege;
	 * wer versehentlich sein ganzes Home als Wächter-Ordner einträgt, soll die
	 * Übersicht nicht minutenlang aufhalten.
	 */
	public const MAX_FILES = 5000;

	public function __construct(
		private AttachmentStorageService $storage,
		private AttachmentMapper $attachmentMapper,
		private IRootFolder $rootFolder,
		private IL10N $l10n,
	) {
	}

	public static function isReceipt(string $mime): bool {
		return in_array($mime, self::ALLOWED_MIMES, true);
	}

	/**
	 * Ist einer der beiden Pfade der andere oder liegt in ihm? Beide relativ
	 * zum selben Nutzer-Home, ohne führende oder schließende Schrägstriche.
	 */
	/** Versteckte Einträge zählen nirgends – weder als Dokument noch in der Ordnerwahl. */
	public static function isHidden(string $name): bool {
		return str_starts_with($name, '.');
	}

	public static function nested(string $a, string $b): bool {
		$a = trim($a, '/');
		$b = trim($b, '/');
		return $a === $b || str_starts_with($a, $b . '/') || str_starts_with($b, $a . '/');
	}

	/**
	 * Alle Beleg-Dateien im Ordnerbaum, neueste zuerst. Null, wenn der
	 * Wächter-Modus aus ist oder der Ordner fehlt.
	 *
	 * @return array{files: list<array{fileId:int, name:string, folder:string, size:int, mtime:int, mime:string}>, capped: bool}|null
	 */
	public function scan(): ?array {
		$folder = $this->storage->watchFolder();
		if ($folder === null) {
			return null;
		}
		$files = [];
		$capped = $this->collect($folder, '', $files);
		usort($files, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime'] ?: strcmp($a['name'], $b['name']));
		return ['files' => $files, 'capped' => $capped];
	}

	/**
	 * @param list<array<string,mixed>> $files
	 * @return bool ob die Obergrenze erreicht wurde
	 */
	private function collect(Folder $folder, string $prefix, array &$files): bool {
		foreach ($folder->getDirectoryListing() as $node) {
			$name = $node->getName();
			if (self::isHidden($name)) {
				continue;
			}
			if ($node instanceof Folder) {
				if ($this->collect($node, $prefix === '' ? $name : $prefix . '/' . $name, $files)) {
					return true;
				}
				continue;
			}
			if (!$node instanceof File || !self::isReceipt($node->getMimetype())) {
				continue;
			}
			$files[] = [
				'fileId' => $node->getId(),
				'name' => $name,
				'folder' => $prefix,
				'size' => (int)$node->getSize(),
				'mtime' => (int)$node->getMTime(),
				'mime' => $node->getMimetype(),
			];
			if (count($files) >= self::MAX_FILES) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Der Eingangskorb: alle Dateien des Ordners samt Buchungen, an denen sie
	 * hängen, und die Belege, deren Datei verschwunden ist. Ein Scan, eine
	 * Abfrage – {@see summary()} und der Dialog projizieren daraus.
	 *
	 * @return array{folderMissing: bool, capped: bool, limit: int,
	 *         files: list<array{fileId:int, name:string, folder:string, size:int, mtime:int, mime:string, journalIds: list<int>}>,
	 *         missing: Attachment[]}
	 */
	public function inbox(string $userId): array {
		$empty = ['folderMissing' => false, 'capped' => false, 'limit' => self::MAX_FILES, 'files' => [], 'missing' => []];
		if (!$this->storage->isWatchMode()) {
			return $empty;
		}
		$scan = $this->scan();
		if ($scan === null) {
			// Ohne Ordner ist "fehlend" keine Aussage über einzelne Belege –
			// und die Einzelprüfung je Beleg wäre bei jedem Aufruf fällig.
			return ['folderMissing' => true] + $empty;
		}
		$linked = $this->attachmentMapper->findLinked($userId);
		$journalsByFile = [];
		foreach ($linked as $attachment) {
			$journalsByFile[(int)$attachment->getFileId()][] = $attachment->getJournalId();
		}
		$files = [];
		foreach ($scan['files'] as $file) {
			$file['journalIds'] = $journalsByFile[$file['fileId']] ?? [];
			$files[] = $file;
		}
		return [
			'folderMissing' => false,
			'capped' => $scan['capped'],
			'limit' => self::MAX_FILES,
			'files' => $files,
			'missing' => $this->missingAmong($linked, $scan['files']),
		];
	}

	/**
	 * Die Zahlen für die Übersicht.
	 *
	 * @return array{folderMissing: bool, capped: bool, unassigned: int, missing: int}
	 */
	public function summary(string $userId): array {
		$inbox = $this->inbox($userId);
		return [
			'folderMissing' => $inbox['folderMissing'],
			'capped' => $inbox['capped'],
			'unassigned' => count(array_filter($inbox['files'], static fn (array $f): bool => $f['journalIds'] === [])),
			'missing' => count($inbox['missing']),
		];
	}

	/**
	 * Was der Scan gesehen hat, ist da. Nur für den Rest wird einzeln
	 * nachgeschlagen – die Datei kann ja außerhalb des Ordners liegen, wenn
	 * jemand sie in der Files-App woandershin verschoben hat.
	 *
	 * @param Attachment[] $linked
	 * @param list<array{fileId:int}> $scanned
	 * @return Attachment[]
	 */
	private function missingAmong(array $linked, array $scanned): array {
		$seen = [];
		foreach ($scanned as $file) {
			$seen[$file['fileId']] = true;
		}
		return array_values(array_filter(
			$linked,
			fn (Attachment $a): bool => !isset($seen[(int)$a->getFileId()]) && !$this->storage->exists($a),
		));
	}

	/**
	 * Eine Datei des Wächter-Ordners anhand ihrer Kennung.
	 *
	 * Gesucht wird nur im Ordnerbaum, nicht im ganzen Home des Nutzers. Sonst
	 * ließe sich per Kennung jede beliebige Datei des Ablage-Nutzers als Beleg
	 * einbinden und über die App auslesen.
	 *
	 * @throws \RuntimeException wenn die Datei nicht im Ordner liegt oder kein Beleg-Typ ist
	 */
	public function fileInFolder(int $fileId): File {
		$folder = $this->storage->watchFolder();
		if ($folder === null) {
			throw new \RuntimeException($this->l10n->t('Der Wächter-Ordner für Belege existiert nicht. Bitte in den Einstellungen prüfen.'));
		}
		$node = $folder->getFirstNodeById($fileId);
		if (!$node instanceof File) {
			throw new \RuntimeException($this->l10n->t('Die Datei liegt nicht im Wächter-Ordner.'));
		}
		if (!self::isReceipt($node->getMimetype())) {
			throw new \RuntimeException($this->l10n->t('Nur Bilder (JPG/PNG/GIF/WebP) und PDFs erlaubt'));
		}
		return $node;
	}

	/** @throws \RuntimeException wenn die Datei nicht im Ordner liegt, kein Beleg-Typ ist oder schon an der Buchung hängt */
	public function link(string $userId, int $journalId, int $fileId): Attachment {
		$node = $this->fileInFolder($fileId);
		foreach ($this->attachmentMapper->findByJournal($journalId, $userId) as $existing) {
			if ((int)$existing->getFileId() === $fileId) {
				throw new \RuntimeException($this->l10n->t('Diese Datei hängt bereits an der Buchung.'));
			}
		}
		$attachment = new Attachment();
		$attachment->setJournalId($journalId);
		$attachment->setUserId($userId);
		$attachment->setFileName($node->getName());
		$attachment->setMimeType($node->getMimetype());
		$attachment->setFileSize((int)$node->getSize());
		$attachment->setUploadedAt(new \DateTime());
		$this->storage->attach($attachment, $node);
		return $this->attachmentMapper->insert($attachment);
	}

	/**
	 * Trägt für Belege, die die App früher unter <Ablage>/<BuchungsID>/
	 * abgelegt hat, die Datei-ID nach. Beim Umschalten auf den Wächter-Ordner
	 * aufgerufen, mit Nutzer und Pfad der bisherigen Ablage – gesucht wird
	 * dort, wo die Dateien liegen, nicht im neuen Ordner. So kann der
	 * bisherige Belegordner selbst zum Wächter-Ordner werden, ohne dass seine
	 * Dateien als „nicht zugewiesen" erscheinen; wird ein anderer Ordner
	 * gewählt, gelten die alten Belege als fehlend, bis sie hineingeschoben
	 * sind – die Datei-ID überlebt das.
	 *
	 * Belege aus der app-internen Ablage liegen nicht im Nutzerbereich und
	 * bleiben, wie sie sind.
	 *
	 * @return int Anzahl der nachgetragenen Belege
	 */
	public function backfillFileIds(string $userId, string $owner, string $basePath): int {
		if ($owner === '') {
			return 0;
		}
		try {
			$userFolder = $this->rootFolder->getUserFolder($owner);
		} catch (\Throwable) {
			return 0;
		}
		$count = 0;
		foreach ($this->attachmentMapper->findUnlinked($userId) as $attachment) {
			try {
				$node = $userFolder->get($this->storage->getNcFilePath($attachment->getId(), $attachment->getJournalId(), $attachment->getFileName(), $basePath));
			} catch (\Throwable) {
				continue;
			}
			if (!$node instanceof File) {
				continue;
			}
			$this->storage->attach($attachment, $node, $owner);
			$this->attachmentMapper->update($attachment);
			$count++;
		}
		return $count;
	}
}
