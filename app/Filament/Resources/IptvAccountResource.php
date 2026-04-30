<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IptvAccountResource\Pages;
use App\Filament\Resources\IptvAccountResource\RelationManagers\ChannelGroupsRelationManager;
use App\Jobs\GenerateProviderAccountJob;
use App\Models\IptvAccount;
use App\Models\M3uSource;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class IptvAccountResource extends Resource
{
    public const EXPIRY_PRESET_1_DAY = '1_day';
    public const EXPIRY_PRESET_1_MONTH = '1_month';
    public const EXPIRY_PRESET_3_MONTHS = '3_months';
    public const EXPIRY_PRESET_6_MONTHS = '6_months';
    public const EXPIRY_PRESET_1_YEAR = '1_year';
    public const EXPIRY_PRESET_CUSTOM = 'custom';

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
                        'ugeen'  => '🤖 Ugeen (auto-generate via Selenium)',
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
                    ->content(function ($record) {
                        if (!$record || $record->provider === 'manual') {
                            return '—';
                        }

                        $status = $record->provider_status;
                        return match ($status) {
                            'pending' => '⏳ Running… (refresh page to see progress)',
                            'done'    => '✅ Credentials ready' .
                                       ($record->provider_synced_at ? ' (synced ' . $record->provider_synced_at->diffForHumans() . ')' : ''),
                            'failed'  => '❌ Failed: ' . ($record->provider_error ?? 'unknown'),
                            null      => '—',
                            default   => "⚙️ {$status}", // Show progress messages with gear emoji
                        };
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
                        ? 'Used as the client username in this app.'
                        : null
                    ),

                Forms\Components\TextInput::make('password')
                    ->required(fn (Forms\Get $get) => $get('provider') !== 'zazy')
                    ->maxLength(255)
                    ->helperText(fn (Forms\Get $get) => $get('provider') !== 'manual'
                        ? ($get('provider') === 'zazy'
                            ? 'Leave blank — will be auto-filled once Zazy generates credentials'
                            : 'Used as the client password in this app.')
                        : 'Stored in plaintext — IPTV clients send it in URLs'
                    )
                    ->default(fn (Forms\Get $get) => $get('provider') === 'zazy' ? 'pending' : null),
            ])->columns(2),

            Forms\Components\Section::make('Ugeen Account')->schema([
                Forms\Components\TextInput::make('provider_login_email')
                    ->label('Ugeen Email')
                    ->email()
                    ->required(fn (Get $get) => $get('provider') === 'ugeen')
                    ->dehydrated(fn (Get $get) => $get('provider') === 'ugeen')
                    ->maxLength(255),

                Forms\Components\TextInput::make('provider_login_password')
                    ->label('Ugeen Password')
                    ->password()
                    ->revealable()
                    ->required(fn (Get $get) => $get('provider') === 'ugeen')
                    ->dehydrated(fn (Get $get) => $get('provider') === 'ugeen')
                    ->maxLength(255),
            ])
                ->columns(2)
                ->visible(fn (Get $get) => $get('provider') === 'ugeen'),

            Forms\Components\Section::make('M3U Source')->schema([
                Forms\Components\Select::make('m3u_source_id')
                    ->label('Linked M3U Source')
                    ->relationship('m3uSource', 'name')
                    ->searchable()
                    ->preload()
                    ->required(fn (Get $get) => $get('provider') === 'manual')
                    ->dehydrated(fn (Get $get) => $get('provider') === 'manual')
                    ->visible(fn (Get $get) => $get('provider') === 'manual')
                    ->live()
                    ->getOptionLabelFromRecordUsing(fn (M3uSource $record) => match ($record->source_type) {
                        'xtream' => "⚡ {$record->name}",
                        'url'    => "🔗 {$record->name}",
                        'file'   => "📁 {$record->name}",
                        default  => $record->name,
                    })
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

                Forms\Components\Placeholder::make('source_details')
                    ->label('')
                    ->content(function (Get $get, $record) {
                        $sourceId = $get('m3u_source_id');
                        if (! $sourceId) {
                            return null;
                        }

                        $source = M3uSource::find($sourceId);
                        if (! $source) {
                            return null;
                        }

                        $typeIcon = match ($source->source_type) {
                            'xtream' => '⚡',
                            'url'    => '🔗',
                            'file'   => '📁',
                            default  => '📺',
                        };

                        $statusColor = match ($source->status) {
                            'idle'    => '🟢',
                            'syncing' => '🟡',
                            'error'   => '🔴',
                            default   => '⚪',
                        };

                        $activeStatus = $source->is_active ? '✅ Active' : '❌ Inactive';
                        $lastSync = $source->last_synced_at ? $source->last_synced_at->diffForHumans() : 'Never synced';
                        $channelsCount = number_format($source->channels_count);
                        $accountsCount = $source->iptvAccounts()->count();

                        return new \Illuminate\Support\HtmlString("
                            <div style='background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-top: 8px;'>
                                <div style='display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;'>
                                    <div>
                                        <strong style='color: #6b7280; font-size: 12px;'>TYPE</strong><br>
                                        <span style='font-size: 14px;'>{$typeIcon} " . strtoupper($source->source_type) . "</span>
                                    </div>
                                    <div>
                                        <strong style='color: #6b7280; font-size: 12px;'>STATUS</strong><br>
                                        <span style='font-size: 14px;'>{$statusColor} " . ucfirst($source->status) . "</span>
                                    </div>
                                    <div>
                                        <strong style='color: #6b7280; font-size: 12px;'>CHANNELS</strong><br>
                                        <span style='font-size: 14px;'>📺 {$channelsCount}</span>
                                    </div>
                                    <div>
                                        <strong style='color: #6b7280; font-size: 12px;'>ACCOUNTS USING THIS</strong><br>
                                        <span style='font-size: 14px;'>👥 {$accountsCount}</span>
                                    </div>
                                    <div>
                                        <strong style='color: #6b7280; font-size: 12px;'>ACTIVE</strong><br>
                                        <span style='font-size: 14px;'>{$activeStatus}</span>
                                    </div>
                                    <div>
                                        <strong style='color: #6b7280; font-size: 12px;'>LAST SYNC</strong><br>
                                        <span style='font-size: 14px;'>🔄 {$lastSync}</span>
                                    </div>
                                </div>
                                <div style='margin-top: 12px; padding-top: 12px; border-top: 1px solid #e5e7eb;'>
                                    <a href='/admin/sources/{$source->id}/edit' target='_blank' style='color: #3b82f6; text-decoration: none; font-size: 13px;'>
                                        🔗 View Source Details →
                                    </a>
                                </div>
                            </div>
                        ");
                    })
                    ->visible(fn (Get $get) => $get('provider') === 'manual' && $get('m3u_source_id')),

                Forms\Components\Placeholder::make('zazy_source_info')
                    ->label('Linked M3U Source')
                    ->content('A new source will be created automatically for this Zazy account after the script returns the provider username and password.')
                    ->visible(fn (Get $get) => $get('provider') === 'zazy'),
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

                Forms\Components\Select::make('expires_at_preset')
                    ->label('Expires At')
                    ->options(static::expiryPresetOptions())
                    ->default(static::EXPIRY_PRESET_1_DAY)
                    ->live()
                    ->dehydrated(false),

                Forms\Components\DatePicker::make('expires_at_custom_date')
                    ->label('Custom Expiry Date')
                    ->native(false)
                    ->visible(fn (Get $get) => $get('expires_at_preset') === static::EXPIRY_PRESET_CUSTOM)
                    ->required(fn (Get $get) => $get('expires_at_preset') === static::EXPIRY_PRESET_CUSTOM)
                    ->dehydrated(false),

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
                    ->color(function ($record) {
                        if (!$record->m3uSource) {
                            return 'gray';
                        }
                        return match ($record->m3uSource->source_type) {
                            'xtream' => 'success',
                            'url'    => 'info',
                            'file'   => 'warning',
                            default  => 'gray',
                        };
                    })
                    ->formatStateUsing(function ($state, $record) {
                        if (!$record->m3uSource) {
                            return '— no source —';
                        }
                        return match ($record->m3uSource->source_type) {
                            'xtream' => "⚡ {$record->m3uSource->name}",
                            'url'    => "🔗 {$record->m3uSource->name}",
                            'file'   => "📁 {$record->m3uSource->name}",
                            default  => $record->m3uSource->name,
                        };
                    })
                    ->url(fn ($record) => $record->m3uSource ? route('filament.admin.resources.m3u-sources.edit', ['record' => $record->m3uSource]) : null)
                    ->openUrlInNewTab()
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
                        default   => $state ? 'info' : 'gray', // Progress messages show as info blue
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'pending' => '⏳ Pending',
                        'done'    => '✅ Done',
                        'failed'  => '❌ Failed',
                        null      => '—',
                        default   => $state, // Show progress messages as-is
                    })
                    ->wrap() // Allow text wrapping for long progress messages
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: false), // Visible by default

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
                Tables\Filters\SelectFilter::make('m3u_source_id')
                    ->label('M3U Source')
                    ->relationship('m3uSource', 'name')
                    ->searchable()
                    ->preload()
                    ->multiple(),

                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'active'    => 'Active',
                        'suspended' => 'Suspended',
                        'expired'   => 'Expired',
                    ]),

                Tables\Filters\SelectFilter::make('provider')
                    ->options([
                        'manual' => 'Manual',
                        'zazy'   => 'Zazy',
                        'ugeen'  => 'Ugeen',
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

                Tables\Actions\Action::make('renew_ugeen')
                    ->label('Renew')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->tooltip(fn (IptvAccount $record) => match ($record->provider_status) {
                        'pending' => 'Renewal in progress: ' . $record->provider_status,
                        'failed'  => 'Last renewal failed: ' . $record->provider_error,
                        'done'    => 'Last renewed ' . $record->provider_synced_at?->diffForHumans(),
                        default   => 'Trigger manual renewal'
                    })
                    ->visible(fn (IptvAccount $record) => $record->provider === 'ugeen')
                    ->disabled(fn (IptvAccount $record) => $record->provider_status === 'pending')
                    ->requiresConfirmation()
                    ->modalHeading('Renew Ugeen Account')
                    ->modalDescription(fn (IptvAccount $record) =>
                        "This will trigger the Ugeen automation script to renew credentials for account \"{$record->username}\". " .
                        "The process takes 2-8 minutes and you can track progress in the 'Prov. Status' column."
                    )
                    ->action(function (IptvAccount $record) {
                        // Check if already pending
                        if ($record->provider_status === 'pending') {
                            Notification::make()
                                ->title('Renewal already in progress')
                                ->body('Please wait for the current renewal to complete.')
                                ->warning()
                                ->send();
                            return;
                        }

                        // Dispatch renewal job
                        \App\Jobs\GenerateProviderAccountJob::dispatch($record->id, isRenewal: true)
                            ->onQueue('default');

                        // Show success notification
                        Notification::make()
                            ->title('⏳ Renewal Started')
                            ->body("Ugeen renewal script is running for \"{$record->username}\". Refresh the page to see progress updates in the 'Prov. Status' column.")
                            ->warning()
                            ->persistent()
                            ->send();
                    }),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('change_source')
                        ->label('Change Source')
                        ->icon('heroicon-o-arrow-path-rounded-square')
                        ->color('info')
                        ->form([
                            Forms\Components\Select::make('m3u_source_id')
                                ->label('New M3U Source')
                                ->options(M3uSource::where('is_active', true)->pluck('name', 'id'))
                                ->searchable()
                                ->required()
                                ->helperText('⚠️ This will clear all channel group restrictions for selected accounts'),
                        ])
                        ->requiresConfirmation()
                        ->modalHeading('Change Source for Selected Accounts')
                        ->modalDescription('Changing the source will reset channel group access to "all groups" for the selected accounts.')
                        ->action(function ($records, array $data) {
                            $count = 0;
                            foreach ($records as $account) {
                                $account->update([
                                    'm3u_source_id'        => $data['m3u_source_id'],
                                    'has_group_restrictions' => false,
                                ]);
                                $account->channelGroups()->detach();
                                $count++;
                            }
                            Notification::make()
                                ->title("Source changed for {$count} accounts")
                                ->success()
                                ->send();
                        }),

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

    public static function expiryPresetOptions(): array
    {
        return [
            static::EXPIRY_PRESET_1_DAY => '1 day (test)',
            static::EXPIRY_PRESET_1_MONTH => '1 month',
            static::EXPIRY_PRESET_3_MONTHS => '3 months',
            static::EXPIRY_PRESET_6_MONTHS => '6 months',
            static::EXPIRY_PRESET_1_YEAR => '1 year',
            static::EXPIRY_PRESET_CUSTOM => 'Custom',
        ];
    }

    public static function hydrateExpiryFormData(array $data): array
    {
        $expiresAt = isset($data['expires_at']) && $data['expires_at']
            ? Carbon::parse($data['expires_at'])
            : null;

        $data['expires_at_preset'] = static::inferExpiryPreset($expiresAt);
        $data['expires_at_custom_date'] = $data['expires_at_preset'] === static::EXPIRY_PRESET_CUSTOM && $expiresAt
            ? $expiresAt->toDateString()
            : null;

        return $data;
    }

    public static function applyExpiryFormData(array $data): array
    {
        $data['expires_at'] = static::resolveExpiryFromFormData($data);

        unset($data['expires_at_preset'], $data['expires_at_custom_date']);

        return $data;
    }

    public static function resolveExpiryFromFormData(array $data): ?Carbon
    {
        $preset = $data['expires_at_preset'] ?? static::EXPIRY_PRESET_1_DAY;

        return match ($preset) {
            static::EXPIRY_PRESET_1_DAY => Carbon::now()->addDay()->endOfDay(),
            static::EXPIRY_PRESET_1_MONTH => Carbon::now()->addMonth()->endOfDay(),
            static::EXPIRY_PRESET_3_MONTHS => Carbon::now()->addMonths(3)->endOfDay(),
            static::EXPIRY_PRESET_6_MONTHS => Carbon::now()->addMonths(6)->endOfDay(),
            static::EXPIRY_PRESET_1_YEAR => Carbon::now()->addYear()->endOfDay(),
            static::EXPIRY_PRESET_CUSTOM => ! empty($data['expires_at_custom_date'])
                ? Carbon::parse($data['expires_at_custom_date'])->endOfDay()
                : null,
            default => null,
        };
    }

    public static function inferExpiryPreset(?CarbonInterface $expiresAt): string
    {
        if (! $expiresAt) {
            return static::EXPIRY_PRESET_1_DAY;
        }

        $normalized = Carbon::instance($expiresAt instanceof Carbon ? $expiresAt : Carbon::parse($expiresAt))->endOfDay();
        $now = Carbon::now();

        foreach ([
            static::EXPIRY_PRESET_1_DAY => $now->copy()->addDay()->endOfDay(),
            static::EXPIRY_PRESET_1_MONTH => $now->copy()->addMonth()->endOfDay(),
            static::EXPIRY_PRESET_3_MONTHS => $now->copy()->addMonths(3)->endOfDay(),
            static::EXPIRY_PRESET_6_MONTHS => $now->copy()->addMonths(6)->endOfDay(),
            static::EXPIRY_PRESET_1_YEAR => $now->copy()->addYear()->endOfDay(),
        ] as $preset => $expected) {
            if ($normalized->equalTo($expected)) {
                return $preset;
            }
        }

        return static::EXPIRY_PRESET_CUSTOM;
    }
}
