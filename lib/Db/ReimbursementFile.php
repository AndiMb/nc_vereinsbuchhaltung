<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Belegdatei eines Auslagenantrags. Anders als ein {@see Attachment} hängt sie
 * an keiner Buchung, sondern am Antrag; bei der Auszahlung entsteht der
 * normale Beleg-Eintrag an der Buchung.
 *
 * Eine entfernte Datei bleibt als Zeile stehen (`removedAt`): die Historie
 * verweist auf sie, und der Inhalts-Hash für den Dubletten-Hinweis soll
 * nicht verschwinden.
 *
 * @method int getReimbursementId()
 * @method void setReimbursementId(int $reimbursementId)
 * @method string getFileName()
 * @method void setFileName(string $fileName)
 * @method string getMimeType()
 * @method void setMimeType(string $mimeType)
 * @method int getFileSize()
 * @method void setFileSize(int $fileSize)
 * @method int|null getFileId()
 * @method void setFileId(?int $fileId)
 * @method string|null getFileOwner()
 * @method void setFileOwner(?string $fileOwner)
 * @method string|null getContentHash()
 * @method void setContentHash(?string $contentHash)
 * @method string getUploadedAt()
 * @method void setUploadedAt(string $uploadedAt)
 * @method string|null getRemovedAt()
 * @method void setRemovedAt(?string $removedAt)
 */
class ReimbursementFile extends Entity implements \JsonSerializable {
	protected $reimbursementId;
	protected $fileName;
	protected $mimeType;
	protected $fileSize;
	protected $fileId;
	protected $fileOwner;
	protected $contentHash;
	protected $uploadedAt;
	protected $removedAt;

	public function __construct() {
		$this->addType('reimbursementId', 'integer');
		$this->addType('fileSize', 'integer');
		$this->addType('fileId', 'integer');
	}

	public function isRemoved(): bool {
		return $this->removedAt !== null;
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'reimbursementId' => $this->reimbursementId,
			'fileName' => $this->fileName,
			'mimeType' => $this->mimeType,
			'fileSize' => $this->fileSize,
			'uploadedAt' => $this->uploadedAt,
		];
	}
}
