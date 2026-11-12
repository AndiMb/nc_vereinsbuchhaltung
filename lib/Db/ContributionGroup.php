<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Beitragsgruppe – trägt das Regelwerk (Betrag, Untergrenze, erlaubte
 * Turnusse), siehe Spec §2.2 „Beitragsgruppe (Contribution Group)" unter
 * docs/beitraege-sepa-modul-spec.md. Bewusst **keine Historisierung**: was
 * ein Mitglied wann schuldete, steht in der Forderung (vbh_open_items), nicht
 * an der Gruppe. Löste das frühere, flache `vbh_membership_fees` fachlich ab
 * (siehe Migration 000138); die alte Tabelle fiel mit Migration 000157 weg.
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
 * @method int|null getBookingCcId()
 * @method void setBookingCcId(?int $bookingCcId)
 * @method string|null getAmountBasis()
 * @method void setAmountBasis(string $amountBasis)
 * @method bool|null getProrate()
 * @method void setProrate(bool $prorate)
 * @method int|null getMinAnnualAmountCents()
 * @method void setMinAnnualAmountCents(?int $minAnnualAmountCents)
 * @method int|null getDefaultAnnualAmountCents()
 * @method void setDefaultAnnualAmountCents(?int $defaultAnnualAmountCents)
 */
class ContributionGroup extends Entity implements \JsonSerializable {
	// Bewusst ohne "realistische" Standardwerte (siehe Entity::setter():
	// `if ($args[0] === $this->$name) { return; }` markiert ein Feld nur als
	// geaendert, wenn sich der Wert vom aktuellen PHP-Property-Wert
	// unterscheidet). QBMapper::insert() schreibt nur als geaendert markierte
	// Felder in die INSERT-Anweisung - ein neu angelegtes Objekt, dessen
	// Setter zufaellig denselben Wert wie der Klassen-Default bekommt (z.B.
	// allowedIntervals='1,12', defaultInterval=12 - beides die verbreitetsten
	// Werte ueberhaupt), wuerde diese Spalte sonst stillschweigend aus dem
	// INSERT auslassen und an der NOT-NULL-Constraint scheitern (beobachtet:
	// SQLSTATE[23000] NOT NULL constraint failed: allowed_intervals). Alle
	// Felder hier werden von ContributionGroupService::applyFields() vor
	// jedem insert()/update() explizit gesetzt, ein Default von null ist
	// deshalb gefahrlos und schliesst die Kollision aus.
	protected $name;
	protected $minMonthlyAmountCents;
	protected $defaultMonthlyAmountCents;
	protected $allowedIntervals;
	protected $defaultInterval;
	protected $isActive;
	protected $createdAt;
	// bookingCcId setzt applyFields() nicht: die Spalte ist nullable, ein nicht
	// gesetzter Wert fehlt im INSERT und bleibt NULL.
	protected $bookingCcId;
	// Betragsangabe (Issue #270): 'month' (Vorgabe der Spalte) oder 'year'.
	// Die Jahresspalten sind genau bei Jahresgruppen gefüllt; die
	// Monatsspalten tragen dort den gerundeten Zwölftel-Wert.
	protected $amountBasis;
	protected $prorate;
	protected $minAnnualAmountCents;
	protected $defaultAnnualAmountCents;

	public const BASIS_MONTH = 'month';
	public const BASIS_YEAR = 'year';
	public const AMOUNT_BASES = [self::BASIS_MONTH, self::BASIS_YEAR];

	/** Erlaubte Turnuswerte – Teiler von 12 (siehe PeriodRule::LENGTHS). */
	public const VALID_INTERVALS = [1, 2, 3, 4, 6, 12];

	public function __construct() {
		$this->addType('minMonthlyAmountCents', 'integer');
		$this->addType('defaultMonthlyAmountCents', 'integer');
		$this->addType('defaultInterval', 'integer');
		$this->addType('isActive', 'boolean');
		$this->addType('bookingCcId', 'integer');
		$this->addType('prorate', 'boolean');
		$this->addType('minAnnualAmountCents', 'integer');
		$this->addType('defaultAnnualAmountCents', 'integer');
	}

	/** Gibt die Gruppe ihre Beträge pro Jahr an (Issue #270)? Ohne Angabe: pro Monat. */
	public function isAnnual(): bool {
		return $this->amountBasis === self::BASIS_YEAR;
	}

	/** Wird ein angebrochenes Beitragsjahr anteilig berechnet? Nur bei Jahresgruppen von Belang; ohne Angabe: ja. */
	public function prorates(): bool {
		return $this->prorate !== false;
	}

	/** Untergrenze in der Betragsangabe der Gruppe: Jahresbetrag bei Jahresgruppen, sonst Monatsbetrag. */
	public function minAmountCents(): int {
		return $this->isAnnual() ? (int)$this->minAnnualAmountCents : (int)$this->minMonthlyAmountCents;
	}

	/** Standardbeitrag in der Betragsangabe der Gruppe. */
	public function defaultAmountCents(): int {
		return $this->isAnnual() ? (int)$this->defaultAnnualAmountCents : (int)$this->defaultMonthlyAmountCents;
	}

	/**
	 * Vorgabe-Kostenstelle (vbh_booking_cc.id, Issue #333): Beiträge dieser
	 * Gruppe werden beim Verbuchen des Einzugs ihr zugeordnet.
	 */
	public function getBookingCostCenterId(): ?int {
		return $this->bookingCcId !== null ? (int)$this->bookingCcId : null;
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
			'bookingCostCenterId' => $this->getBookingCostCenterId(),
			'amountBasis' => $this->isAnnual() ? self::BASIS_YEAR : self::BASIS_MONTH,
			'prorate' => $this->prorates(),
			'minAnnualAmountCents' => $this->isAnnual() ? (int)$this->minAnnualAmountCents : null,
			'minAnnualAmount' => $this->isAnnual() ? (int)$this->minAnnualAmountCents / 100 : null,
			'defaultAnnualAmountCents' => $this->isAnnual() ? (int)$this->defaultAnnualAmountCents : null,
			'defaultAnnualAmount' => $this->isAnnual() ? (int)$this->defaultAnnualAmountCents / 100 : null,
		];
	}
}
