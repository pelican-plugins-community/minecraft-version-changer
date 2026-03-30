<?php

namespace Olivier\MinecraftVersionChanger\Providers;

use App\Models\Subuser;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class MinecraftVersionChangerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Subuser::registerCustomPermissions('minecraft-version-changer', [
            'view',
            'change',
        ], 'tabler-package', false);
        
        $this->mergeConfigFrom(
            plugin_path('Minecraft-Version-Changer', 'config/minecraft-version-changer.php'),
            'minecraft-version-changer'
        );
    }

    public function boot(): void
    {
        View::addNamespace(
            'minecraft-version-changer',
            plugin_path('Minecraft-Version-Changer', 'resources/views')
        );
    }
}
