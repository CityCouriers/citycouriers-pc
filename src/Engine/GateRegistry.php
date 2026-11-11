<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

use CityCouriers\Pc\Config\ConfigValidator;

/**
 * Rejestr zaimplementowanych bramek. Kolejność wykonania wynika z kanonicznej listy nazw (`ConfigValidator::GATES`,
 * zgodnej z docs/bramki.md), a nie z kolejności kluczy w `pc.json`; bramki wstępne biegną jako pierwsze.
 */
final class GateRegistry
{
    /** @var array<string, Gate> */
    private array $gates = [];

    /**
     * @throws EngineException
     */
    public function __construct(Gate ...$gates)
    {
        foreach ($gates as $gate) {
            $this->register($gate);
        }
    }

    public function get(string $name): ?Gate
    {
        return $this->gates[$name] ?? null;
    }

    /**
     * Kolejność wykonania włączonych bramek: najpierw wstępne, potem reszta, każda grupa w kolejności kanonicznej.
     *
     * @param list<string> $enabled
     *
     * @return list<string>
     */
    public function plan(array $enabled): array
    {
        $ordered = array_values(array_filter(ConfigValidator::GATES, static fn(string $name): bool => in_array($name, $enabled, true)));
        $isFirst = fn(string $name): bool => ($this->gates[$name] ?? null)?->precondition() === true;

        return [
            ...array_values(array_filter($ordered, $isFirst)),
            ...array_values(array_filter($ordered, static fn(string $name): bool => !$isFirst($name))),
        ];
    }

    /**
     * @throws EngineException
     */
    private function register(Gate $gate): void
    {
        $name = $gate->name();
        if (!in_array($name, ConfigValidator::GATES, true)) {
            throw new EngineException(sprintf('Bramka „%s” nie jest znaną nazwą z kontraktu konfiguracji.', $name));
        }
        if (isset($this->gates[$name])) {
            throw new EngineException(sprintf('Bramka „%s” jest zarejestrowana dwa razy.', $name));
        }
        if ($gate->precondition() && $gate->mode() !== GateMode::Blocking) {
            throw new EngineException(sprintf('Bramka „%s”: tylko bramka blokująca może być wstępna.', $name));
        }

        $this->gates[$name] = $gate;
    }
}
