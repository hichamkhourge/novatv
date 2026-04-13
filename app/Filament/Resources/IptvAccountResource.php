<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IptvAccountResource\Pages;
use App\Filament\Resources\IptvAccountResource\RelationManagers\ChannelGroupsRelationManager;
use App\Models\IptvAccount;
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
            Forms\Components\Section::make('Credentials')->schema([
                Forms\Components\TextInput::make('username')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255)
                    ->alphaNum(),

                Forms\Components\TextInput::make('password')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Stored in plaintext — IPTV clients send it in URLs'),
            ])->columns(2),

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
