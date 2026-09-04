<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getNumber()
 * @method void setNumber(string $number)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getType()
 * @method void setType(string $type)
 * @method string|null getCategory()
 * @method void setCategory(?string $category)
 * @method bool getIsBank()
 * @method void setIsBank(bool $isBank)
 * @method bool getCountInTotal()
 * @method void setCountInTotal(bool $countInTotal)
 * @method bool getActive()
 * @method void setActive(bool $active)
 * @method int getOpeningBalanceCents()
 * @method void setOpeningBalanceCents(int $openingBalanceCents)
 * @method string|null getOpeningDate()
 * @method void setOpeningDate(?string $openingDate)
 * @method int|null getParentId()
 * @method void setParentId(?int $parentId)
 * @method string|null getSphere()
 * @method void setSphere(?string $sphere)
 * @method string|null getReserveKind()
 * @method void setReserveKind(?string $reserveKind)
 * @method string|null getIban()
 * @method void setIban(?string $iban)
 * @method int|null getCostCenterId()
 * @method void setCostCenterId(?int $costCenterId)
 */
class Account extends Entity implements \JsonSerializable, AccountNature {
	protected $userId;
	protected $number;
	protected $name;
	protected $type;
	protected $category;
	protected $isBank = false;
	/**
	 * Zaehlt dieses Geldkonto in den Geldbestand der Kopfzeile? Reine Anzeige –
	 * siehe countsInCashTotal().
	 */
	protected $countInTotal = true;
	protected $active = true;
	protected $openingBalanceCents = 0;
	protected $openingDate;
	protected $parentId;
	protected $sphere;
	protected $reserveKind;
	/** IBAN des Geldkontos – ordnet importierte Bankumsätze diesem Konto zu. */
	protected $iban;
	/**
	 * Frei vergebene Kostenstelle (vbh_costcenters.id) – nur im Kostenstellen-
	 * Modus 'manual' ausgewertet, siehe ReportService::costCenterReport().
	 */
	protected $costCenterId;

	/** Gültige Werte für die steuerliche Sphäre (Account::$sphere). */
	public const SPHERES = ['ideell', 'vermoegensverwaltung', 'zweckbetrieb', 'wirtschaftlich'];

	/** Gültige Werte für die Rücklagen-Art (Account::$reserveKind, § 62 AO). */
	public const RESERVE_KINDS = ['frei', 'zweckgebunden', 'wiederbeschaffung'];

	public function __construct() {
		$this->addType('isBank', 'boolean');
		$this->addType('countInTotal', 'boolean');
		$this->addType('active', 'boolean');
		$this->addType('openingBalanceCents', 'integer');
		$this->addType('parentId', 'integer');
		$this->addType('costCenterId', 'integer');
	}

	/**
	 * Ausdrueckliche Methode statt des geerbten __call-Getters: nur so laesst
	 * sich die ID in {@see AccountNature} zusagen. Der Wert ist derselbe.
	 */
	public function getId(): int {
		return (int)$this->id;
	}

	/**
	 * Bestandskonto: kumuliert seinen Saldo ueber Jahresgrenzen (Kontostand,
	 * Saldovortrag, Teil des Vermoegens). Das sind ausschliesslich die
	 * Geldkonten (Bank/Kasse, isBank). Alle anderen Konten – auch Aktiv-/
	 * Passivkonten wie Durchlauf-, Verrechnungs- oder Uebertragskonten –
	 * werden jahresbezogen ausgewertet; es gibt keine namens- oder
	 * flagbasierte Sonderbehandlung.
	 */
	public function isStockAccount(): bool {
		return (bool)$this->isBank;
	}

	/**
	 * Zaehlt in den Geldbestand, den die Kopfzeile als eine Zahl zeigt.
	 *
	 * Nur fuer Geldkonten sinnvoll, deshalb die Kopplung an isStockAccount():
	 * ein Aufwandskonto hat keinen Bestand, den man addieren koennte, und das
	 * Kennzeichen bleibt an einem Konto stehen, dem spaeter das Geldkonto-
	 * Kennzeichen abgenommen wurde (siehe AccountService::update()).
	 *
	 * Bewusst ohne Wirkung auf Kassenbericht, Vermoegensuebersicht und
	 * Saldenliste: dort muss jeder Euro auftauchen, auch der auf einem Konto,
	 * das in der Alltagszahl der Kopfzeile stoert.
	 */
	public function countsInCashTotal(): bool {
		return $this->isStockAccount() && (bool)$this->countInTotal;
	}

	/**
	 * Haben-Natur (Einnahmen-Seite): income/liability/equity.
	 * Soll-Natur (Ausgaben-Seite): expense/asset.
	 */
	public function isCreditNature(): bool {
		return in_array($this->type, ['income', 'liability', 'equity'], true);
	}

	/**
	 * Erfolgswirksam in Auswertungen: alle Konten ausser Geldkonten (Bestand,
	 * siehe isStockAccount()) und Eigenkapital (Eroeffnungsmechanik). Auch
	 * Aktiv-/Passivkonten ohne Geldkonto-Flag (z.B. Durchlauf-/Uebertrags-
	 * konten) zaehlen mit ihrer Jahresbewegung wie normale Einnahmen-/
	 * Ausgabenkonten: es gibt keine Sonderkonten ausser Bank/Kasse.
	 */
	public function isResultRelevant(): bool {
		return !$this->isStockAccount() && $this->type !== 'equity';
	}

	/**
	 * Kommt im Finanzplan vor.
	 *
	 * Enger gefasst als isResultRelevant(): geplant werden echte Einnahmen und
	 * Ausgaben. Ein Durchlauf- oder Uebertragskonto ist zwar erfolgswirksam,
	 * hat aber keinen Planwert und stuende nur als Nullzeile im Soll-Ist.
	 */
	public function isBudgetable(): bool {
		return $this->type === 'income' || $this->type === 'expense';
	}

	/**
	 * Wirtschaftlicher Geschäftsbetrieb: die steuerlich sensibelste Sphäre
	 * (Freigrenze, siehe ReportService::sphereReport()).
	 */
	public function isCommercialSphere(): bool {
		return $this->sphere === 'wirtschaftlich';
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'number' => $this->number,
			'name' => $this->name,
			'type' => $this->type,
			'category' => $this->category,
			'isBank' => $this->isBank,
			'countInTotal' => $this->countInTotal,
			'active' => $this->active,
			'openingBalanceCents' => $this->openingBalanceCents,
			'openingBalance' => $this->openingBalanceCents / 100,
			'openingDate' => $this->openingDate,
			'parentId' => $this->parentId,
			'sphere' => $this->sphere,
			'reserveKind' => $this->reserveKind,
			'iban' => $this->iban,
			'costCenterId' => $this->costCenterId,
		];
	}
}
