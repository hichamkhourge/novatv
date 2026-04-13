<?php

namespace App\Filament\Resources\IptvAccountResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ChannelGroupsRelationManager extends RelationManager
{
    protected static string $relationship = 'channelGroups';
    protected static ?string $title = 'Channel Groups Access';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(255),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('channels_count')
                    ->counts('channels')
                    ->label('Channels')
                    ->alignCenter(),
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Active'),
            ])
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    ->preloadRecordSelect()
                    ->label('Grant Access to Group'),
            ])
            ->actions([
                Tables\Actions\DetachAction::make()->label('Revoke'),
            ])
            ->bulkActions([
                Tables\Actions\DetachBulkAction::make()->label('Revoke Selected'),
            ])
            ->emptyStateHeading('No groups assigned')
            ->emptyStateDescription('When no groups are assigned, the account has access to ALL active channel groups.');
    }
}
