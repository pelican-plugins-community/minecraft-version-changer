<?php

namespace Olivier\MinecraftVersionChanger;

use Filament\Contracts\Plugin;
use Filament\Panel;

class MinecraftVersionChangerPlugin implements Plugin
{
    public function getId(): string
    {
        return 'minecraft-version-changer';
    }

    public function register(Panel $panel): void
    {
        if ($panel->getId() === 'server') {
            $panel->discoverPages(
                plugin_path($this->getId(), 'src/Filament/Server/Pages'),
                'Olivier\\MinecraftVersionChanger\\Filament\\Server\\Pages'
            );
        }
    }

    public function boot(Panel $panel): void
    {
        // Register views
        \Illuminate\Support\Facades\View::addNamespace(
            'minecraft-version-changer',
            plugin_path($this->getId(), 'resources/views')
        );
    }
}
