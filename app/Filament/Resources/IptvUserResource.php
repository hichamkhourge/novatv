<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IptvUserResource\Pages;
use App\Filament\Resources\IptvUserResource\RelationManagers\M3uSourcesRelationManager;
use App\Models\IptvUser;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class IptvUserResource extends Resource
{
    protected static ?string $model = IptvUser::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'IPTV Users';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Account Details')
                    ->schema([
                        Forms\Components\TextInput::make('username')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255)
                            ->alphaNum(),

                        Forms\Components\TextInput::make('password')
                            ->required()
                            ->maxLength(255)
                            ->password()
                            ->revealable(),

                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->maxLength(255),
                    ])->columns(3),

                Forms\Components\Section::make('Subscription Settings')
                    ->schema([
                        Forms\Components\TextInput::make('max_connections')
                            ->required()
                            ->numeric()
                            ->default(1)
                            ->minValue(1)
                            ->maxValue(10),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),

                        Forms\Components\DateTimePicker::make('expires_at')
                            ->label('Expiration Date')
                            ->nullable()
                            ->seconds(false),
                    ])->columns(3),

                Forms\Components\Section::make('Notes')
                    ->schema([
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

                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->getStateUsing(function (IptvUser $record): string {
                        if (!$record->is_active) {
                            return 'Inactive';
                        }
                        if ($record->isExpired()) {
                            return 'Expired';
                        }
                        return 'Active';
                    })
                    ->colors([
                        'success' => 'Active',
                        'danger' => 'Expired',
                        'secondary' => 'Inactive',
                    ]),

                Tables\Columns\TextColumn::make('m3uSources_count')
                    ->counts('m3uSources')
                    ->label('Sources')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('max_connections')
                    ->label('Max Conn.')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('expires_at')
                    ->dateTime()
                    ->sortable()
                    ->since(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),
                Tables\Filters\Filter::make('expired')
                    ->query(fn ($query) => $query->whereNotNull('expires_at')->where('expires_at', '<', now()))
                    ->label('Expired'),
            ])
            ->actions([
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
            M3uSourcesRelationManager::class,
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
