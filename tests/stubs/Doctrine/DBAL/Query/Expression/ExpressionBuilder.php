<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Query\Expression;

/**
 * Lückenfüller für die Unit-Tests, kein Bestandteil der App – siehe
 * {@see \Doctrine\DBAL\ParameterType}. Nur die Vergleichsoperatoren, auf die
 * OCP\DB\QueryBuilder\IExpressionBuilder seine Konstanten legt.
 */
final class ExpressionBuilder {
	public const EQ = '=';
	public const NEQ = '<>';
	public const LT = '<';
	public const LTE = '<=';
	public const GT = '>';
	public const GTE = '>=';
}
