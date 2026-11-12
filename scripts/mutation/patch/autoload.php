<?php

declare(strict_types=1);

// Podmiana klasy wtyczki Pest Mutate na lokalną kopię (powody w MutationTest.php obok). Plik jest w `autoload-dev.files`
// Composera, więc loader rejestruje się przy każdym `vendor/autoload.php` w tym repo, przed loaderem Composera.
spl_autoload_register(static function (string $class): void {
    if ($class === 'Pest\\Mutate\\MutationTest') {
        require __DIR__ . '/MutationTest.php';
    }
}, true, true);
