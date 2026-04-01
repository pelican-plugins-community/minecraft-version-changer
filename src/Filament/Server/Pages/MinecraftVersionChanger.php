<?php

namespace Olivier\MinecraftVersionChanger\Filament\Server\Pages;

use App\Enums\ContainerStatus;
use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select as FormSelect;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Http;
use Olivier\MinecraftVersionChanger\Services\MCJarsApiService;

/**
 * @property Schema $form
 */
class MinecraftVersionChanger extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithFormActions;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-package';
    
    protected string $view = 'minecraft-version-changer::version-changer';
    
    protected static ?int $navigationSort = 3;

    public ?array $data = [];
    public array $availableVersions = [];
    public array $availableBuilds = [];
    public array $serverTypes = [];

    private ?MCJarsApiService $apiService = null;

    public function mount(MCJarsApiService $apiService): void
    {
        $server = Filament::getTenant();
        
        abort_unless(user()?->can('minecraft-version-changer.view', $server), 403);
        
        $this->apiService = $apiService;
        $this->serverTypes = $this->apiService->getServerTypes();
        
        $defaultType = $this->apiService->detectServerType($server);
        $this->availableVersions = $this->apiService->getVersions($defaultType);
        
        $this->form->fill([
            'server_type' => $defaultType,
            'version' => null,
            'build' => null,
            'delete_all_files' => false,
        ]);
    }

    /**
     * @return Component[]
     */
    protected function getFormSchema(): array
    {
        return [
            Section::make('Change Minecraft Version')
                ->description('Download and install a different Minecraft server version')
                ->icon('tabler-download')
                ->schema([
                    Grid::make(2)
                        ->schema([
                            FormSelect::make('server_type')
                                ->label('Server Type')
                                ->options(function () {
                                    $options = [];
                                    foreach ($this->serverTypes as $type) {
                                        $options[$type] = ucfirst(strtolower($type));
                                    }
                                    return $options;
                                })
                                ->required()
                                ->live(onBlur: false)
                                ->afterStateUpdated(function ($state, $set) {
                                    if ($state === null) {
                                        $this->availableVersions = [];
                                        $set('version', null);
                                        $set('build', null);
                                        return;
                                    }
                                    
                                    $apiService = app(MCJarsApiService::class);
                                    $versions = $apiService->getVersions($state);
                                    $this->availableVersions = $versions;
                                    $set('version', null);
                                    $set('build', null);
                                }),
                            
                            FormSelect::make('version')
                                ->label('Version')
                                ->options(function ($get) {
                                    $serverType = $get('server_type');
                                    if (!$serverType) {
                                        return [];
                                    }
                                    $apiService = app(MCJarsApiService::class);
                                    $versions = $apiService->getVersions($serverType);
                                    return array_combine($versions, $versions);
                                })
                                ->searchable()
                                ->required()
                                ->live(onBlur: false)
                                ->placeholder('Select a version...')
                                ->helperText('Choose the Minecraft version you want to install')
                                ->disabled(fn ($get) => empty($get('server_type')))
                                ->afterStateUpdated(function ($state, $set, $get) {
                                    $serverType = $get('server_type');
                                    
                                    if ($state === null || $serverType === null) {
                                        $this->availableBuilds = [];
                                        $set('build', null);
                                        return;
                                    }
                                    
                                    if (in_array(strtoupper($serverType), ['FORGE', 'NEOFORGE'])) {
                                        $apiService = app(MCJarsApiService::class);
                                        $builds = $apiService->getBuilds($serverType, $state);
                                        $this->availableBuilds = $builds;
                                        $set('build', null);
                                    } else {
                                        $this->availableBuilds = [];
                                        $set('build', null);
                                    }
                                }),
                            
                            FormSelect::make('build')
                                ->label('Build Version')
                                ->options(function ($get) {
                                    $serverType = $get('server_type');
                                    $version = $get('version');
                                    
                                    if (!$serverType || !$version || !in_array(strtoupper($serverType), ['FORGE', 'NEOFORGE'])) {
                                        return [];
                                    }
                                    
                                    $apiService = app(MCJarsApiService::class);
                                    $builds = $apiService->getBuilds($serverType, $version);
                                    
                                    $options = [];
                                    foreach ($builds as $build) {
                                        $projectVersionId = $build['projectVersionId'] ?? null;
                                        $name = $build['name'] ?? null;
                                        
                                        if ($projectVersionId) {
                                            $label = $name ?? $projectVersionId;
                                            if (isset($build['experimental']) && $build['experimental']) {
                                                $label .= ' (Experimental)';
                                            }
                                            $options[$projectVersionId] = $label;
                                        }
                                    }
                                    
                                    return $options;
                                })
                                ->searchable()
                                ->live()
                                ->placeholder('Select a build...')
                                ->helperText('Choose the specific build number for this version')
                                ->visible(function ($get) {
                                    $serverType = $get('server_type');
                                    $version = $get('version');
                                    return $serverType && $version && in_array(strtoupper($serverType), ['FORGE', 'NEOFORGE']);
                                })
                                ->required(function ($get) {
                                    $serverType = $get('server_type');
                                    $version = $get('version');
                                    return $serverType && $version && in_array(strtoupper($serverType), ['FORGE', 'NEOFORGE']);
                                }),
                        ]),
                    
                    Toggle::make('delete_all_files')
                        ->label('Delete all files before installation')
                        ->helperText('WARNING: This will permanently delete ALL files in your server directory before installing the new version!')
                        ->inline(false)
                        ->default(false),
                ])
                ->footerActions([
                    Action::make('install')
                        ->label('Download & Install')
                        ->icon('tabler-download')
                        ->color('primary')
                        ->requiresConfirmation()
                        ->modalHeading('Change Minecraft Version?')
                        ->modalDescription(function ($get) {
                            $deleteAllFiles = $get('delete_all_files');
                            if ($deleteAllFiles) {
                                return 'WARNING: This will DELETE ALL FILES in your server directory, then download and install the new version. This action is IRREVERSIBLE! Make sure your server is stopped and you have a backup!';
                            }
                            return 'This will download and replace the server.jar file. Make sure your server is stopped!';
                        })
                        ->modalSubmitActionLabel(fn ($get) => $get('delete_all_files') ? 'Yes, DELETE ALL & Install' : 'Yes, install')
                        ->visible(function ($get) {
                            $serverType = $get('server_type');
                            $version = $get('version');
                            $build = $get('build');
                            
                            if (in_array(strtoupper($serverType ?? ''), ['FORGE', 'NEOFORGE'])) {
                                return !empty($version) && !empty($build);
                            }
                            
                            return !empty($version);
                        })
                        ->authorize(fn () => user()?->can('minecraft-version-changer.change', Filament::getTenant()))
                        ->action(function ($get, DaemonFileRepository $fileRepository) {
                            $server = Filament::getTenant();
                            
                            $status = $server->retrieveStatus();
                            
                            if ($status === ContainerStatus::Running || $status === ContainerStatus::Starting) {
                                Notification::make()
                                    ->title('Server must be stopped')
                                    ->body('Please stop the server before changing versions. Current status: ' . $status->name)
                                    ->danger()
                                    ->send();
                                return;
                            }

                            $serverType = $get('server_type');
                            $version = $get('version');
                            $build = $get('build');
                            $deleteAllFiles = $get('delete_all_files');
                            $apiService = app(MCJarsApiService::class);

                            try {
                                // Delete all files if option is checked
                                if ($deleteAllFiles) {
                                    Notification::make()
                                        ->title('Deleting all files...')
                                        ->body('Removing all files from the server directory')
                                        ->warning()
                                        ->send();
                                    
                                    try {
                                        // Get list of all files and directories
                                        $contents = $fileRepository->setServer($server)->getDirectory('/');
                                        
                                        $itemsToDelete = [];
                                        
                                        foreach ($contents as $item) {
                                            // Add all items to delete list (files and directories)
                                            if (isset($item['name'])) {
                                                $itemsToDelete[] = $item['name'];
                                            }
                                        }
                                        
                                        // Delete all items (the daemon will handle files and directories)
                                        if (!empty($itemsToDelete)) {
                                            $fileRepository->setServer($server)->deleteFiles('/', $itemsToDelete);
                                        }
                                        
                                        Notification::make()
                                            ->title('Files deleted')
                                            ->body('All server files have been removed')
                                            ->success()
                                            ->send();
                                    } catch (\Exception $deleteException) {
                                        Notification::make()
                                            ->title('Failed to delete files')
                                            ->body('Error: ' . $deleteException->getMessage())
                                            ->danger()
                                            ->send();
                                        return;
                                    }
                                }
                                $downloadUrl = $apiService->getDownloadUrl($serverType, $version, $build);
                                
                                if (!$downloadUrl) {
                                    Notification::make()
                                        ->title('Download failed')
                                        ->body('Could not get download URL from MCJars API')
                                        ->danger()
                                        ->send();
                                    return;
                                }

                                $isZipFile = str_ends_with($downloadUrl, '.zip');
                                
                                if ($isZipFile) {
                                    Notification::make()
                                        ->title('Downloading server package...')
                                        ->body("Downloading and extracting {$serverType} {$version} (includes libraries and dependencies)")
                                        ->info()
                                        ->send();

                                    $zipFileName = 'server_installer.zip';
                                    
                                    $fileRepository->setServer($server)->pull($downloadUrl, '/', [
                                        'filename' => $zipFileName,
                                        'foreground' => true,
                                    ]);
                                    
                                    $fileRepository->setServer($server)->decompressFile('/', $zipFileName);
                                    
                                    $fileRepository->setServer($server)->deleteFiles('/', [$zipFileName]);
                                    
                                    Notification::make()
                                        ->title('Installation completed')
                                        ->body("Installed {$serverType} {$version}. Server files, libraries and dependencies have been extracted.")
                                        ->success()
                                        ->send();
                                } else {
                                    Notification::make()
                                        ->title('Downloading server JAR...')
                                        ->body("Downloading {$serverType} {$version}")
                                        ->info()
                                        ->send();

                                    $fileRepository->setServer($server)->pull($downloadUrl, '/', ['filename' => 'server.jar']);
                                    
                                    Notification::make()
                                        ->title('Download started')
                                        ->body("Installing {$serverType} {$version}. The server.jar file will be replaced.")
                                        ->success()
                                        ->send();
                                }
                                    
                            } catch (\Exception $exception) {
                                report($exception);
                                
                                Notification::make()
                                    ->title('Download could not be started')
                                    ->body($exception->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),
                ]),
            
            Section::make('Important Information')
                ->icon('tabler-alert-triangle')
                ->iconColor('warning')
                ->schema([
                    TextEntry::make('warnings')
                        ->hiddenLabel()
                        ->state(fn () => "**Before changing versions:**\n\n• Stop your server completely\n• Backup all server files\n• Some versions may not be compatible with your current plugins/mods\n• World files may need conversion between major versions")
                        ->markdown()
                        ->color('warning'),
                ])
                ->collapsible()
                ->collapsed(false),
        ];
    }

    protected function getFormStatePath(): ?string
    {
        return 'data';
    }

    protected function getFormActions(): array
    {
        return [];
    }

    public static function getNavigationLabel(): string
    {
        return 'Minecraft Versions';
    }

    public static function shouldRegisterNavigation(): bool
    {
        $server = Filament::getTenant();
        
        if (!$server) {
            return false;
        }
        
        return \Olivier\MinecraftVersionChanger\Services\FeatureChecker::hasFeature($server);
    }

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();
        
        if (!$server) {
            return false;
        }
        
        return \Olivier\MinecraftVersionChanger\Services\FeatureChecker::hasFeature($server) 
            && user()?->can('minecraft-version-changer.view', $server);
    }

    public function getTitle(): string
    {
        return 'Minecraft Version Changer';
    }
}
