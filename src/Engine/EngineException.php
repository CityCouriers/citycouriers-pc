<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

use RuntimeException;

/**
 * Błąd samego silnika (nie pojedynczej bramki): kod wyjścia 5.
 */
final class EngineException extends RuntimeException {}
