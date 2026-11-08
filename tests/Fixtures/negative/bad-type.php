<?php

declare(strict_types=1);

function badType(int $value): string
{
    return $value + 1;
}
