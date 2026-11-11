<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Tests\Support;

/**
 * Wspólny dziennik zdarzeń atrap ("run:format", "finalize:format:completed"), do asercji kolejności.
 */
final class EventLog
{
    /** @var list<string> */
    public array $lines = [];

    public function add(string $line): void
    {
        $this->lines[] = $line;
    }
}
