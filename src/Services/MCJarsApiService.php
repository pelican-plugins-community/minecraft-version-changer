<?php

namespace Olivier\MinecraftVersionChanger\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MCJarsApiService
{
    private const API_BASE_URL = 'https://mcjars.app/api/v2';
    private int $cacheDuration;

    public function __construct()
    {
        $this->cacheDuration = config('minecraft-version-changer.cache_duration', 3600);
    }

    /**
     * Get available server types from MCJars API
     */
    public function getServerTypes(): array
    {
        return Cache::remember('mcjars.types', $this->cacheDuration, function () {
            try {
                $response = Http::timeout(10)->get(self::API_BASE_URL . '/types');
                
                if ($response->successful()) {
                    $data = $response->json();
                    
                    if (!isset($data['success']) || !$data['success']) {
                        return $this->getFallbackTypes();
                    }

                    $types = [];
                    $categories = $data['types'] ?? [];
                    
                    foreach (['recommended', 'established'] as $category) {
                        if (isset($categories[$category])) {
                            foreach ($categories[$category] as $key => $typeData) {
                                if (!($typeData['deprecated'] ?? false)) {
                                    $types[strtoupper($key)] = $typeData['name'] ?? $key;
                                }
                            }
                        }
                    }

                    return array_keys($types);
                }
            } catch (\Exception $e) {
                // Silently handle error
            }

            return $this->getFallbackTypes();
        });
    }

    private function getFallbackTypes(): array
    {
        return ['VANILLA', 'PAPER', 'PURPUR', 'SPIGOT', 'FABRIC', 'FORGE'];
    }

    /**
     * Get available versions for a specific server type from MCJars API
     */
    public function getVersions(?string $type): array
    {
        if ($type === null) {
            return [];
        }
        
        $typeUpper = strtoupper($type);
        
        return Cache::remember("mcjars.versions.{$typeUpper}", $this->cacheDuration, function () use ($typeUpper) {
            try {
                $response = Http::timeout(10)->get(self::API_BASE_URL . "/builds/{$typeUpper}");
                
                if ($response->successful()) {
                    $data = $response->json();
                    
                    if (!isset($data['success']) || !$data['success']) {
                        return [];
                    }

                    $versions = [];
                    $builds = $data['builds'] ?? [];
                    
                    foreach ($builds as $versionId => $versionData) {
                        if (isset($versionData['supported']) && $versionData['supported']) {
                            $type = $versionData['type'] ?? 'RELEASE';
                            if ($type === 'RELEASE') {
                                $versions[] = $versionId;
                            }
                        }
                    }

                    return array_reverse($versions);
                }
            } catch (\Exception $e) {
                // Silently handle error
            }

            return [];
        });
    }

    /**
     * Get available builds for a specific server type and version from MCJars API
     */
    public function getBuilds(?string $type, ?string $version): array
    {
        if ($type === null || $version === null) {
            return [];
        }
        
        $typeUpper = strtoupper($type);
        
        try {
            $response = Http::timeout(10)->get(self::API_BASE_URL . "/builds/{$typeUpper}/{$version}");
            
            if ($response->successful()) {
                $data = $response->json();
                
                if (!isset($data['success']) || !$data['success']) {
                    return [];
                }

                $builds = $data['builds'] ?? [];
                
                // Return builds in reverse order (newest first)
                return array_reverse($builds);
            }
        } catch (\Exception $e) {
            // Silently handle error
        }

        return [];
    }

    /**
     * Get download URL for a specific version using MCJars API
     */
    public function getDownloadUrl(?string $type, ?string $version, ?string $projectVersionId = null): ?string
    {
        if ($type === null || $version === null) {
            return null;
        }
        
        $typeUpper = strtoupper($type);
        
        try {
            $response = Http::timeout(10)->get(self::API_BASE_URL . "/builds/{$typeUpper}/{$version}");
            
            if ($response->successful()) {
                $data = $response->json();
                
                if (!isset($data['success']) || !$data['success']) {
                    return null;
                }

                $builds = $data['builds'] ?? [];
                
                if (empty($builds)) {
                    return null;
                }

                // If projectVersionId is specified (for FORGE/NEOFORGE), find that specific build
                $selectedBuild = null;
                if ($projectVersionId !== null) {
                    foreach ($builds as $build) {
                        if (isset($build['projectVersionId']) && $build['projectVersionId'] == $projectVersionId) {
                            $selectedBuild = $build;
                            break;
                        }
                    }
                }
                
                // If no specific build found or requested, use latest build (first in reversed array)
                if ($selectedBuild === null) {
                    $selectedBuild = reset($builds);
                }
                
                // For FORGE, NEOFORGE and similar modded servers, use zipUrl instead of jarUrl
                // jarUrl is null for these types, zipUrl contains the installer
                $downloadUrl = $selectedBuild['zipUrl'] ?? $selectedBuild['jarUrl'] ?? null;
                
                if ($downloadUrl) {
                    return $downloadUrl;
                }
            }
        } catch (\Exception $e) {
            // Silently handle error
        }

        return null;
    }

    /**
     * Detect current server type from egg name/tags
     */
    public function detectServerType(\App\Models\Server $server): string
    {
        $eggName = strtolower($server->egg->name);
        $tags = array_map('strtolower', $server->egg->tags ?? []);
        
        $typeMap = [
            'paper' => 'PAPER',
            'purpur' => 'PURPUR',
            'fabric' => 'FABRIC',
            'forge' => 'FORGE',
            'spigot' => 'SPIGOT',
            'vanilla' => 'VANILLA',
            'folia' => 'FOLIA',
            'pufferfish' => 'PUFFERFISH',
        ];
        
        foreach ($typeMap as $keyword => $type) {
            if (str_contains($eggName, $keyword) || in_array($keyword, $tags)) {
                return $type;
            }
        }
        
        return 'VANILLA';
    }
    
    /**
     * Clear cache
     */
    public function clearCache(): void
    {
        Cache::forget('mcjars.types');
        
        $types = $this->getFallbackTypes();
        foreach ($types as $type) {
            Cache::forget("mcjars.versions.{$type}");
        }
    }
}
