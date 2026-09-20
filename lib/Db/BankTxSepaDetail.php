<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * SEPA-Detail-Zeile eines Bankumsatzes (Spec §2.2/§5, Issue #72): additive
 * 1:n-Nebentabelle zu {@see BankTransaction} (**die bleibt unangetastet**,
 * siehe {@see \OCA\Vereinsbuchhaltung\Service\Statement\RowNormalizer}).
 * Eine Zeile je `TxDtls` (camt) bzw. je referenztragender Buchung (MT940/CSV,
 * dort 1:1 zur Buchung – siehe jeweiliger Parser).
 *
 * Trägt sowohl die extrahierten Rohdaten (End-to-End-Id, Mandatsreferenz,
 * Rückgabegrund, Beträge, GVC, Sammlerreferenz) als auch den Stand des
 * Bestätigungsvorgangs: „ein Bestätigungsvorgang je Bankumsatz, Einzelurteil
 * je Detail-Zeile" (Spec §5) – siehe {@see STATUS_*}.
 *
 * @method int getBankTxId()
 * @method void setBankTxId(int $bankTxId)
 * @method int getDetailIndex()
 * @method void setDetailIndex(int $detailIndex)
 * @method string|null getEndToEndId()
 * @method void setEndToEndId(?string $endToEndId)
 * @method string|null getMandateReference()
 * @method void setMandateReference(?string $mandateReference)
 * @method string|null getReturnReasonCode()
 * @method void setReturnReasonCode(?string $returnReasonCode)
 * @method string|null getReturnReasonText()
 * @method void setReturnReasonText(?string $returnReasonText)
 * @method int|null getOriginalAmountCents()
 * @method void setOriginalAmountCents(?int $originalAmountCents)
 * @method int|null getChargesCents()
 * @method void setChargesCents(?int $chargesCents)
 * @method string|null getGvc()
 * @method void setGvc(?string $gvc)
 * @method string|null getBatchReference()
 * @method void setBatchReference(?string $batchReference)
 * @method int getAmountCents()
 * @method void setAmountCents(int $amountCents)
 * @method bool getIsReturn()
 * @method void setIsReturn(bool $isReturn)
 * @method string getDetectionSource()
 * @method void setDetectionSource(string $detectionSource)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method int|null getDebitItemId()
 * @method void setDebitItemId(?int $debitItemId)
 * @method int|null getOpenItemId()
 * @method void setOpenItemId(?int $openItemId)
 * @method string|null getDecidedAt()
 * @method void setDecidedAt(?string $decidedAt)
 * @method string|null getDecidedBy()
 * @method void setDecidedBy(?string $decidedBy)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class BankTxSepaDetail extends Entity implements \JsonSerializable {
	protected $bankTxId;
	protected $detailIndex = 0;
	protected $endToEndId;
	protected $mandateReference;
	protected $returnReasonCode;
	protected $returnReasonText;
	protected $originalAmountCents;
	protected $chargesCents;
	protected $gvc;
	protected $batchReference;
	protected $amountCents = 0;
	protected $isReturn = false;
	protected $detectionSource = self::DETECTION_STRUCTURED;
	protected $status = self::STATUS_OPEN;
	protected $debitItemId;
	protected $openItemId;
	protected $decidedAt;
	protected $decidedBy;
	protected $createdAt;

	/** Strukturell aus camt/MT940/CSV-Feldern erkannt. */
	public const DETECTION_STRUCTURED = 'strukturiert';
	/** Referenzlose Formate (Spec §5): bestehende Text-Heuristik als dokumentierter Fallback. */
	public const DETECTION_TEXT_HEURISTIC = 'text_heuristik';

	/** Noch nicht beurteilt. */
	public const STATUS_OPEN = 'offen';
	/** Einem Einzugsposten (Rückgabe) oder einer Forderung (Zahlungseingang) zugeordnet. */
	public const STATUS_ASSIGNED = 'zugeordnet';
	/** Vorschlag geprüft und verworfen. */
	public const STATUS_REJECTED = 'abgelehnt';
	/** Kein Kandidat gefunden – Aufgabe „nicht zuordenbar" (Spec §5). */
	public const STATUS_UNMATCHED = 'nicht_zuordenbar';
	public const STATUSES = [self::STATUS_OPEN, self::STATUS_ASSIGNED, self::STATUS_REJECTED, self::STATUS_UNMATCHED];

	public function __construct() {
		$this->addType('bankTxId', 'integer');
		$this->addType('detailIndex', 'integer');
		$this->addType('originalAmountCents', 'integer');
		$this->addType('chargesCents', 'integer');
		$this->addType('amountCents', 'integer');
		$this->addType('isReturn', 'boolean');
		$this->addType('debitItemId', 'integer');
		$this->addType('openItemId', 'integer');
	}

	/** Ob dieser Vorschlag bereits beurteilt wurde (Grundlage für die Sammler-Regel, siehe Klassendoc). */
	public function isDecided(): bool {
		return $this->status !== self::STATUS_OPEN;
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'bankTxId' => $this->bankTxId,
			'detailIndex' => $this->detailIndex,
			'endToEndId' => $this->endToEndId,
			'mandateReference' => $this->mandateReference,
			'returnReasonCode' => $this->returnReasonCode,
			'returnReasonText' => $this->returnReasonText,
			'originalAmountCents' => $this->originalAmountCents,
			'chargesCents' => $this->chargesCents,
			'gvc' => $this->gvc,
			'batchReference' => $this->batchReference,
			'amountCents' => $this->amountCents,
			'amount' => $this->amountCents / 100,
			'isReturn' => $this->isReturn,
			'detectionSource' => $this->detectionSource,
			'status' => $this->status,
			'debitItemId' => $this->debitItemId,
			'openItemId' => $this->openItemId,
			'decidedAt' => $this->decidedAt,
			'decidedBy' => $this->decidedBy,
			'createdAt' => $this->createdAt,
		];
	}
}
