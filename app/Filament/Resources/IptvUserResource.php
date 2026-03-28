<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IptvUserResource\Pages;
use App\Filament\Resources\IptvUserResource\RelationManagers;
use App\Models\IptvUser;
use App\Services\TuliproxService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Crypt;

class IptvUserResource extends Resource
{
    protected static ?string $model = IptvUser::class;
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('username')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                Forms\Components\TextInput::make('password')
                    ->password()
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('email')
                    ->email()
                    ->maxLength(255),
                Forms\Components\Select::make('package_id')
                    ->relationship('package', 'name')
                    ->label('Package'),
                Forms\Components\Select::make('m3u_source_id')
                    ->relationship('m3uSource', 'name')
                    ->label('M3U Source')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->createOptionForm([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->label('Source Name'),
                        Forms\Components\Radio::make('source_type')
                            ->label('Source Type')
                            ->options([
                                'url' => 'URL (Fetch from external source)',
                                'file' => 'File (Upload M3U file)',
                            ])
                            ->default('url')
                            ->required()
                            ->live(),
                        Forms\Components\Select::make('provider_type')
                            ->label('Source Provider')
                            ->options([
                                'none' => 'None (No Automation)',
                                'ugeen' => 'UGEEN',
                                'zazy' => 'ZAZY',
                                'custom' => 'Custom Script',
                            ])
                            ->default('none')
                            ->required()
                            ->live()
                            ->helperText('Select the source provider for subscription automation'),
                        Forms\Components\TextInput::make('provider_username')
                            ->label('Provider Username/Email')
                            ->helperText('The email or username used to login to the provider panel')
                            ->visible(fn (Forms\Get $get): bool => in_array($get('provider_type'), ['ugeen', 'zazy', 'custom']))
                            ->dehydrateStateUsing(fn ($state) => $state ? Crypt::encryptString($state) : null)
                            ->afterStateHydrated(function (Forms\Components\TextInput $component, $state) {
                                if ($state) {
                                    try {
                                        $component->state(Crypt::decryptString($state));
                                    } catch (\Exception $e) {
                                        $component->state('');
                                    }
                                }
                            })
                            ->password()
                            ->revealable(),
                        Forms\Components\TextInput::make('provider_password')
                            ->label('Provider Password')
                            ->helperText('The password used to login to the provider panel')
                            ->visible(fn (Forms\Get $get): bool => in_array($get('provider_type'), ['ugeen', 'zazy', 'custom']))
                            ->dehydrateStateUsing(fn ($state) => $state ? Crypt::encryptString($state) : null)
                            ->afterStateHydrated(function (Forms\Components\TextInput $component, $state) {
                                if ($state) {
                                    try {
                                        $component->state(Crypt::decryptString($state));
                                    } catch (\Exception $e) {
                                        $component->state('');
                                    }
                                }
                            })
                            ->password()
                            ->revealable(),
                        Forms\Components\KeyValue::make('provider_config')
                            ->label('Additional Configuration')
                            ->helperText('Provider-specific settings (e.g., package_id, api_key, etc.)')
                            ->visible(fn (Forms\Get $get): bool => in_array($get('provider_type'), ['ugeen', 'zazy', 'custom']))
                            ->keyLabel('Setting Key')
                            ->valueLabel('Setting Value')
                            ->default(['package_id' => '384']),
                        Forms\Components\TextInput::make('script_path')
                            ->label('Custom Script Path')
                            ->helperText('Path to Python script (leave empty to use default for provider type)')
                            ->visible(fn (Forms\Get $get): bool => $get('provider_type') === 'custom')
                            ->placeholder('/path/to/script.py'),
                        Forms\Components\Toggle::make('automation_enabled')
                            ->label('Enable Automation')
                            ->helperText('When enabled, users assigned to this source will have their subscriptions automatically renewed daily')
                            ->visible(fn (Forms\Get $get): bool => in_array($get('provider_type'), ['ugeen', 'zazy', 'custom']))
                            ->default(false),
                        Forms\Components\TextInput::make('url')
                            ->url()
                            ->maxLength(255)
                            ->label('M3U URL')
                            ->helperText('Enter the full URL to the M3U playlist')
                            ->required(fn (Forms\Get $get): bool => $get('source_type') === 'url')
                            ->visible(fn (Forms\Get $get): bool => $get('source_type') === 'url'),
                        Forms\Components\FileUpload::make('file_path')
                            ->label('M3U File')
                            ->disk('local')
                            ->directory('m3u_files')
                            ->acceptedFileTypes(['application/x-mpegurl', 'audio/x-mpegurl', 'text/plain', '.m3u', '.m3u8'])
                            ->maxSize(10240)
                            ->required(fn (Forms\Get $get): bool => $get('source_type') === 'file')
                            ->visible(fn (Forms\Get $get): bool => $get('source_type') === 'file')
                            ->helperText('Upload an M3U or M3U8 file (max 10MB)'),
                        Forms\Components\Toggle::make('is_active')
                            ->default(true)
                            ->label('Active'),
                        Forms\Components\Toggle::make('use_direct_urls')
                            ->label('Use Direct URLs from Source')
                            ->default(false),
                    ])
                    ->createOptionModalHeading('Create New M3U Source'),
                Forms\Components\TextInput::make('max_connections')
                    ->numeric()
                    ->default(1)
                    ->required(),
                Forms\Components\Toggle::make('is_active')
                    ->default(true)
                    ->required(),
                Forms\Components\DateTimePicker::make('expires_at')
                    ->label('Expiration Date'),
                Forms\Components\Textarea::make('notes')
                    ->maxLength(65535)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('username')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('package.name')
                    ->label('Package')
                    ->sortable(),
                Tables\Columns\TextColumn::make('m3uSource.name')
                    ->label('M3U Source')
                    ->sortable(),
                Tables\Columns\TextColumn::make('m3u_url')
                    ->label('M3U Playlist Link')
                    ->getStateUsing(fn (IptvUser $record): string =>
                        config('app.url') . '/get.php?username=' . urlencode($record->username) . '&password=' . urlencode($record->password)
                    )
                    ->copyable()
                    ->url(fn (IptvUser $record): string =>
                        config('app.url') . '/get.php?username=' . urlencode($record->username) . '&password=' . urlencode($record->password)
                    )
                    ->openUrlInNewTab()
                    ->limit(50)
                    ->tooltip(fn (IptvUser $record): string =>
                        config('app.url') . '/get.php?username=' . urlencode($record->username) . '&password=' . urlencode($record->password)
                    ),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('max_connections')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('expires_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active'),
            ])
            ->actions([
                Tables\Actions\Action::make('sync_tuliprox')
                    ->label('Sync to Tuliprox')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->action(function () {
                        try {
                            $tuliproxService = app(TuliproxService::class);
                            $tuliproxService->syncAll();

                            Notification::make()
                                ->title('Tuliprox Configuration Synced')
                                ->success()
                                ->body('All Tuliprox configuration files have been updated successfully.')
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Sync Failed')
                                ->danger()
                                ->body('Failed to sync Tuliprox configuration: ' . $e->getMessage())
                                ->send();
                        }
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Sync Tuliprox Configuration')
                    ->modalDescription('This will regenerate all Tuliprox configuration files (user.yml, source.yml, api-proxy.yml) based on current database state.')
                    ->modalSubmitActionLabel('Sync Now'),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIptvUsers::route('/'),
            'create' => Pages\CreateIptvUser::route('/create'),
            'edit' => Pages\EditIptvUser::route('/{record}/edit'),
        ];
    }
}
