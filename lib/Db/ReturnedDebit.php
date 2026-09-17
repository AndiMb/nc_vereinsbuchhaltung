<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Rücklastschrift (Spec §2.2 „Rücklastschrift (Returned Debit)", Issue #72):
 * höchstens eine je {@see DebitItem} – der Unique-Index auf `debit_item_id`
 * (Migration 000144) ist der Posten-Guard aus Spec §3.6/§5 („max. 1
 * Rücklastschrift je Einzugsposten, Dubletten verpuffen still").
 *
 * `reasonCode`/`reasonText`: Codes bleiben admin-only (Spec §3.6 „Mitglieder-
 * Klartexte: Codes bleiben admin-only"), Freitext ist bei „unbekanntem" Grund
 * Pflicht. Die volle Ursache-Klassifikation (Rückgabe-Klassen, Mandats-Sperre,
 * Mahnwesen) folgt erst mit Issue #73 – hier reicht der einfache Ja/Nein-
 * Schalter {@see getFeeRechargeTriggered()} für die Gebühren-Weiterbelastung.
 *
 * @method int getDebitItemId()
 * @method void setDebitItemId(int $debitItemId)
 * @method int|null getBankTxSepaDetailId()
 * @method void setBankTxSepaDetailId(?int $bankTxSepaDetailId)
 * @method string|null getReasonCode()
 * @method void setReasonCode(?string $reasonCode)
 * @method string|null getReasonText()
 * @method void setReasonText(?string $reasonText)
 * @method string getReceivedAt()
 * @method void setReceivedAt(string $receivedAt)
 * @method string getSource()
 * @method void setSource(string $source)
 * @method int|null getChargesCents()
 * @method void setChargesCents(?int $chargesCents)
 * @method bool getFeeRechargeTriggered()
 * @method void setFeeRechargeTriggered(bool $feeRechargeTriggered)
 * @method int|null getFeeOpenItemId()
 * @method void setFeeOpenItemId(?int $feeOpenItemId)
 * @method int|null getJournalId()
 * @method void setJournalId(?int $journalId)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 */
class ReturnedDebit extends Entity implements \JsonSerializable {
	protected $debitItemId;
	protected $bankTxSepaDetailId;
	protected $reasonCode;
	protected $reasonText;
	protected $receivedAt;
	protected $source = self::SOURCE_IMPORT;
	protected $chargesCents;
	protected $feeRechargeTriggered = false;
	protected $feeOpenItemId;
	protected $journalId;
	protected $createdAt;
	protected $createdBy;

	/** Rücklastschrift-Quelle ist seit T12/T26 zweiwertig (kein "Integration" mehr, siehe Spec §13.2). */
	public const SOURCE_IMPORT = 'import';
	public const SOURCE_MANUAL = 'manual';
	public const SOURCES = [self::SOURCE_IMPORT, self::SOURCE_MANUAL];

	public function __construct() {
		$this->addType('debitItemId', 'integer');
		$this->addType('bankTxSepaDetailId', 'integer');
		$this->addType('chargesCents', 'integer');
		$this->addType('feeRechargeTriggered', 'boolean');
		$this->addType('feeOpenItemId', 'integer');
		$this->addType('journalId', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'debitItemId' => $this->debitItemId,
			'bankTxSepaDetailId' => $this->bankTxSepaDetailId,
			'reasonCode' => $this->reasonCode,
			'reasonText' => $this->reasonText,
			'receivedAt' => $this->receivedAt,
			'source' => $this->source,
			'chargesCents' => $this->chargesCents,
			'feeRechargeTriggered' => $this->feeRechargeTriggered,
			'feeOpenItemId' => $this->feeOpenItemId,
			'journalId' => $this->journalId,
			'createdAt' => $this->createdAt,
			'createdBy' => $this->createdBy,
		];
	}
}
