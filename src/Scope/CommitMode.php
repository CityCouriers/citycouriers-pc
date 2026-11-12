<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Scope;

/**
 * Tryb commita ustalony dla hooka `pre-commit` (git nie podaje go hookowi). `Plain` wolno wybrać tylko po dodatnim
 * potwierdzeniu (zamknięta allowlista opcji `git commit`); wszystko niepewne to `Unknown`, czyli baza szersza (`HEAD^`).
 */
enum CommitMode: string
{
    case Plain = 'plain';
    case Amend = 'amend';
    case Unknown = 'unknown';
}
