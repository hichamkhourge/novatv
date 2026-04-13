<?php

namespace App\Filament\Widgets;

use App\Models\StreamSession;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class ActiveStreamsWidget extends BaseWidget
{
    protected static ?int $sort = 2;
    protected static ?string $heading = 'Active Streams (last 30s)';
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                StreamSession::query()
                    ->with(['account', 'channel'])
                    ->where('last_seen_at', '>', now()->subSeconds(30))
                    ->latest('last_seen_at')
            )
            ->columns([
                Tables\Columns\TextColumn::make('account.username')
                    ->label('Account')
                    ->searchable(),

                Tables\Columns\TextColumn::make('channel.name')
                    ->label('Channel')
                    ->searchable(),

                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP Address'),

                Tables\Columns\TextColumn::make('started_at')
                    ->label('Started')
                    ->dateTime('H:i:s')
                    ->since(),

                Tables\Columns\TextColumn::make('last_seen_at')
                    ->label('Last Seen')
                    ->dateTime('H:i:s')
                    ->since(),
            ])
            ->emptyStateHeading('No active streams')
            ->emptyStateIcon('heroicon-o-signal-slash')
            ->poll('10s');
    }
}
