<?php

namespace App\Filament\Resources;

use App\Filament\Resources\M3uSourceResource\Pages;
use App\Jobs\SyncM3uSourceJob;
use App\Models\M3uSource;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class M3uSourceResource extends Resource
{
    protected static ?string $model = M3uSource::class;

    protected static ?string $navigationIcon = 'heroicon-o-server-stack';

    protected static ?string $navigationLabel = 'M3U Sources';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->label('Source Name'),

                Forms\Components\TextInput::make('url')
                    ->required()
                    ->url()
                    ->maxLength(65535)
                    ->label('M3U URL')
                    ->helperText('Full URL to the M3U playlist file')
                    ->columnSpanFull(),

                Forms\Components\Toggle::make('is_active')
                    ->label('Active')
                    ->default(true)
                    ->helperText('Inactive sources will not be synced'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('url')
                    ->limit(50)
                    ->tooltip(fn ($record) => $record->url)
                    ->searchable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'secondary' => 'idle',
                        'warning' => 'syncing',
                        'success' => 'active',
                        'danger' => 'error',
                    ])
                    ->icon(fn ($state) => match ($state) {
                        'idle' => 'heroicon-o-pause-circle',
                        'syncing' => 'heroicon-o-arrow-path',
                        'active' => 'heroicon-o-check-circle',
                        'error' => 'heroicon-o-x-circle',
                        default => null,
                    })
                    ->tooltip(fn ($record) => $record->error_message),

                Tables\Columns\TextColumn::make('channels_count')
                    ->label('Channels')
                    ->sortable()
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('last_synced_at')
                    ->label('Last Synced')
                    ->dateTime()
                    ->sortable()
                    ->since(),

                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'idle' => 'Idle',
                        'syncing' => 'Syncing',
                        'active' => 'Active',
                        'error' => 'Error',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->actions([
                Tables\Actions\Action::make('sync')
                    ->label('Sync Now')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->action(function (M3uSource $record) {
                        if ($record->status === 'syncing') {
                            Notification::make()
                                ->warning()
                                ->title('Already Syncing')
                                ->body('This source is currently syncing.')
                                ->send();
                            return;
                        }

                        SyncM3uSourceJob::dispatch($record->id);

                        Notification::make()
                            ->success()
                            ->title('Sync Started')
                            ->body("Sync job dispatched for {$record->name}")
                            ->send();
                    }),

                Tables\Actions\Action::make('view_channels')
                    ->label('View Channels')
                    ->icon('heroicon-o-list-bullet')
                    ->modalHeading(fn ($record) => "Channels from: {$record->name}")
                    ->modalContent(fn ($record) => view('filament.modals.view-channels', [
                        'channels' => $record->channels()->active()->limit(100)->get(),
                    ]))
                    ->modalWidth('5xl')
                    ->slideOver(),

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
            'index' => Pages\ListM3uSources::route('/'),
            'create' => Pages\CreateM3uSource::route('/create'),
            'edit' => Pages\EditM3uSource::route('/{record}/edit'),
        ];
    }
}
