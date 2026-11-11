<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

/**
 * Bramka z fazą po biegu (reguła 7), dla bramek zmieniających dane użytkownika, które muszą je przywrócić.
 * Faza może tylko pogorszyć wynik (passed < not_scanned < failed) i nie jest przerywana ani objęta limitem czasu.
 */
interface FinalizingGate extends Gate
{
    public function finalize(GateContext $context, GateResult $result, EndCause $cause): GateResult;
}
