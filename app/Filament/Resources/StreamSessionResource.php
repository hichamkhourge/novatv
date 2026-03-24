<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StreamSessionResource\Pages;
use App\Filament\Resources\StreamSessionResource\RelationManagers;
use App\Models\StreamSession;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class StreamSessionResource extends Resource
{
    protected static ?string $model = StreamSession::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('iptv_user_id')
                    ->relationship('iptvUser', 'username')
                    ->required()
                    ->label('IPTV User'),
                Forms\Components\TextInput::make('ip_address')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Textarea::make('user_agent')
                    ->maxLength(65535)
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('stream_id')
                    ->required()
                    ->maxLength(255),
                Forms\Components\DateTimePicker::make('started_at')
                    ->required(),
                Forms\Components\DateTimePicker::make('last_seen_at')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('iptvUser.username')
                    ->sortable()
                    ->searchable()
                    ->label('User'),
                Tables\Columns\TextColumn::make('ip_address')
                    ->searchable(),
                Tables\Columns\TextColumn::make('stream_id')
                    ->searchable(),
                Tables\Columns\TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_seen_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('last_seen_at', 'desc');
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
            'index' => Pages\ListStreamSessions::route('/'),
            'create' => Pages\CreateStreamSession::route('/create'),
            'edit' => Pages\EditStreamSession::route('/{record}/edit'),
        ];
    }
}
