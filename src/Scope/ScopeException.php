<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Scope;

use RuntimeException;

/**
 * Błąd klasyfikatora zakresu (zestaw reguł, git). Wywołujący zamienia go na pełny zestaw bramek (fail-closed).
 */
final class ScopeException extends RuntimeException {}
