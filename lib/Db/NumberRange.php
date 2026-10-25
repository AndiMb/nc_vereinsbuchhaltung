<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Nummernkreis für fortlaufende Rechnungsnummern. Pro Art (`rechnung`,
 * `storno`) genau eine Zeile. Das Muster enthält Platzhalter `{JJJJ}` (Jahr)
 * und `{NNNN}` (Zähler, Länge über `padding`), z. B. `RE-{JJJJ}-{NNNN}`.
 *
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getPrefixPattern()
 * @method void setPrefixPattern(string $prefixPattern)
 * @method int getPadding()
 * @method void setPadding(int $padding)
 * @method string getResetMode()
 * @method void setResetMode(string $resetMode)
 * @method int getNextNo()
 * @method void setNextNo(int $nextNo)
 * @method string|null getCounterKey()
 * @method void setCounterKey(?string $counterKey)
 * @method string|null getLastIssuedAt()
 * @method void setLastIssuedAt(?string $lastIssuedAt)
 */
class NumberRange extends Entity implements \JsonSerializable {
	public const KIND_INVOICE = 'rechnung';
	public const KIND_CREDIT = 'storno';
	public const KINDS = [self::KIND_INVOICE, self::KIND_CREDIT];

	public const RESET_YEARLY = 'yearly';
	public const RESET_NEVER = 'never';
	public const RESET_MODES = [self::RESET_YEARLY, self::RESET_NEVER];

	protected $kind;
	protected $prefixPattern;
	protected $padding = 4;
	protected $resetMode = self::RESET_YEARLY;
	protected $nextNo = 1;
	protected $counterKey;
	protected $lastIssuedAt;

	public function __construct() {
		$this->addType('padding', 'integer');
		$this->addType('nextNo', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'kind' => $this->kind,
			'prefixPattern' => $this->prefixPattern,
			'padding' => $this->padding,
			'resetMode' => $this->resetMode,
			'nextNo' => $this->nextNo,
			'counterKey' => $this->counterKey,
			'lastIssuedAt' => $this->lastIssuedAt,
		];
	}
}
