<?php

namespace app\plugin;

interface PluginProviderInterface
{
    public function definition(): array;

    public function settings(): array;

    public function boot(): void;
}
