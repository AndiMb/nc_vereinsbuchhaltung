<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Eine ausgestellte Zuwendungsbestätigung (Sammelbestätigung über
 * Mitgliedsbeiträge eines Kalenderjahres, Issue #10) – das aufzubewahrende
 * Doppel nach § 50 Abs. 7 EStDV. Das PDF liegt wie ein Rechnungsdokument im
 * Ordner des Belegablage-Nutzers oder app-intern (`fileId` = 0).
 *
 * @method int getMemberId()
 * @method void setMemberId(int $memberId)
 * @method int getDebtorId()
 * @method void setDebtorId(int $debtorId)
 * @method string getReceiptType()
 * @method void setReceiptType(string $receiptType)
 * @method int getReceiptYear()
 * @method void setReceiptYear(int $receiptYear)
 * @method string getNumber()
 * @method void setNumber(string $number)
 * @method int getAmountCents()
 * @method void setAmountCents(int $amountCents)
 * @method string getPeriodFrom()
 * @method void setPeriodFrom(string $periodFrom)
 * @method string getPeriodTo()
 * @method void setPeriodTo(string $periodTo)
 * @method string getItems()
 * @method void setItems(string $items)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getFileName()
 * @method void setFileName(string $fileName)
 * @method string getSha256()
 * @method void setSha256(string $sha256)
 * @method string getIssuedAt()
 * @method void setIssuedAt(string $issuedAt)
 * @method string getIssuedBy()
 * @method void setIssuedBy(string $issuedBy)
 * @method string|null getVoidedAt()
 * @method void setVoidedAt(?string $voidedAt)
 * @method string|null getVoidedBy()
 * @method void setVoidedBy(?string $voidedBy)
 * @method string|null getVoidReason()
 * @method void setVoidReason(?string $voidReason)
 */
class DonationReceipt extends Entity implements \JsonSerializable {

	/** Sammelbestätigung über die Zuwendungen eines Kalenderjahres. */
	public const TYPE_COLLECTIVE = 'sammel';
	/** Bestätigung über eine einzelne Geldzuwendung. */
	public const TYPE_SINGLE = 'einzel';

	// Mitglied bzw. Debitor (externer Spender, Teil B) als Zuwendender, sonst 0.
	// Bewusst ohne Vorgabe 0: Entity::setter() übergeht einen unveränderten Wert,
	// setMemberId(0) käme sonst nicht ins INSERT (member_id hat keinen Default).
	protected $memberId;
	protected $debtorId;
	protected $receiptType = self::TYPE_COLLECTIVE;
	protected $receiptYear;
	protected $number;
	protected $amountCents;
	protected $periodFrom;
	protected $periodTo;
	protected $items;
	protected $fileId;
	protected $fileName;
	protected $sha256;
	protected $issuedAt;
	protected $issuedBy;
	protected $voidedAt;
	protected $voidedBy;
	protected $voidReason;

	public function __construct() {
		$this->addType('memberId', 'integer');
		$this->addType('debtorId', 'integer');
		$this->addType('receiptYear', 'integer');
		$this->addType('amountCents', 'integer');
		$this->addType('fileId', 'integer');
	}

	public function isVoided(): bool {
		return $this->voidedAt !== null;
	}

	public function isSingle(): bool {
		return $this->receiptType === self::TYPE_SINGLE;
	}

	/** @return array{0: string, 1: int} Art und Id des Zuwendenden ({@see Donation::DONOR_MEMBER}) */
	public function donor(): array {
		return $this->memberId > 0 ? [Donation::DONOR_MEMBER, (int)$this->memberId] : [Donation::DONOR_DEBTOR, (int)$this->debtorId];
	}

	/** Schlüssel einer bestätigten Position: `o:<OpenItem-Id>` (Beitrag) oder `j:<Buchungs-Id>` (Spende). */
	public static function itemKey(array $item): string {
		return isset($item['journalId']) ? 'j:' . (int)$item['journalId'] : 'o:' . (int)($item['openItemId'] ?? 0);
	}

	/**
	 * Die bestätigten Zahlungen: Beiträge mit `openItemId`, Spenden (Teil B)
	 * mit `journalId`, `type` (`beitrag`/`spende`) und `waiver`.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function itemList(): array {
		$items = json_decode((string)$this->items, true);
		return is_array($items) ? array_values($items) : [];
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'memberId' => $this->memberId,
			'debtorId' => $this->debtorId,
			'type' => $this->receiptType,
			'year' => $this->receiptYear,
			'number' => $this->number,
			'amountCents' => $this->amountCents,
			'periodFrom' => $this->periodFrom,
			'periodTo' => $this->periodTo,
			'items' => $this->itemList(),
			'fileName' => $this->fileName,
			'issuedAt' => $this->issuedAt,
			'issuedBy' => $this->issuedBy,
			'voidedAt' => $this->voidedAt,
			'voidedBy' => $this->voidedBy,
			'voidReason' => $this->voidReason,
		];
	}
}
