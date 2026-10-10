<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Ein abgelehnter Zahlungseingangs-Vorschlag (Issue #105, Spec §2.2): „diese
 * Gutschrift passt NICHT auf diese Forderung". Hält das Urteil fest, damit
 * {@see \OCA\Vereinsbuchhaltung\Service\Sepa\IncomingPaymentMatchingService}
 * denselben Vorschlag nicht erneut macht. Siehe Migration 000153.
 *
 * @method int getBankTxId()
 * @method void setBankTxId(int $bankTxId)
 * @method int getOpenItemId()
 * @method void setOpenItemId(int $openItemId)
 * @method string|null getRejectedBy()
 * @method void setRejectedBy(?string $rejectedBy)
 * @method string getRejectedAt()
 * @method void setRejectedAt(string $rejectedAt)
 */
class IncomingPaymentRejection extends Entity {
	protected $bankTxId;
	protected $openItemId;
	protected $rejectedBy;
	protected $rejectedAt;

	public function __construct() {
		$this->addType('bankTxId', 'integer');
		$this->addType('openItemId', 'integer');
	}
}
