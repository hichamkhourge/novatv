<?php

namespace App\Filament\Resources\IptvAccountResource\RelationManagers;

use App\Models\ChannelGroup;
use App\Models\IptvAccount;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

class ChannelGroupsRelationManager extends RelationManager
{
    protected static string $relationship = 'channelGroups';

    protected static ?string $title = 'Channel Groups Access';

    /** @var array<int, int>|null */
    private ?array $assignedGroupSortMap = null;

    public function getRelationship(): Relation | Builder
    {
        $account = $this->ownerAccount();

        return ChannelGroup::query()
            ->select('channel_groups.*')
            ->leftJoin('account_channel_groups as acg', function ($join) use ($account) {
                $join->on('acg.channel_group_id', '=', 'channel_groups.id')
                    ->where('acg.account_id', '=', $account->id);
            })
            ->where('channel_groups.is_active', true)
            ->when(! $account->allow_adult, fn (Builder $query) => $query->where('channel_groups.is_adult', false));
    }

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
                ->orderByRaw('COALESCE(acg.sort_order, channel_groups.sort_order, 0)')
                ->orderBy('channel_groups.sort_order')
                ->orderBy('channel_groups.name'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_adult')
                    ->label('Adult')
                    ->boolean()
                    ->alignCenter(),

                Tables\Columns\ToggleColumn::make('access_enabled')
                    ->label('Enabled')
                    ->alignCenter()
                    ->getStateUsing(fn (ChannelGroup $record): bool => $this->isGroupEnabled($record))
                    ->updateStateUsing(function (ChannelGroup $record, bool $state): bool {
                        $this->setGroupEnabled($record, $state);

                        return $state;
                    }),

                Tables\Columns\TextColumn::make('user_sort_order')
                    ->label('User Order')
                    ->alignCenter()
                    ->getStateUsing(fn (ChannelGroup $record): int => $this->resolveSortOrder($record)),

                Tables\Columns\TextColumn::make('channels_count')
                    ->counts('channels')
                    ->label('Channels')
                    ->alignCenter(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('adult_access')
                    ->label('Adult Access')
                    ->icon('heroicon-o-lock-open')
                    ->fillForm(fn (): array => [
                        'allow_adult' => (bool) $this->ownerAccount()->allow_adult,
                    ])
                    ->form([
                        Forms\Components\Toggle::make('allow_adult')
                            ->label('Enable adult groups for this account')
                            ->default(false),
                    ])
                    ->action(function (array $data): void {
                        $this->ownerAccount()->update([
                            'allow_adult' => (bool) ($data['allow_adult'] ?? false),
                        ]);

                        $this->reloadCachedState();
                    })
                    ->successNotificationTitle('Adult access updated'),

                Tables\Actions\Action::make('enable_all')
                    ->label('Enable All Active')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn (): bool => (bool) $this->ownerAccount()->has_group_restrictions)
                    ->requiresConfirmation()
                    ->action(function (): void {
                        $account = $this->ownerAccount();
                        $account->update(['has_group_restrictions' => false]);
                        $this->reloadCachedState();
                    }),

                Tables\Actions\Action::make('disable_all')
                    ->label('Disable All Active')
                    ->icon('heroicon-o-x-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (): void {
                        $account = $this->ownerAccount();
                        $account->update(['has_group_restrictions' => true]);
                        $account->channelGroups()->detach();
                        $this->reloadCachedState();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('set_order')
                    ->label('Set Order')
                    ->icon('heroicon-o-bars-3')
                    ->visible(fn (ChannelGroup $record): bool => ! $this->ownerAccount()->has_group_restrictions || $this->isGroupEnabled($record))
                    ->fillForm(fn (ChannelGroup $record): array => [
                        'sort_order' => $this->resolveSortOrder($record),
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
                        $sortOrder = max(0, (int) ($data['sort_order'] ?? 0));

                        $this->ownerAccount()
                            ->channelGroups()
                            ->syncWithoutDetaching([
                                $record->id => ['sort_order' => $sortOrder],
                            ]);

                        $this->reloadCachedState();
                    })
                    ->successNotificationTitle('Group order updated'),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('enable_selected')
                    ->label('Enable Selected')
                    ->icon('heroicon-o-check')
                    ->action(function (Collection $records): void {
                        $this->setManyGroupsEnabled($records, true);
                    }),

                Tables\Actions\BulkAction::make('disable_selected')
                    ->label('Disable Selected')
                    ->icon('heroicon-o-x-mark')
                    ->color('warning')
                    ->action(function (Collection $records): void {
                        $this->setManyGroupsEnabled($records, false);
                    }),
            ])
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('No active groups')
            ->emptyStateDescription('No active channel groups are available for this account.');
    }

    private function ownerAccount(): IptvAccount
    {
        /** @var IptvAccount $account */
        $account = $this->ownerRecord;

        return $account;
    }

    private function isGroupEnabled(ChannelGroup $group): bool
    {
        if (! $this->ownerAccount()->has_group_restrictions) {
            return true;
        }

        return array_key_exists($group->id, $this->getAssignedGroupSortMap());
    }

    private function setGroupEnabled(ChannelGroup $group, bool $enabled): void
    {
        $this->setManyGroupsEnabled(collect([$group]), $enabled);
    }

    private function setManyGroupsEnabled(Collection $groups, bool $enabled): void
    {
        $account = $this->ownerAccount();
        $groupIds = $groups
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($groupIds === []) {
            return;
        }

        if ($enabled) {
            if (! $account->has_group_restrictions) {
                return;
            }

            $payload = [];

            foreach ($groups as $group) {
                $payload[$group->id] = ['sort_order' => $this->resolveSortOrder($group)];
            }

            $account->channelGroups()->syncWithoutDetaching($payload);
            $this->reloadCachedState();

            return;
        }

        if (! $account->has_group_restrictions) {
            $visibleGroups = $this->getVisibleGroups();
            $payload = [];
            $disabled = array_flip($groupIds);

            foreach ($visibleGroups as $visibleGroup) {
                if (isset($disabled[$visibleGroup->id])) {
                    continue;
                }

                $payload[$visibleGroup->id] = ['sort_order' => $this->resolveSortOrder($visibleGroup)];
            }

            $account->update(['has_group_restrictions' => true]);
            $account->channelGroups()->sync($payload);
            $this->reloadCachedState();

            return;
        }

        $account->channelGroups()->detach($groupIds);
        $this->reloadCachedState();
    }

    private function resolveSortOrder(ChannelGroup $group): int
    {
        $assignedSort = $this->getAssignedGroupSortMap()[$group->id] ?? null;

        if ($assignedSort !== null) {
            return (int) $assignedSort;
        }

        return (int) ($group->sort_order ?? 0);
    }

    /**
     * @return array<int, int>
     */
    private function getAssignedGroupSortMap(): array
    {
        if ($this->assignedGroupSortMap !== null) {
            return $this->assignedGroupSortMap;
        }

        $this->assignedGroupSortMap = $this->ownerAccount()
            ->channelGroups()
            ->pluck('account_channel_groups.sort_order', 'channel_groups.id')
            ->mapWithKeys(fn ($sortOrder, $groupId) => [(int) $groupId => (int) $sortOrder])
            ->all();

        return $this->assignedGroupSortMap;
    }

    /**
     * @return Collection<int, ChannelGroup>
     */
    private function getVisibleGroups(): Collection
    {
        $account = $this->ownerAccount();

        return ChannelGroup::query()
            ->where('is_active', true)
            ->when(! $account->allow_adult, fn (Builder $query) => $query->where('is_adult', false))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'sort_order', 'name', 'is_adult']);
    }

    private function reloadCachedState(): void
    {
        $this->assignedGroupSortMap = null;
        $this->ownerRecord->refresh();
        $this->ownerRecord->unsetRelation('channelGroups');
    }
}
