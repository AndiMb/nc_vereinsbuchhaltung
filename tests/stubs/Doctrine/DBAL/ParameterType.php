<?php

declare(strict_types=1);

namespace Doctrine\DBAL;

/**
 * Lückenfüller für die Unit-Tests, kein Bestandteil der App.
 *
 * OCP\DB\QueryBuilder\IQueryBuilder legt seine PARAM_*-Konstanten auf die von
 * Doctrine DBAL. Das Stub-Paket nextcloud/ocp bringt DBAL nicht mit; ohne diese
 * Klasse scheitert schon das Mocken von IDBConnection am ersten Zugriff auf
 * eine Konstante. Werte wie in DBAL 3 (dort eine Klasse mit Integer-Konstanten,
 * ab DBAL 4 ein Enum – OCP rechnet mit den Zahlen).
 *
 * Wird ausschließlich vom Autoloader in tests/bootstrap.php eingebunden.
 */
final class ParameterType {
	public const NULL = 0;
	public const INTEGER = 1;
	public const STRING = 2;
	public const LARGE_OBJECT = 3;
	public const BOOLEAN = 5;
	public const BINARY = 16;
	public const ASCII = 17;
}
