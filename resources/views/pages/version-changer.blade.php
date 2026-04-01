<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Server Type Selection --}}
        <div>
            <label class="text-sm font-medium text-gray-700 dark:text-gray-200">Server Type</label>
            <select 
                wire:model.live="serverType"
                class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200"
            >
                @foreach($serverTypes as $type)
                    <option value="{{ $type }}">{{ ucfirst($type) }}</option>
                @endforeach
            </select>
        </div>

        {{-- Version Selection --}}
        @if(!empty($availableVersions))
            <div>
                <label class="text-sm font-medium text-gray-700 dark:text-gray-200">Version</label>
                <select 
                    wire:model="selectedVersion"
                    class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200"
                >
                    <option value="">Select a version...</option>
                    @foreach($availableVersions as $version)
                        <option value="{{ $version }}">{{ $version }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Current Selection Info --}}
            @if($selectedVersion)
                <div class="rounded-lg bg-primary-50 dark:bg-primary-900/20 p-4">
                    <div class="flex items-center">
                        <x-filament::icon
                            icon="tabler-info-circle"
                            class="h-5 w-5 text-primary-600 dark:text-primary-400 mr-2"
                        />
                        <div>
                            <p class="text-sm font-medium text-primary-900 dark:text-primary-100">
                                Ready to download
                            </p>
                            <p class="text-sm text-primary-700 dark:text-primary-300">
                                {{ ucfirst($serverType) }} version {{ $selectedVersion }}
                            </p>
                        </div>
                    </div>
                </div>
            @endif
        @else
            <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-4 text-center">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Loading versions...
                </p>
            </div>
        @endif

        {{-- Warning Notice --}}
        <div class="rounded-lg bg-warning-50 dark:bg-warning-900/20 p-4">
            <div class="flex">
                <x-filament::icon
                    icon="tabler-alert-triangle"
                    class="h-5 w-5 text-warning-600 dark:text-warning-400 mr-2"
                />
                <div class="text-sm text-warning-700 dark:text-warning-300">
                    <p class="font-medium">Important</p>
                    <ul class="mt-2 list-disc list-inside space-y-1">
                        <li>Make sure to <strong>stop your server</strong> before changing versions</li>
                        <li>Backup your server files before proceeding</li>
                        <li>Some versions may not be compatible with your plugins/mods</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
