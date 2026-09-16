<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Beitragsgruppe – trägt das Regelwerk (Betrag, Untergrenze, erlaubte
 * Turnusse), siehe Spec §2.2 „Beitragsgruppe (Contribution Group)" unter
 * docs/beitraege-sepa-modul-spec.md. Bewusst **keine Historisierung**: was
 * ein Mitglied wann schuldete, steht in der Forderung (vbh_open_items), nicht
 * an der Gruppe. Löst das bisherige, flache `vbh_membership_fees` fachlich ab
 * (siehe Migration 000138) – die alte Tabelle bleibt unangetastet stehen.
 *
 * `allowedIntervals` speichert eine Teilmenge von {1,2,3,4,6,12} (Teiler von
 * 12, siehe auch {@see \OCA\Vereinsbuchhaltung\Service\PeriodRule::LENGTHS}
 * für dasselbe Muster bei Geschäftsjahren) als sortierte Komma-Liste in der
 * Datenbank – {@see getAllowedIntervalsArray()}/{@see setAllowedIntervalsArray()}
 * sind die eigentliche Schnittstelle dafür.
 *
 * @method string getName()
 * @method void setName(string $name)
 * @method int getMinMonthlyAmountCents()
 * @method void setMinMonthlyAmountCents(int $minMonthlyAmountCents)
 * @method int getDefaultMonthlyAmountCents()
 * @method void setDefaultMonthlyAmountCents(int $defaultMonthlyAmountCents)
 * @method string getAllowedIntervals()
 * @method void setAllowedIntervals(string $allowedIntervals)
 * @method int getDefaultInterval()
 * @method void setDefaultInterval(int $defaultInterval)
 * @method bool getIsActive()
 * @method void setIsActive(bool $isActive)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class ContributionGroup extends Entity implements \JsonSerializable {
	protected $name;
	protected $minMonthlyAmountCents = 0;
	protected $defaultMonthlyAmountCents = 0;
	protected $allowedIntervals = '1,12';
	protected $defaultInterval = 12;
	protected $isActive = true;
	protected $createdAt;

	/** Erlaubte Turnuswerte – Teiler von 12 (siehe PeriodRule::LENGTHS). */
	public const VALID_INTERVALS = [1, 2, 3, 4, 6, 12];

	public function __construct() {
		$this->addType('minMonthlyAmountCents', 'integer');
		$this->addType('defaultMonthlyAmountCents', 'integer');
		$this->addType('defaultInterval', 'integer');
		$this->addType('isActive', 'boolean');
	}

	/** @return int[] aufsteigend sortiert, ohne Duplikate */
	public function getAllowedIntervalsArray(): array {
		if ($this->allowedIntervals === null || $this->allowedIntervals === '') {
			return [];
		}
		$values = array_map('intval', explode(',', $this->allowedIntervals));
		sort($values);
		return array_values(array_unique($values));
	}

	/** @param int[] $intervals */
	public function setAllowedIntervalsArray(array $intervals): void {
		$values = array_values(array_unique(array_map('intval', $intervals)));
		sort($values);
		$this->setAllowedIntervals(implode(',', $values));
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'name' => $this->name,
			'minMonthlyAmountCents' => $this->minMonthlyAmountCents,
			'minMonthlyAmount' => $this->minMonthlyAmountCents / 100,
			'defaultMonthlyAmountCents' => $this->defaultMonthlyAmountCents,
			'defaultMonthlyAmount' => $this->defaultMonthlyAmountCents / 100,
			'allowedIntervals' => $this->getAllowedIntervalsArray(),
			'defaultInterval' => $this->defaultInterval,
			'isActive' => $this->isActive,
			'createdAt' => $this->createdAt,
		];
	}
}
