<?php

declare(strict_types=1);

final class BadSmell
{
    public function run(int $a): int
    {
        $unused = 1;

        return $a;
    }
}
