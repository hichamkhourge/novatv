<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ChannelResource\Pages;
use App\Models\Channel;
use App\Models\ChannelGroup;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ChannelResource extends Resource
{
    protected static ?string $model = Channel::class;
    protected static ?string $navigationIcon  = 'heroicon-o-tv';
    protected static ?string $navigationLabel = 'Channels';
    protected static ?string $navigationGroup = 'IPTV';
    protected static ?int    $navigationSort  = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Channel Details')->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->columnSpan(2),

                Forms\Components\Select::make('channel_group_id')
                    ->label('Channel Group')
                    ->relationship('channelGroup', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                Forms\Components\TextInput::make('sort_order')
                    ->numeric()
                    ->default(0),

                Forms\Components\Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
            ])->columns(2),

            Forms\Components\Section::make('Stream Details')->schema([
                Forms\Components\TextInput::make('stream_url')
                    ->label('Stream URL (Upstream)')
                    ->required()
                    ->url()
                    ->maxLength(2048)
                    ->columnSpanFull(),

                Forms\Components\TextInput::make('logo_url')
                    ->label('Logo URL')
                    ->url()
                    ->maxLength(2048)
                    ->columnSpanFull(),

                Forms\Components\TextInput::make('tvg_id')
                    ->label('TVG ID')
                    ->maxLength(255),

                Forms\Components\TextInput::make('tvg_name')
                    ->label('TVG Name')
                    ->maxLength(255),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('channelGroup.name')
                    ->label('Group')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Active'),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Order')
                    ->alignCenter()
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order')
            ->filters([
                Tables\Filters\SelectFilter::make('channel_group_id')
                    ->label('Group')
                    ->relationship('channelGroup', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->actions([
                Tables\Actions\Action::make('test_stream')
                    ->label('Test Stream')
                    ->icon('heroicon-o-play')
                    ->url(fn (Channel $record) => $record->stream_url)
                    ->openUrlInNewTab(),

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
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListChannels::route('/'),
            'create' => Pages\CreateChannel::route('/create'),
            'edit'   => Pages\EditChannel::route('/{record}/edit'),
        ];
    }
}
