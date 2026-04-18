<?php

namespace App\Filament\Resources;

use App\Filament\Resources\M3uSourceResource\Pages;
use App\Jobs\ImportM3uJob;
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
    protected static ?string $navigationLabel = 'M3U Sources';
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
                        'url'  => 'URL (remote M3U link)',
                        'file' => 'File Upload',
                    ])
                    ->required()
                    ->default('url')
                    ->live(),
            ])->columns(2),

            Forms\Components\Section::make('Source Input')->schema([
                Forms\Components\TextInput::make('url')
                    ->label('M3U URL')
                    ->url()
                    ->placeholder('http://provider.com/get.php?username=X&password=Y&type=m3u_plus')
                    ->helperText('Full URL to the upstream M3U playlist')
                    ->visible(fn (Get $get) => $get('source_type') === 'url')
                    ->requiredIf('source_type', 'url')
                    ->columnSpanFull(),

                Forms\Components\FileUpload::make('file_path')
                    ->label('M3U File')
                    ->acceptedFileTypes(['audio/x-mpegurl', 'application/x-mpegurl', 'text/plain'])
                    ->directory('m3u_sources')
                    ->storeFileNamesIn('original_filename')
                    ->helperText('Upload a .m3u or .m3u8 file')
                    ->visible(fn (Get $get) => $get('source_type') === 'file')
                    ->requiredIf('source_type', 'file')
                    ->columnSpanFull(),
            ]),

            Forms\Components\Section::make('Status')->schema([
                Forms\Components\Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
            ])->collapsible(),
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
                        'info'    => 'url',
                        'warning' => 'file',
                    ])
                    ->formatStateUsing(fn (string $state) => strtoupper($state)),

                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'success' => 'idle',
                        'warning' => 'syncing',
                        'danger'  => 'error',
                    ]),

                Tables\Columns\TextColumn::make('channels_count')
                    ->label('Channels')
                    ->alignCenter()
                    ->sortable(),

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
                    ->modalHeading('Sync M3U Source')
                    ->modalDescription('This will fetch the M3U and import/update all channels. Large sources may take a few minutes.')
                    ->action(function (M3uSource $record): void {
                        if ($record->status === 'syncing') {
                            Notification::make()
                                ->title('Already syncing')
                                ->warning()
                                ->send();
                            return;
                        }

                        $source = $record->source_type === 'file'
                            ? $record->getFullFilePath()
                            : $record->url;

                        if (! $source) {
                            Notification::make()
                                ->title('No source URL or file configured')
                                ->danger()
                                ->send();
                            return;
                        }

                        ImportM3uJob::dispatch($source, $record->id);

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
