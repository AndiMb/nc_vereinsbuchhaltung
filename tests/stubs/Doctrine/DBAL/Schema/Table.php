<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema;

/**
 * Lückenfüller für die Unit-Tests, kein Bestandteil der App.
 *
 * ISchemaWrapper::getTable() liefert eine Doctrine-Tabelle, das Stub-Paket
 * nextcloud/ocp bringt DBAL aber nicht mit. Nur die Methoden, die
 * Migrationstests mocken (Version000163MigrationTest), Signaturen wie in
 * DBAL 3.
 *
 * Wird ausschließlich vom Autoloader in tests/bootstrap.php eingebunden.
 */
class Table {
	public function hasColumn(string $name): bool {
		return false;
	}

	public function getColumn(string $name): Column {
		return new Column();
	}

	/** @param array<string, mixed> $options */
	public function changeColumn(string $name, array $options): self {
		return $this;
	}
}
