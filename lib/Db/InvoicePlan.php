<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Plan für wiederkehrende Rechnungen (Epic #168, Stufe 3): Empfänger, Positionen
 * und Kopfangaben, dazu Takt in Monaten, Beginn, Ende und das nächste Datum.
 * `lines` ist das JSON der Positionen in der Form, die der Editor sendet.
 *
 * @method string getName()
 * @method void setName(string $name)
 * @method int getDebtorId()
 * @method void setDebtorId(int $debtorId)
 * @method int getIntervalMonths()
 * @method void setIntervalMonths(int $intervalMonths)
 * @method string getStartDate()
 * @method void setStartDate(string $startDate)
 * @method string|null getEndDate()
 * @method void setEndDate(?string $endDate)
 * @method string|null getNextRun()
 * @method void setNextRun(?string $nextRun)
 * @method string getPeriodMode()
 * @method void setPeriodMode(string $periodMode)
 * @method string getLines()
 * @method void setLines(string $lines)
 * @method string|null getNoteHead()
 * @method void setNoteHead(?string $noteHead)
 * @method string|null getNoteFoot()
 * @method void setNoteFoot(?string $noteFoot)
 * @method string|null getPaymentTerms()
 * @method void setPaymentTerms(?string $paymentTerms)
 * @method int|null getDueDays()
 * @method void setDueDays(?int $dueDays)
 * @method string|null getBuyerReference()
 * @method void setBuyerReference(?string $buyerReference)
 * @method string|null getOrderReference()
 * @method void setOrderReference(?string $orderReference)
 * @method int getAutoIssue()
 * @method void setAutoIssue(int $autoIssue)
 * @method int getAutoSend()
 * @method void setAutoSend(int $autoSend)
 * @method int getActive()
 * @method void setActive(int $active)
 * @method int getRunCount()
 * @method void setRunCount(int $runCount)
 * @method string|null getLastRunAt()
 * @method void setLastRunAt(?string $lastRunAt)
 * @method int|null getLastInvoiceId()
 * @method void setLastInvoiceId(?int $lastInvoiceId)
 * @method string|null getLastError()
 * @method void setLastError(?string $lastError)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 */
class InvoicePlan extends Entity implements \JsonSerializable {
	/** Leistungszeitraum: der Takt, der mit dem Lauf beginnt (z. B. der Monat der Rechnung). */
	public const PERIOD_INTERVAL = 'interval';
	/** Leistungsdatum = Rechnungsdatum. */
	public const PERIOD_ISSUE_DATE = 'issue_date';
	public const PERIOD_MODES = [self::PERIOD_INTERVAL, self::PERIOD_ISSUE_DATE];

	/** Erlaubte Takte in Monaten: monatlich, vierteljährlich, halbjährlich, jährlich. */
	public const INTERVALS = [1, 3, 6, 12];

	protected $name;
	protected $debtorId;
	protected $intervalMonths = 1;
	protected $startDate;
	protected $endDate;
	protected $nextRun;
	protected $periodMode = self::PERIOD_INTERVAL;
	protected $lines = '[]';
	protected $noteHead;
	protected $noteFoot;
	protected $paymentTerms;
	protected $dueDays;
	protected $buyerReference;
	protected $orderReference;
	protected $autoIssue = 0;
	protected $autoSend = 0;
	protected $active = 1;
	protected $runCount = 0;
	protected $lastRunAt;
	protected $lastInvoiceId;
	protected $lastError;
	protected $createdAt;
	protected $createdBy;

	public function __construct() {
		$this->addType('debtorId', 'integer');
		$this->addType('intervalMonths', 'integer');
		$this->addType('dueDays', 'integer');
		$this->addType('autoIssue', 'integer');
		$this->addType('autoSend', 'integer');
		$this->addType('active', 'integer');
		$this->addType('runCount', 'integer');
		$this->addType('lastInvoiceId', 'integer');
	}

	/** @return list<array<string, mixed>> */
	public function linesData(): array {
		$data = json_decode($this->lines, true);
		return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'name' => $this->name,
			'debtorId' => $this->debtorId,
			'intervalMonths' => $this->intervalMonths,
			'startDate' => $this->startDate,
			'endDate' => $this->endDate,
			'nextRun' => $this->nextRun,
			'periodMode' => $this->periodMode,
			'lines' => $this->linesData(),
			'noteHead' => $this->noteHead,
			'noteFoot' => $this->noteFoot,
			'paymentTerms' => $this->paymentTerms,
			'dueDays' => $this->dueDays,
			'buyerReference' => $this->buyerReference,
			'orderReference' => $this->orderReference,
			'autoIssue' => $this->autoIssue === 1,
			'autoSend' => $this->autoSend === 1,
			'active' => $this->active === 1,
			'runCount' => $this->runCount,
			'lastRunAt' => $this->lastRunAt,
			'lastInvoiceId' => $this->lastInvoiceId,
			'lastError' => $this->lastError,
			'createdAt' => $this->createdAt,
			'createdBy' => $this->createdBy,
		];
	}
}
