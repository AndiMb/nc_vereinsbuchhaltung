<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Belegdatei eines Entwurfs (Issue #356). Der Inhalt liegt in der
 * app-internen Ablage unter `reimbursement-drafts/<id>` – nie im
 * Wächter-Ordner, den der Kassenwart sieht. Beim Einreichen wird daraus eine
 * gewöhnliche {@see ReimbursementFile}, die Entwurfsdatei verschwindet.
 *
 * @method int getDraftId()
 * @method void setDraftId(int $draftId)
 * @method string getFileName()
 * @method void setFileName(string $fileName)
 * @method string getMimeType()
 * @method void setMimeType(string $mimeType)
 * @method int getFileSize()
 * @method void setFileSize(int $fileSize)
 * @method string getUploadedAt()
 * @method void setUploadedAt(string $uploadedAt)
 */
class ReimbursementDraftFile extends Entity implements \JsonSerializable {
	protected $draftId;
	protected $fileName;
	protected $mimeType;
	protected $fileSize;
	protected $uploadedAt;

	public function __construct() {
		$this->addType('draftId', 'integer');
		$this->addType('fileSize', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'fileName' => $this->fileName,
			'mimeType' => $this->mimeType,
			'fileSize' => $this->fileSize,
			'uploadedAt' => $this->uploadedAt,
		];
	}
}
