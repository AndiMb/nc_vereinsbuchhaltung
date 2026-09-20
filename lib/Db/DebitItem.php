<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Einzugsposten (Spec §2.2 „Einzugsposten (Debit Item)", Issue #71): der bei
 * der Freigabe eingefrorene Schnappschuss einer Forderung ({@see OpenItem},
 * verknüpft über `open_item_id`) – „Posten und XML-Zeile sind dieselbe
 * Wahrheit". Entsteht ausschließlich in
 * {@see \OCA\Vereinsbuchhaltung\Service\DebitBatchService::release()}, nie
 * einzeln.
 *
 * Kein eigenes Statusfeld: „ableitbar aus Lauf-Status + Rücklastschrift"
 * (Spec §2.2) – der Lauf-Status kommt über `batch_id` von {@see DebitBatch},
 * eine Rücklastschrift ist erst Issue #73.
 *
 * `amendment_indicator`/`original_debtor_account` transportieren einen zum
 * Freigabezeitpunkt noch offenen {@see MandateAmendment} (Kontowechsel) in
 * die pain.008-Zeile (Compliance-Anhang Spec §8: „DK empfiehlt SMNDA für
 * jeden Kontowechsel"). `original_debtor_account` trägt dabei immer nur den
 * festen Wert `SMNDA`, nie die tatsächliche alte IBAN – genau das verlangt
 * die Regel.
 *
 * `iban` ist seit Migration 000146 nullable, `account_holder` bleibt Pflicht
 * (Platzhalter {@see Mandate::REDACTED_ACCOUNT_HOLDER} statt NULL) – dieselbe
 * DSGVO-Anonymisierung (Spec §3.8, Issue #78) wie beim Mandat selbst, siehe
 * {@see \OCA\Vereinsbuchhaltung\Service\MemberAnonymizationService}. Ein
 * anonymisierter Einzugsposten verliert damit die „byte-identische
 * Nachrenderbarkeit" (Spec §3.5) seiner Bankdaten – laut Klassendoc dieser
 * Migration (000143) eine bewusst in Kauf genommene Folge: nach Ablauf der
 * Aufbewahrungsfrist zählt die DSGVO-Löschpflicht mehr als eine erneute
 * Byte-für-Byte-Reproduzierbarkeit eines Jahrzehnte alten Laufs.
 *
 * @method int getBatchId()
 * @method void setBatchId(int $batchId)
 * @method int getOpenItemId()
 * @method void setOpenItemId(int $openItemId)
 * @method int getMandateId()
 * @method void setMandateId(int $mandateId)
 * @method int getAmountCents()
 * @method void setAmountCents(int $amountCents)
 * @method string|null getIban()
 * @method void setIban(?string $iban)
 * @method string|null getBic()
 * @method void setBic(?string $bic)
 * @method string getAccountHolder()
 * @method void setAccountHolder(string $accountHolder)
 * @method string getMandateReference()
 * @method void setMandateReference(string $mandateReference)
 * @method string getSignedDate()
 * @method void setSignedDate(string $signedDate)
 * @method string getSequenceType()
 * @method void setSequenceType(string $sequenceType)
 * @method string getEndToEndId()
 * @method void setEndToEndId(string $endToEndId)
 * @method string getRemittanceInfo()
 * @method void setRemittanceInfo(string $remittanceInfo)
 * @method bool getAmendmentIndicator()
 * @method void setAmendmentIndicator(bool $amendmentIndicator)
 * @method string|null getOriginalDebtorAccount()
 * @method void setOriginalDebtorAccount(?string $originalDebtorAccount)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class DebitItem extends Entity implements \JsonSerializable {
	protected $batchId;
	protected $openItemId;
	protected $mandateId;
	protected $amountCents = 0;
	protected $iban;
	protected $bic;
	protected $accountHolder;
	protected $mandateReference;
	protected $signedDate;
	protected $sequenceType = self::SEQUENCE_TYPE;
	protected $endToEndId;
	protected $remittanceInfo;
	protected $amendmentIndicator = false;
	protected $originalDebtorAccount;
	protected $createdAt;

	/** Immer RCUR, nie FRST (Compliance-Anhang Spec §8), siehe {@see Mandate::SEQUENCE_TYPE}. */
	public const SEQUENCE_TYPE = 'RCUR';

	/** Fester SMNDA-Wert für `original_debtor_account` bei einem Kontowechsel-Amendment. */
	public const ORIGINAL_DEBTOR_ACCOUNT_SMNDA = 'SMNDA';

	public function __construct() {
		$this->addType('batchId', 'integer');
		$this->addType('openItemId', 'integer');
		$this->addType('mandateId', 'integer');
		$this->addType('amountCents', 'integer');
		$this->addType('amendmentIndicator', 'boolean');
	}

	/**
	 * IBAN mit maskierter Mitte, dieselbe Regel wie {@see Mandate::maskedIban()}
	 * – der Einzug-Unterreiter ist laut Spec §3.9 für `revisor` lesbar, aber nur
	 * mit maskierter IBAN, unabhängig von der tatsächlichen Rolle des Aufrufers
	 * (siehe {@see \OCA\Vereinsbuchhaltung\Controller\DebitBatchController}).
	 *
	 * Null nach einer DSGVO-Anonymisierung (siehe Klassendoc) – dann gibt es
	 * nichts mehr zu maskieren.
	 */
	public function maskedIban(): ?string {
		if ($this->iban === null || $this->iban === '') {
			return $this->iban;
		}
		$len = strlen($this->iban);
		if ($len <= 8) {
			return str_repeat('•', $len);
		}
		return substr($this->iban, 0, 4) . str_repeat('•', $len - 8) . substr($this->iban, -4);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'batchId' => $this->batchId,
			'openItemId' => $this->openItemId,
			'mandateId' => $this->mandateId,
			'amountCents' => $this->amountCents,
			'amount' => $this->amountCents / 100,
			'iban' => $this->maskedIban(),
			'bic' => $this->bic,
			'accountHolder' => $this->accountHolder,
			'mandateReference' => $this->mandateReference,
			'signedDate' => $this->signedDate,
			'sequenceType' => $this->sequenceType,
			'endToEndId' => $this->endToEndId,
			'remittanceInfo' => $this->remittanceInfo,
			'amendmentIndicator' => $this->amendmentIndicator,
			'originalDebtorAccount' => $this->originalDebtorAccount,
			'createdAt' => $this->createdAt,
		];
	}
}
