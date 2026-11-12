<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Dev\Mutation;

/**
 * Wygenerowany mutant (wiersz `generated` dziennika). `file` jest względny wobec katalogu repo i ma separatory `/`.
 */
final readonly class Generated
{
    public function __construct(
        public string $id,
        public string $file,
        public string $mutator,
        public int $startLine,
        public int $endLine,
    ) {}

    /**
     * Krótka nazwa mutatora (bez przestrzeni nazw), jak w logu wtyczki.
     */
    public function mutatorName(): string
    {
        $position = strrpos($this->mutator, '\\');

        return $position === false ? $this->mutator : substr($this->mutator, $position + 1);
    }
}
