<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Scope;

/**
 * Wywołanie, które może zgłosić ostrzeżenie PHP (zły wzorzec, brak pliku, brak programu), bez operatora `@`:
 * ostrzeżenie jest połykane, a wynik `false` zostaje do obsłużenia przez wywołującego.
 */
final class Quiet
{
    /**
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     */
    public function call(callable $call): mixed
    {
        set_error_handler(static fn(): bool => true);
        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }
}
