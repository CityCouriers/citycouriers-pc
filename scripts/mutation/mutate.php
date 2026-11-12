<?php

declare(strict_types=1);

// Mutacje Pest Mutate w repo pc (#60): `composer mutate`. Opis: README, sekcja Rozwój, i scripts/mutation/src/Mutate.php.

use CityCouriers\Pc\Dev\Mutation\Mutate;

$root = dirname(__DIR__, 2);

require $root . '/vendor/autoload.php';

exit((new Mutate($root))->main(array_slice($argv, 1)));
