<?php

namespace app\plugin;

abstract class AbstractPluginProvider implements PluginProviderInterface
{
    public function __construct(
        protected array $definition = [],
        protected array $settings = [],
    ) {
    }

    public function definition(): array
    {
        return $this->definition;
    }

    public function settings(): array
    {
        return $this->settings;
    }

    public function boot(): void
    {
    }

    protected function setting(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->settings) ? $this->settings[$key] : $default;
    }
}
