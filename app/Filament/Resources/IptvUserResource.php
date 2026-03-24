<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IptvUserResource\Pages;
use App\Filament\Resources\IptvUserResource\RelationManagers;
use App\Models\IptvUser;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

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
                        Forms\Components\TextInput::make('url')
                            ->required()
                            ->url()
                            ->maxLength(255)
                            ->label('M3U URL')
                            ->helperText('Enter the full URL to the M3U playlist'),
                        Forms\Components\Toggle::make('is_active')
                            ->default(true)
                            ->label('Active'),
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
                        'http://localhost:8080/get.php?username=' . urlencode($record->username) . '&password=' . urlencode($record->password)
                    )
                    ->copyable()
                    ->url(fn (IptvUser $record): string =>
                        'http://localhost:8080/get.php?username=' . urlencode($record->username) . '&password=' . urlencode($record->password)
                    )
                    ->openUrlInNewTab()
                    ->limit(50)
                    ->tooltip(fn (IptvUser $record): string =>
                        'http://localhost:8080/get.php?username=' . urlencode($record->username) . '&password=' . urlencode($record->password)
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
