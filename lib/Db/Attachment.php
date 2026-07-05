<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getJournalId()
 * @method void setJournalId(int $journalId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getFileName()
 * @method void setFileName(string $fileName)
 * @method string getMimeType()
 * @method void setMimeType(string $mimeType)
 * @method int getFileSize()
 * @method void setFileSize(int $fileSize)
 * @method \DateTime getUploadedAt()
 * @method void setUploadedAt(\DateTime $uploadedAt)
 */
class Attachment extends Entity implements \JsonSerializable {
	protected $journalId;
	protected $userId;
	protected $fileName;
	protected $mimeType;
	protected $fileSize;
	protected $uploadedAt;

	public function __construct() {
		$this->addType('journalId', 'integer');
		$this->addType('fileSize', 'integer');
		$this->addType('uploadedAt', 'datetime');
	}

	public function jsonSerialize(): array {
		return [
			'id'         => $this->id,
			'journalId'  => $this->journalId,
			'fileName'   => $this->fileName,
			'mimeType'   => $this->mimeType,
			'fileSize'   => $this->fileSize,
			'uploadedAt' => $this->uploadedAt?->format(\DateTime::ATOM),
		];
	}
}
