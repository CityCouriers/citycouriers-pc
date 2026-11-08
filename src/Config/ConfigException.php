<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Config;

use RuntimeException;

/**
 * Plik konfiguracji konsumenta nie daje się odczytać albo nie jest obiektem JSON.
 */
final class ConfigException extends RuntimeException {}
