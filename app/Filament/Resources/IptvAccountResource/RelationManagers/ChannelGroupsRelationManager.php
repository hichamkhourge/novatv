<?php

namespace App\Filament\Resources\IptvAccountResource\RelationManagers;

use App\Models\ChannelGroup;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Actions\AttachAction;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->orderBy('account_channel_groups.sort_order')
                ->orderBy('channel_groups.sort_order')
                ->orderBy('channel_groups.name'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('pivot.sort_order')
                    ->label('User Order')
                    ->alignCenter()
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('account_channel_groups.sort_order', $direction)),
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Global Order')
                    ->alignCenter()
                    ->sortable(),
                Tables\Columns\TextColumn::make('channels_count')
                    ->counts('channels')
                    ->label('Channels')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('is_active')
                    ->label('Global Status')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Disabled')
                    ->color(fn (bool $state): string => $state ? 'success' : 'danger'),
            ])
            ->headerActions([
                AttachAction::make()
                    ->preloadRecordSelect()
                    ->label('Grant Access to Group')
                    ->form(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        Forms\Components\TextInput::make('sort_order')
                            ->label('User Order')
                            ->numeric()
                            ->required()
                            ->default(0)
                            ->minValue(0)
                            ->helperText('Lower numbers appear first for this account.'),
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('set_order')
                    ->label('Set Order')
                    ->icon('heroicon-o-bars-3')
                    ->fillForm(fn (ChannelGroup $record): array => [
                        'sort_order' => (int) ($record->pivot?->sort_order ?? 0),
                    ])
                    ->form([
                        Forms\Components\TextInput::make('sort_order')
                            ->label('User Order')
                            ->numeric()
                            ->required()
                            ->default(0)
                            ->minValue(0),
                    ])
                    ->action(function (ChannelGroup $record, array $data): void {
                        $this->ownerRecord
                            ->channelGroups()
                            ->updateExistingPivot($record->id, [
                                'sort_order' => max(0, (int) ($data['sort_order'] ?? 0)),
                            ]);
                    })
                    ->successNotificationTitle('Group order updated'),
                Tables\Actions\DetachAction::make()->label('Revoke'),
            ])
            ->bulkActions([
                Tables\Actions\DetachBulkAction::make()->label('Revoke Selected'),
            ])
            ->emptyStateHeading('No groups assigned')
            ->emptyStateDescription('When no groups are assigned, the account has access to all active channel groups.');
    }
}
