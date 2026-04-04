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
        $cached = Cache::get('mcjars.types');
        if ($cached !== null) {
            return $cached;
        }
        try {
            $response = Http::timeout(10)->get(self::API_BASE_URL . '/types');

            if (!$response->successful()) {
                return $this->getFallbackTypes();
            }

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

            $result = array_keys($types);
            Cache::put('mcjars.types', $result, $this->cacheDuration);
            return $result;
        } catch (\Exception $e) {
            // Silently handle error
        }

        return $this->getFallbackTypes();
    }

    private function getFallbackTypes(): array
    {
        return ['VANILLA', 'PAPER', 'PURPUR', 'SPIGOT', 'FABRIC', 'FORGE', 'NEOFORGE'];
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

        $cached = Cache::get("mcjars.versions.{$typeUpper}");
        if ($cached !== null) {
            return $cached;

        }

        try {
            $response = Http::timeout(10)->get(self::API_BASE_URL . "/builds/{$typeUpper}");

            if (!$response->successful()) {
                return [];
            }

            $data = $response->json();

            if (!isset($data['success']) || !$data['success']) {
                return [];
            }

            $versions = [];
            $builds = $data['builds'] ?? [];

            foreach ($builds as $versionId => $versionData) {
                if (isset($versionData['supported']) && $versionData['supported']) {
                    $versionType = $versionData['type'] ?? 'RELEASE';
                    if ($versionType === 'RELEASE') {
                        $versions[] = $versionId;
                    }
                }
            }

            $result = array_reverse($versions);
            Cache::put("mcjars.versions.{$typeUpper}", $result, $this->cacheDuration);
            return $result;
        } catch (\Exception $e) {
            // Silently handle error
        }

        return [];
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

        $cached = Cache::get("mcjars.builds.{$typeUpper}.{$version}");
        if ($cached !== null) {
            return $cached;
        }

        try {
            $response = Http::timeout(10)->get(self::API_BASE_URL . "/builds/{$typeUpper}/{$version}");

            if (!$response->successful()) {
                return [];
            }

            $data = $response->json();

            if (!isset($data['success']) || !$data['success']) {
                return [];
            }

            $builds = $data['builds'] ?? [];

            // Return builds in reverse order (newest first)
            $result = array_reverse($builds);
            Cache::put("mcjars.builds.{$typeUpper}.{$version}", $result, $this->cacheDuration);
            return $result;
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

        try {
            $builds = $this->getBuilds($type, $version);
            if ($builds === []) {
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

            if ($projectVersionId !== null && $selectedBuild === null) {
                return null;
            }

            // If no specific build was requested, use latest build
            if ($selectedBuild === null) {
                $selectedBuild = array_values($builds)[0] ?? null;
            }

            return $selectedBuild['zipUrl'] ?? $selectedBuild['jarUrl'] ?? null;

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
            'neoforge' => 'NEOFORGE',
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
        $cachedTypes = Cache::get('mcjars.types', $this->getFallbackTypes());
        Cache::forget('mcjars.types');
        foreach ($cachedTypes as $type) {
            $versions = Cache::get("mcjars.versions.{$type}", []);
            Cache::forget("mcjars.versions.{$type}");
            foreach ($versions as $version) {
                Cache::forget("mcjars.builds.{$type}.{$version}");
            }
        }
    }
}