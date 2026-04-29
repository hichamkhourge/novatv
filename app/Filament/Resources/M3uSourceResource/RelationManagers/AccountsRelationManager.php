<?php

namespace App\Filament\Resources\M3uSourceResource\RelationManagers;

use App\Models\IptvAccount;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AccountsRelationManager extends RelationManager
{
    protected static string $relationship = 'iptvAccounts';

    protected static ?string $title = 'IPTV Accounts';

    protected static ?string $recordTitleAttribute = 'username';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Account Details')
                    ->schema([
                        Forms\Components\TextInput::make('username')
                            ->required()
                            ->unique(IptvAccount::class, 'username', ignoreRecord: true)
                            ->maxLength(255)
                            ->placeholder('account_username')
                            ->helperText('Alphanumeric only, unique across all accounts'),

                        Forms\Components\TextInput::make('password')
                            ->required()
                            ->maxLength(255)
                            ->password()
                            ->revealable()
                            ->placeholder('Enter password'),

                        Forms\Components\Select::make('status')
                            ->options([
                                'active'    => '✅ Active',
                                'suspended' => '⏸️ Suspended',
                                'expired'   => '⏰ Expired',
                            ])
                            ->default('active')
                            ->required(),
                    ])->columns(3),

                Forms\Components\Section::make('Settings')
                    ->schema([
                        Forms\Components\TextInput::make('max_connections')
                            ->numeric()
                            ->default(1)
                            ->minValue(1)
                            ->maxValue(100)
                            ->required(),

                        Forms\Components\Toggle::make('allow_adult')
                            ->label('Allow Adult Content')
                            ->default(false)
                            ->inline(false),

                        Forms\Components\Toggle::make('has_group_restrictions')
                            ->label('Restrict Channel Groups')
                            ->default(false)
                            ->helperText('If disabled, user can access all active groups')
                            ->inline(false),
                    ])->columns(3),

                Forms\Components\Section::make('Expiry')
                    ->schema([
                        Forms\Components\Select::make('expiry_preset')
                            ->label('Expiry Preset')
                            ->options([
                                '1_day'     => '1 Day',
                                '1_month'   => '1 Month',
                                '3_months'  => '3 Months',
                                '6_months'  => '6 Months',
                                '1_year'    => '1 Year',
                                'custom'    => 'Custom Date',
                                'never'     => 'Never Expires',
                            ])
                            ->default('1_month')
                            ->live()
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                if ($state === 'never') {
                                    $set('expires_at', null);
                                } elseif ($state !== 'custom') {
                                    $duration = match ($state) {
                                        '1_day'    => '+1 day',
                                        '1_month'  => '+1 month',
                                        '3_months' => '+3 months',
                                        '6_months' => '+6 months',
                                        '1_year'   => '+1 year',
                                        default    => '+1 month',
                                    };
                                    $set('expires_at', now()->modify($duration)->format('Y-m-d H:i:s'));
                                }
                            }),

                        Forms\Components\DateTimePicker::make('expires_at')
                            ->label('Expiration Date')
                            ->visible(fn (Forms\Get $get) => $get('expiry_preset') === 'custom')
                            ->native(false)
                            ->seconds(false)
                            ->displayFormat('Y-m-d H:i')
                            ->helperText('Leave empty for never expires'),
                    ])->columns(2),

                Forms\Components\Section::make('Notes')
                    ->schema([
                        Forms\Components\Textarea::make('notes')
                            ->label('Internal Notes')
                            ->rows(3)
                            ->columnSpanFull()
                            ->placeholder('Add any internal notes about this account...'),
                    ])->collapsible(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('username')
            ->columns([
                Tables\Columns\TextColumn::make('username')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable()
                    ->copyMessage('Username copied'),

                Tables\Columns\TextColumn::make('password')
                    ->searchable()
                    ->toggleable()
                    ->copyable()
                    ->copyMessage('Password copied'),

                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'success' => 'active',
                        'warning' => 'suspended',
                        'danger'  => 'expired',
                    ])
                    ->sortable(),

                Tables\Columns\TextColumn::make('max_connections')
                    ->label('Max Conn.')
                    ->alignCenter()
                    ->sortable(),

                Tables\Columns\IconColumn::make('allow_adult')
                    ->label('Adult')
                    ->boolean()
                    ->alignCenter()
                    ->sortable(),

                Tables\Columns\IconColumn::make('has_group_restrictions')
                    ->label('Restricted')
                    ->boolean()
                    ->alignCenter()
                    ->sortable(),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->color(fn ($record) => $record->expires_at && $record->expires_at->isPast() ? 'danger' : 'success')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? $state->format('Y-m-d H:i') : 'Never'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->since()
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
                Tables\Filters\TernaryFilter::make('allow_adult')
                    ->label('Adult Content'),
                Tables\Filters\TernaryFilter::make('has_group_restrictions')
                    ->label('Group Restrictions'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        // Apply expiry transformation
                        if (isset($data['expiry_preset'])) {
                            if ($data['expiry_preset'] === 'never') {
                                $data['expires_at'] = null;
                            } elseif ($data['expiry_preset'] !== 'custom' && $data['expiry_preset']) {
                                $duration = match ($data['expiry_preset']) {
                                    '1_day'    => '+1 day',
                                    '1_month'  => '+1 month',
                                    '3_months' => '+3 months',
                                    '6_months' => '+6 months',
                                    '1_year'   => '+1 year',
                                    default    => '+1 month',
                                };
                                $data['expires_at'] = now()->modify($duration);
                            }
                            unset($data['expiry_preset']);
                        }

                        // Provider fields default to manual
                        $data['provider'] = 'manual';

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('view_details')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->url(fn (IptvAccount $record) => route('filament.admin.resources.iptv-accounts.edit', $record))
                    ->openUrlInNewTab(),

                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('copy_urls')
                    ->label('Copy URLs')
                    ->icon('heroicon-o-clipboard-document')
                    ->color('info')
                    ->modalHeading('Account Access URLs')
                    ->modalWidth('2xl')
                    ->modalContent(fn (IptvAccount $record) => view('filament.components.account-urls', [
                        'm3u_url'     => route('iptv.m3u.get', [
                            'username' => $record->username,
                            'password' => $record->password,
                        ]),
                        'xtream_url'  => url('/'),
                        'username'    => $record->username,
                        'password'    => $record->password,
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),

                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('activate')
                        ->label('Activate')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(fn ($records) => $records->each->update(['status' => 'active'])),

                    Tables\Actions\BulkAction::make('suspend')
                        ->label('Suspend')
                        ->icon('heroicon-o-pause-circle')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(fn ($records) => $records->each->update(['status' => 'suspended'])),

                    Tables\Actions\BulkAction::make('renew')
                        ->label('Renew')
                        ->icon('heroicon-o-arrow-path')
                        ->color('info')
                        ->form([
                            Forms\Components\Select::make('duration')
                                ->label('Renew Duration')
                                ->options([
                                    '1_day'    => '1 Day',
                                    '1_month'  => '1 Month',
                                    '3_months' => '3 Months',
                                    '6_months' => '6 Months',
                                    '1_year'   => '1 Year',
                                ])
                                ->default('1_month')
                                ->required(),
                        ])
                        ->action(function ($records, array $data) {
                            $duration = match ($data['duration']) {
                                '1_day'    => '+1 day',
                                '1_month'  => '+1 month',
                                '3_months' => '+3 months',
                                '6_months' => '+6 months',
                                '1_year'   => '+1 year',
                                default    => '+1 month',
                            };

                            $records->each(function ($record) use ($duration) {
                                $base = $record->expires_at && $record->expires_at->isFuture()
                                    ? $record->expires_at
                                    : now();
                                $record->update(['expires_at' => $base->modify($duration)]);
                            });
                        }),

                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->orderBy('created_at', 'desc'));
    }
}
