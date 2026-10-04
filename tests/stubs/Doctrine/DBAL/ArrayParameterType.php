<?php

declare(strict_types=1);

namespace Doctrine\DBAL;

/**
 * Lückenfüller für die Unit-Tests, kein Bestandteil der App – siehe
 * {@see ParameterType}. Werte wie in DBAL 3 (Parametertyp + 100).
 */
final class ArrayParameterType {
	public const INTEGER = 101;
	public const STRING = 102;
	public const ASCII = 117;
	public const BINARY = 116;
}
