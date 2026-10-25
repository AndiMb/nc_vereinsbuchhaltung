<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Erzeugtes Dokument zu einer festgeschriebenen Rechnung (PDF, später
 * ZUGFeRD-PDF und XRechnung-XML). Die Datei liegt im Nextcloud-Dateisystem,
 * hier stehen Verweis und SHA-256 als Nachweis der Unveränderlichkeit.
 *
 * @method int getInvoiceId()
 * @method void setInvoiceId(int $invoiceId)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getFileName()
 * @method void setFileName(string $fileName)
 * @method string getSha256()
 * @method void setSha256(string $sha256)
 * @method string|null getProfile()
 * @method void setProfile(?string $profile)
 * @method string getGeneratedAt()
 * @method void setGeneratedAt(string $generatedAt)
 */
class InvoiceDocument extends Entity implements \JsonSerializable {
	public const KIND_PDF = 'pdf';
	public const KIND_ZUGFERD_PDF = 'zugferd_pdf';
	public const KIND_XRECHNUNG_CII = 'xrechnung_cii';
	public const KIND_XRECHNUNG_UBL = 'xrechnung_ubl';
	public const KINDS = [self::KIND_PDF, self::KIND_ZUGFERD_PDF, self::KIND_XRECHNUNG_CII, self::KIND_XRECHNUNG_UBL];

	protected $invoiceId;
	protected $kind;
	protected $fileId;
	protected $fileName;
	protected $sha256;
	protected $profile;
	protected $generatedAt;

	public function __construct() {
		$this->addType('invoiceId', 'integer');
		$this->addType('fileId', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'invoiceId' => $this->invoiceId,
			'kind' => $this->kind,
			'fileId' => $this->fileId,
			'fileName' => $this->fileName,
			'sha256' => $this->sha256,
			'profile' => $this->profile,
			'generatedAt' => $this->generatedAt,
		];
	}
}
