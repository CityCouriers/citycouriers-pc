<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

/**
 * Przyczyna końca biegu przekazywana fazie `finalize`.
 */
enum EndCause: string
{
    case Completed = 'completed';
    case GateException = 'gate_exception';
    case Interrupted = 'interrupted';
    case EngineError = 'engine_error';
}
