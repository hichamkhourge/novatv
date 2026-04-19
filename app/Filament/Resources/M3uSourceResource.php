<?php

namespace App\Filament\Resources;

use App\Filament\Resources\M3uSourceResource\Pages;
use App\Jobs\ImportM3uJob;
use App\Jobs\ImportXtreamJob;
use App\Models\M3uSource;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class M3uSourceResource extends Resource
{
    protected static ?string $model           = M3uSource::class;
    protected static ?string $navigationIcon  = 'heroicon-o-tv';
    protected static ?string $navigationLabel = 'Sources';
    protected static ?string $navigationGroup = 'IPTV';
    protected static ?int    $navigationSort  = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([

            Forms\Components\Section::make('Source Details')->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Source Name')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('e.g. Premium Provider 1'),

                Forms\Components\Select::make('source_type')
                    ->label('Source Type')
                    ->options([
                        'xtream' => '⚡ Xtream Codes API (recommended)',
                        'url'    => '🔗 URL (M3U link)',
                        'file'   => '📁 File Upload (M3U)',
                    ])
                    ->required()
                    ->default('xtream')
                    ->live(),

                Forms\Components\Toggle::make('is_active')
                    ->label('Active')
                    ->default(true)
                    ->inline(false),
            ])->columns(3),

            // ── Xtream Codes API ──────────────────────────────────────────────
            Forms\Components\Section::make('⚡ Xtream Codes API')
                ->description('Import channels directly from the provider\'s Xtream API. Much better than M3U — proper categories, live-only streams, no VOD mixing.')
                ->schema([
                    Forms\Components\TextInput::make('xtream_host')
                        ->label('Host URL')
                        ->url()
                        ->placeholder('http://provider.com:8080')
                        ->helperText('Include http:// and port if needed. No trailing slash.')
                        ->required()
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('xtream_username')
                        ->label('Username')
                        ->required()
                        ->placeholder('your_username'),

                    Forms\Components\TextInput::make('xtream_password')
                        ->label('Password')
                        ->required()
                        ->password()
                        ->revealable()
                        ->placeholder('your_password'),

                    Forms\Components\CheckboxList::make('xtream_stream_types')
                        ->label('Import Types')
                        ->options([
                            'live'   => '📺 Live TV',
                            'vod'    => '🎬 VOD (Movies)',
                            'series' => '📚 Series',
                        ])
                        ->default(['live'])
                        ->helperText('Select which stream types to import. Live TV is recommended for IPTV resellers.')
                        ->columns(3)
                        ->columnSpanFull(),

                    Forms\Components\TagsInput::make('excluded_groups')
                        ->label('Exclude Groups')
                        ->placeholder('Add group name and press Enter…')
                        ->helperText('Groups to skip during import (e.g. "24/7", "VOD", etc.)')
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->visible(fn (Get $get) => $get('source_type') === 'xtream'),

            // ── M3U URL ───────────────────────────────────────────────────────
            Forms\Components\Section::make('🔗 M3U URL')
                ->schema([
                    Forms\Components\TextInput::make('url')
                        ->label('M3U URL')
                        ->url()
                        ->placeholder('http://provider.com/get.php?username=X&password=Y&type=m3u_plus')
                        ->helperText('Use type=m3u_plus for best results (includes group-title attributes).')
                        ->required()
                        ->columnSpanFull(),

                    Forms\Components\TagsInput::make('excluded_groups')
                        ->label('Exclude Groups')
                        ->placeholder('Add group name and press Enter…')
                        ->helperText('Groups to skip (e.g. "24/7" skips VOD-style entries)')
                        ->columnSpanFull(),
                ])
                ->visible(fn (Get $get) => $get('source_type') === 'url'),

            // ── File Upload ───────────────────────────────────────────────────
            Forms\Components\Section::make('📁 M3U File Upload')
                ->schema([
                    Forms\Components\FileUpload::make('file_path')
                        ->label('M3U File')
                        ->acceptedFileTypes(['audio/x-mpegurl', 'application/x-mpegurl', 'text/plain'])
                        ->directory('m3u_sources')
                        ->storeFileNamesIn('original_filename')
                        ->helperText('Upload a .m3u or .m3u8 file')
                        ->required()
                        ->columnSpanFull(),

                    Forms\Components\TagsInput::make('excluded_groups')
                        ->label('Exclude Groups')
                        ->placeholder('Add group name and press Enter…')
                        ->columnSpanFull(),
                ])
                ->visible(fn (Get $get) => $get('source_type') === 'file'),

        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\BadgeColumn::make('source_type')
                    ->label('Type')
                    ->colors([
                        'success' => 'xtream',
                        'info'    => 'url',
                        'warning' => 'file',
                    ])
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'xtream' => '⚡ Xtream',
                        'url'    => '🔗 M3U URL',
                        'file'   => '📁 File',
                        default  => strtoupper($state),
                    }),

                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'success' => 'idle',
                        'warning' => 'syncing',
                        'danger'  => 'error',
                    ]),

                Tables\Columns\TextColumn::make('channels_count')
                    ->label('Channels')
                    ->alignCenter()
                    ->sortable()
                    ->formatStateUsing(fn ($state) => number_format($state)),

                Tables\Columns\TextColumn::make('iptvAccounts_count')
                    ->label('Accounts')
                    ->counts('iptvAccounts')
                    ->alignCenter(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('last_synced_at')
                    ->label('Last Sync')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('source_type')
                    ->options([
                        'xtream' => 'Xtream',
                        'url'    => 'M3U URL',
                        'file'   => 'File',
                    ]),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'idle'    => 'Idle',
                        'syncing' => 'Syncing',
                        'error'   => 'Error',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active')->label('Active'),
            ])
            ->actions([
                Tables\Actions\Action::make('sync')
                    ->label('Sync Now')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Sync Source')
                    ->modalDescription(fn (M3uSource $record) => match ($record->source_type) {
                        'xtream' => "Fetch live categories & streams from the Xtream API for \"{$record->name}\".",
                        'file'   => "Re-import channels from the uploaded M3U file for \"{$record->name}\".",
                        default  => "Fetch the M3U playlist and import all channels for \"{$record->name}\".",
                    })
                    ->action(function (M3uSource $record): void {
                        if ($record->status === 'syncing') {
                            Notification::make()->title('Already syncing')->warning()->send();
                            return;
                        }

                        if ($record->isXtream()) {
                            if (! $record->xtream_host || ! $record->xtream_username || ! $record->xtream_password) {
                                Notification::make()->title('Xtream credentials not configured')->danger()->send();
                                return;
                            }
                            ImportXtreamJob::dispatch($record->id);
                        } else {
                            $source = $record->isFileSource()
                                ? $record->getFullFilePath()
                                : $record->url;

                            if (! $source) {
                                Notification::make()->title('No source URL or file configured')->danger()->send();
                                return;
                            }
                            ImportM3uJob::dispatch($source, $record->id);
                        }

                        Notification::make()
                            ->title('Sync started')
                            ->body("Importing channels for \"{$record->name}\"…")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\EditAction::make(),

                Tables\Actions\DeleteAction::make()
                    ->before(function (M3uSource $record, Tables\Actions\DeleteAction $action): void {
                        if ($record->iptvAccounts()->exists()) {
                            Notification::make()
                                ->title('Cannot delete — accounts are linked to this source')
                                ->body('Unlink or reassign all accounts first.')
                                ->danger()
                                ->send();
                            $action->cancel();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListM3uSources::route('/'),
            'create' => Pages\CreateM3uSource::route('/create'),
            'edit'   => Pages\EditM3uSource::route('/{record}/edit'),
        ];
    }
}
