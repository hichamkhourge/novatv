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
use Illuminate\Support\Collection;

class ChannelGroupsRelationManager extends RelationManager
{
    protected static string $relationship = 'channelGroups';

    protected static ?string $title = 'Channel Groups Access';

    /** @var array<int, int>|null */
    private ?array $assignedGroupSortMap = null;

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
            ->query(fn (): Builder => $this->baseGroupsQuery())
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

                Tables\Columns\TextColumn::make('source_channels_count')
                    ->label('Channels')
                    ->alignCenter()
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('source_channels_count', $direction)),
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
                        $this->resetTable();
                    })
                    ->successNotificationTitle('Adult access updated'),

                Tables\Actions\Action::make('enable_all')
                    ->label('Enable All Active')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn (): bool => (bool) $this->ownerAccount()->m3u_source_id && (bool) $this->ownerAccount()->has_group_restrictions)
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
                    ->visible(fn (): bool => (bool) $this->ownerAccount()->m3u_source_id)
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
            ->emptyStateDescription(fn (): string => $this->ownerAccount()->m3u_source_id
                ? 'No active channel groups are available for this source.'
                : 'Select an M3U source for this account to manage group access.');
    }

    private function ownerAccount(): IptvAccount
    {
        /** @var IptvAccount $account */
        $account = $this->ownerRecord;

        return $account;
    }

    private function baseGroupsQuery(): Builder
    {
        $account = $this->ownerAccount();
        $sourceId = $account->m3u_source_id;

        return $this->sourceScopedGroupsQuery()
            ->select('channel_groups.*')
            ->leftJoin('account_channel_groups as acg', function ($join) use ($account) {
                $join->on('acg.channel_group_id', '=', 'channel_groups.id')
                    ->where('acg.account_id', '=', $account->id);
            })
            ->withCount([
                'channels as source_channels_count' => function (Builder $query) use ($sourceId): void {
                    if (! $sourceId) {
                        $query->whereRaw('1 = 0');
                        return;
                    }

                    $query->where('channels.m3u_source_id', $sourceId)
                        ->where('channels.is_active', true);
                },
            ]);
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

        $sourceId = $this->ownerAccount()->m3u_source_id;
        if (! $sourceId) {
            $this->assignedGroupSortMap = [];
            return $this->assignedGroupSortMap;
        }

        $this->assignedGroupSortMap = $this->ownerAccount()->channelGroups()
            ->whereExists(function ($sub) use ($sourceId) {
                $sub->selectRaw('1')
                    ->from('channels')
                    ->whereColumn('channels.channel_group_id', 'channel_groups.id')
                    ->where('channels.m3u_source_id', $sourceId)
                    ->where('channels.is_active', true);
            })
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
        return $this->sourceScopedGroupsQuery()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'sort_order', 'name', 'is_adult']);
    }

    private function sourceScopedGroupsQuery(): Builder
    {
        $account = $this->ownerAccount();
        $sourceId = $account->m3u_source_id;

        $query = ChannelGroup::query()
            ->where('channel_groups.is_active', true)
            ->when(! $account->allow_adult, fn (Builder $builder) => $builder->where('channel_groups.is_adult', false));

        if (! $sourceId) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereExists(function ($sub) use ($sourceId) {
            $sub->selectRaw('1')
                ->from('channels')
                ->whereColumn('channels.channel_group_id', 'channel_groups.id')
                ->where('channels.m3u_source_id', $sourceId)
                ->where('channels.is_active', true);
        });
    }

    private function reloadCachedState(): void
    {
        $this->assignedGroupSortMap = null;
        $this->ownerRecord->refresh();
        $this->ownerRecord->unsetRelation('channelGroups');
        $this->flushCachedTableRecords();
    }
}
