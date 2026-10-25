<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\EInvoice;

/**
 * Eine E-Rechnung lässt sich nicht erzeugen. Die Einzelpunkte stehen in
 * {@see getProblems()}; die Oberfläche zeigt sie als Liste.
 */
class EInvoiceException extends \RuntimeException {

	/** @param list<string> $problems */
	public function __construct(
		string $message,
		private array $problems = [],
		?\Throwable $previous = null,
	) {
		parent::__construct($message, 0, $previous);
	}

	/** @return list<string> */
	public function getProblems(): array {
		return $this->problems;
	}
}
