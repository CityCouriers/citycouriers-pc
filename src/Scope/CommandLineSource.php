<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Scope;

/**
 * Źródło argumentów procesu o danym identyfikatorze (proces nadrzędny hooka). `null` oznacza „nie da się ustalić”
 * (brak źródła na platformie, brak procesu, brak uprawnień), co daje tryb `Unknown`.
 */
interface CommandLineSource
{
    /**
     * @return list<string>|null
     */
    public function argv(int $pid): ?array;
}
