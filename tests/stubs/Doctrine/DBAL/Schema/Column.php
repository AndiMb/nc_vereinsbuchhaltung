<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema;

/**
 * Lückenfüller für die Unit-Tests, kein Bestandteil der App – siehe Table.
 *
 * Wird ausschließlich vom Autoloader in tests/bootstrap.php eingebunden.
 */
class Column {
	public function getLength(): ?int {
		return null;
	}
}
