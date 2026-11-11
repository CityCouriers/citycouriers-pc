<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

use CityCouriers\Pc\Config\ConfigException;

/**
 * Przygotowanie biegu w stałej kolejności: konfiguracja (kod 3), potem repozytorium i `--base` (kod 2).
 */
final readonly class RunPreparer
{
    public function __construct(private ContextFactory $contexts) {}

    /**
     * @throws ConfigException
     * @throws UsageException
     * @throws EngineException
     */
    public function prepare(RunOptions $options): PreparedRun
    {
        $config = (new RunConfigLoader())->load($options->config);

        return new PreparedRun($config->enabled, $this->contexts->create($options, $config->raw));
    }
}
