<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Dev\Mutation;

/**
 * Wynik mutanta z wtyczki Pest Mutate (wartości jak w `Pest\Mutate\Support\MutationTestResult`).
 * `None` to mutant, którego bieg nie dokończył (w logu wtyczki: „pending”).
 */
enum Status: string
{
    case Tested = 'tested';
    case Untested = 'untested';
    case Uncovered = 'uncovered';
    case Timeout = 'timeout';
    case None = 'none';

    /**
     * Nazwa kategorii w linii `Mutations:` logu wtyczki.
     */
    public function label(): string
    {
        return $this === self::None ? 'pending' : $this->value;
    }
}
