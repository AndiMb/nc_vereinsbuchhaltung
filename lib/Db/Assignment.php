<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Zuweisung – zeitraumbehaftete n:m-Kante Mitglied↔Beitragsgruppe, siehe Spec
 * §2.2 „Zuweisung (Assignment)". Der Zeitraum (validFrom/validTo) *ist* der
 * Status: eine Zuweisung ohne validTo (oder mit validTo in der Zukunft) ist
 * aktiv. Mehrere Zuweisungen pro Mitglied sind erlaubt (Basis + Sparte +
 * Förderbeitrag); keine zeitlich überlappende Doppelzuweisung zur selben
 * Gruppe (siehe AssignmentService::assertNoOverlap()).
 *
 * `minMonthlyAmountOverrideCents` ersetzt die Gruppen-Untergrenze **in beide
 * Richtungen**, wenn gesetzt – nur `buchhalter` darf ihn setzen
 * (AssignmentService::setMinAmountOverride()).
 *
 * Enum-Sprachentscheidung `paymentMethod` (siehe Assignment::PAYMENT_METHODS
 * und PR-Beschreibung): `direct_debit`/`ueberweisung` – Spec §13.1 nennt
 * dieses Paar wörtlich als Beispiel für „englisch wo Technik (SEPA-Fachwort),
 * deutsch wo Fachbegriff (Überweisung ist ein ganz normales deutsches Wort)".
 *
 * @method int getMemberId()
 * @method void setMemberId(int $memberId)
 * @method int getGroupId()
 * @method void setGroupId(int $groupId)
 * @method int getIntervalMonths()
 * @method void setIntervalMonths(int $intervalMonths)
 * @method int getMonthlyAmountCents()
 * @method void setMonthlyAmountCents(int $monthlyAmountCents)
 * @method int|null getMinMonthlyAmountOverrideCents()
 * @method void setMinMonthlyAmountOverrideCents(?int $minMonthlyAmountOverrideCents)
 * @method string|null getOverrideReason()
 * @method void setOverrideReason(?string $overrideReason)
 * @method string getPaymentMethod()
 * @method void setPaymentMethod(string $paymentMethod)
 * @method string getValidFrom()
 * @method void setValidFrom(string $validFrom)
 * @method string|null getValidTo()
 * @method void setValidTo(?string $validTo)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class Assignment extends Entity implements \JsonSerializable {
	protected $memberId;
	protected $groupId;
	protected $intervalMonths = 12;
	protected $monthlyAmountCents = 0;
	protected $minMonthlyAmountOverrideCents;
	protected $overrideReason;
	protected $paymentMethod = self::PAYMENT_METHOD_DIRECT_DEBIT;
	protected $validFrom;
	protected $validTo;
	protected $createdAt;

	public const PAYMENT_METHOD_DIRECT_DEBIT = 'direct_debit';
	public const PAYMENT_METHOD_TRANSFER = 'ueberweisung';
	public const PAYMENT_METHODS = [self::PAYMENT_METHOD_DIRECT_DEBIT, self::PAYMENT_METHOD_TRANSFER];

	public function __construct() {
		$this->addType('memberId', 'integer');
		$this->addType('groupId', 'integer');
		$this->addType('intervalMonths', 'integer');
		$this->addType('monthlyAmountCents', 'integer');
		$this->addType('minMonthlyAmountOverrideCents', 'integer');
	}

	/** „der Zeitraum ist der Status" – analog zu Member::isActive(). */
	public function isActive(?string $today = null): bool {
		$today ??= (new \DateTime())->format('Y-m-d');
		if ($this->validFrom > $today) {
			return false;
		}
		return $this->validTo === null || $this->validTo >= $today;
	}

	/** Die für diese Zuweisung geltende Untergrenze: Override ersetzt die Gruppen-Untergrenze vollständig. */
	public function effectiveMinMonthlyAmountCents(ContributionGroup $group): int {
		return $this->minMonthlyAmountOverrideCents ?? $group->getMinMonthlyAmountCents();
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'memberId' => $this->memberId,
			'groupId' => $this->groupId,
			'intervalMonths' => $this->intervalMonths,
			'monthlyAmountCents' => $this->monthlyAmountCents,
			'monthlyAmount' => $this->monthlyAmountCents / 100,
			'minMonthlyAmountOverrideCents' => $this->minMonthlyAmountOverrideCents,
			'minMonthlyAmountOverride' => $this->minMonthlyAmountOverrideCents !== null ? $this->minMonthlyAmountOverrideCents / 100 : null,
			'overrideReason' => $this->overrideReason,
			'paymentMethod' => $this->paymentMethod,
			'validFrom' => $this->validFrom,
			'validTo' => $this->validTo,
			'active' => $this->isActive(),
			'createdAt' => $this->createdAt,
		];
	}
}
