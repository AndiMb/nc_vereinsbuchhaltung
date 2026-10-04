<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Types;

/**
 * Lückenfüller für die Unit-Tests, kein Bestandteil der App – siehe
 * {@see \Doctrine\DBAL\ParameterType}. Nur die Konstante, die
 * OCP\DB\QueryBuilder\IQueryBuilder braucht.
 */
final class Types {
	public const BOOLEAN = 'boolean';
}
