<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IptvAccountResource\Pages;
use App\Filament\Resources\IptvAccountResource\RelationManagers\ChannelGroupsRelationManager;
use App\Jobs\GenerateProviderAccountJob;
use App\Models\IptvAccount;
use App\Models\M3uSource;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class IptvAccountResource extends Resource
{
    protected static ?string $model = IptvAccount::class;
    protected static ?string $navigationIcon  = 'heroicon-o-user-group';
    protected static ?string $navigationLabel = 'IPTV Accounts';
    protected static ?string $navigationGroup = 'IPTV';
    protected static ?int    $navigationSort  = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            // ── Provider ─────────────────────────────────────────────────────
            Forms\Components\Section::make('Provider')->schema([
                Forms\Components\Select::make('provider')
                    ->label('Source Provider')
                    ->options([
                        'manual' => '✋ Manual (enter credentials yourself)',
                        'zazy'   => '🤖 Zazy TV (auto-generate via Selenium)',
                    ])
                    ->default('manual')
                    ->required()
                    ->live()
                    ->helperText('For automated providers, credentials are generated in the background after saving.'),

                Forms\Components\Placeholder::make('provider_info')
                    ->label('')
                    ->content('⏳ After saving, a background job will run the Zazy automation script (2–8 min). The account will show status "pending" until credentials are ready.')
                    ->visible(fn (Forms\Get $get) => $get('provider') !== 'manual'),

                Forms\Components\Placeholder::make('provider_status_display')
                    ->label('Automation Status')
                    ->content(fn ($record) => match ($record?->provider_status) {
                        'pending' => '⏳ Running…',
                        'done'    => '✅ Credentials ready',
                        'failed'  => '❌ Failed: ' . ($record->provider_error ?? 'unknown'),
                        default   => '—',
                    })
                    ->visible(fn ($record) => $record && $record->provider !== 'manual'),
            ])->columns(1),

            // ── Credentials ──────────────────────────────────────────────────
            Forms\Components\Section::make('Credentials')->schema([
                Forms\Components\TextInput::make('username')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255)
                    ->alphaNum()
                    ->helperText(fn (Forms\Get $get) => $get('provider') !== 'manual'
                        ? 'Used as your client username in this app (not the Zazy username — that is stored in the M3U source)'
                        : null
                    ),

                Forms\Components\TextInput::make('password')
                    ->required(fn (Forms\Get $get) => $get('provider') === 'manual')
                    ->maxLength(255)
                    ->helperText(fn (Forms\Get $get) => $get('provider') !== 'manual'
                        ? 'Leave blank — will be auto-filled once the provider generates credentials'
                        : 'Stored in plaintext — IPTV clients send it in URLs'
                    )
                    ->default(fn (Forms\Get $get) => $get('provider') !== 'manual' ? 'pending' : null),
            ])->columns(2),

            Forms\Components\Section::make('M3U Source')->schema([
                Forms\Components\Select::make('m3u_source_id')
                    ->label('Linked M3U Source')
                    ->relationship('m3uSource', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->createOptionForm([
                        Forms\Components\TextInput::make('name')
                            ->label('Source Name')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\Select::make('source_type')
                            ->label('Type')
                            ->options(['url' => 'URL', 'file' => 'File Upload'])
                            ->default('url')
                            ->required()
                            ->live(),

                        Forms\Components\TextInput::make('url')
                            ->label('M3U URL')
                            ->url()
                            ->placeholder('http://provider.com/get.php?...')
                            ->visible(fn (\Filament\Forms\Get $get) => $get('source_type') === 'url')
                            ->requiredIf('source_type', 'url'),

                        Forms\Components\FileUpload::make('file_path')
                            ->label('M3U File')
                            ->directory('m3u_sources')
                            ->acceptedFileTypes(['audio/x-mpegurl', 'application/x-mpegurl', 'text/plain'])
                            ->visible(fn (\Filament\Forms\Get $get) => $get('source_type') === 'file')
                            ->requiredIf('source_type', 'file'),
                    ])
                    ->createOptionUsing(function (array $data): int {
                        $source = M3uSource::create(array_merge($data, [
                            'status'         => 'idle',
                            'is_active'      => true,
                            'channels_count' => 0,
                        ]));

                        // Auto-dispatch import job after inline creation
                        $importSource = $source->source_type === 'file'
                            ? $source->getFullFilePath()
                            : $source->url;

                        if ($importSource) {
                            \App\Jobs\ImportM3uJob::dispatch($importSource, $source->id);
                        }

                        return $source->id;
                    })
                    ->helperText('Channels served to this client come from this M3U source'),
            ])->columns(1),

            Forms\Components\Section::make('Subscription')->schema([
                Forms\Components\Select::make('status')
                    ->options([
                        'active'    => 'Active',
                        'suspended' => 'Suspended',
                        'expired'   => 'Expired',
                    ])
                    ->required()
                    ->default('active'),

                Forms\Components\TextInput::make('max_connections')
                    ->numeric()
                    ->required()
                    ->default(1)
                    ->minValue(1)
                    ->maxValue(100),

                Forms\Components\DateTimePicker::make('expires_at')
                    ->label('Expires At')
                    ->nullable()
                    ->seconds(false),

                Forms\Components\Toggle::make('allow_adult')
                    ->label('Enable Adult Groups')
                    ->helperText('When disabled, adult categories are hidden for this account.')
                    ->default(false),
            ])->columns(3),

            Forms\Components\Section::make('Notes')->schema([
                Forms\Components\Textarea::make('notes')
                    ->rows(3)
                    ->columnSpanFull(),
            ])->collapsible(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('username')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('m3uSource.name')
                    ->label('M3U Source')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('info')
                    ->placeholder('— no source —'),

                Tables\Columns\TextColumn::make('provider')
                    ->label('Provider')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'zazy'   => 'success',
                        'ugeen'  => 'warning',
                        default  => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'zazy'   => '🤖 Zazy',
                        'ugeen'  => '🤖 Ugeen',
                        default  => '✋ Manual',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('provider_status')
                    ->label('Prov. Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'pending' => 'warning',
                        'done'    => 'success',
                        'failed'  => 'danger',
                        default   => 'gray',
                    })
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'success' => 'active',
                        'danger'  => 'expired',
                        'warning' => 'suspended',
                    ])
                    ->sortable(),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('max_connections')
                    ->label('Max Conn.')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('active_sessions_count')
                    ->label('Live')
                    ->alignCenter()
                    ->getStateUsing(fn (IptvAccount $r) => $r->streamSessions()
                        ->where('last_seen_at', '>', now()->subSeconds(30))
                        ->count()
                    ),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])

            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'active'    => 'Active',
                        'suspended' => 'Suspended',
                        'expired'   => 'Expired',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('copy_m3u')
                    ->label('Copy M3U')
                    ->icon('heroicon-o-clipboard')
                    ->action(fn () => null)
                    ->extraAttributes(fn (IptvAccount $record) => [
                        'x-data'   => '',
                        'x-on:click' => "navigator.clipboard.writeText('" . url('/get.php') . "?username={$record->username}&password={$record->password}&type=m3u_plus')",
                    ])
                    ->tooltip('Copy M3U playlist URL to clipboard'),

                Tables\Actions\Action::make('copy_xtream')
                    ->label('Copy Xtream')
                    ->icon('heroicon-o-link')
                    ->action(fn () => null)
                    ->extraAttributes(fn (IptvAccount $record) => [
                        'x-data'   => '',
                        'x-on:click' => "navigator.clipboard.writeText('" . url('/') . ":::" . "{$record->username}:::{$record->password}')",
                    ])
                    ->tooltip('Copy Xtream connection string to clipboard'),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('renew_30_days')
                        ->label('Renew 30 Days')
                        ->icon('heroicon-o-calendar')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            foreach ($records as $account) {
                                $base = ($account->expires_at && $account->expires_at->isFuture())
                                    ? $account->expires_at
                                    : Carbon::now();
                                $account->update(['expires_at' => $base->addDays(30)]);
                            }
                            Notification::make()->title('Renewed 30 days')->success()->send();
                        }),

                    Tables\Actions\BulkAction::make('activate')
                        ->label('Activate')
                        ->icon('heroicon-o-check-circle')
                        ->action(function ($records) {
                            $records->each->update(['status' => 'active']);
                            Notification::make()->title('Accounts activated')->success()->send();
                        }),

                    Tables\Actions\BulkAction::make('suspend')
                        ->label('Suspend')
                        ->icon('heroicon-o-pause-circle')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            $records->each->update(['status' => 'suspended']);
                            Notification::make()->title('Accounts suspended')->warning()->send();
                        }),

                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ChannelGroupsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListIptvAccounts::route('/'),
            'create' => Pages\CreateIptvAccount::route('/create'),
            'edit'   => Pages\EditIptvAccount::route('/{record}/edit'),
        ];
    }
}
