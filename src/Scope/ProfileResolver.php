<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Scope;

/**
 * Jedyne miejsce, które zamienia tekst na profil: brak i nieznana wartość dają `enforce-all` (fail-closed), więc literówka
 * w nazwie profilu nigdy nie zwęża zakresu.
 */
final class ProfileResolver
{
    public function resolve(?string $value): Profile
    {
        return Profile::tryFrom((string) $value) ?? Profile::EnforceAll;
    }

    public function isKnown(string $value): bool
    {
        return Profile::tryFrom($value) !== null;
    }
}
