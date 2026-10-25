<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Exception;

/**
 * Eine Rechnung lässt sich noch nicht festschreiben, weil Pflichtangaben
 * fehlen. Die Einzelpunkte stehen in {@see getProblems()}, die Oberfläche
 * zeigt sie als Liste.
 */
class InvoiceNotIssuableException extends \InvalidArgumentException {

	/** @param list<string> $problems */
	public function __construct(string $message, private array $problems = []) {
		parent::__construct($message);
	}

	/** @return list<string> */
	public function getProblems(): array {
		return $this->problems;
	}
}
