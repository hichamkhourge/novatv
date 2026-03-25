<?php

namespace App\Filament\Resources;

use App\Filament\Resources\M3uSourceResource\Pages;
use App\Filament\Resources\M3uSourceResource\RelationManagers;
use App\Models\M3uSource;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class M3uSourceResource extends Resource
{
    protected static ?string $model = M3uSource::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Radio::make('source_type')
                    ->label('Source Type')
                    ->options([
                        'url' => 'URL (Fetch from external source)',
                        'file' => 'File (Upload M3U file)',
                    ])
                    ->default('url')
                    ->required()
                    ->live()
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('url')
                    ->maxLength(65535)
                    ->label('M3U URL')
                    ->required(fn (Forms\Get $get): bool => $get('source_type') === 'url')
                    ->visible(fn (Forms\Get $get): bool => $get('source_type') === 'url')
                    ->columnSpanFull(),
                Forms\Components\FileUpload::make('file_path')
                    ->label('M3U File')
                    ->disk('local')
                    ->directory('m3u_files')
                    ->acceptedFileTypes(['application/x-mpegurl', 'audio/x-mpegurl', 'text/plain', '.m3u', '.m3u8'])
                    ->maxSize(10240)
                    ->required(fn (Forms\Get $get): bool => $get('source_type') === 'file')
                    ->visible(fn (Forms\Get $get): bool => $get('source_type') === 'file')
                    ->helperText('Upload an M3U or M3U8 file (max 10MB)')
                    ->columnSpanFull(),
                Forms\Components\Toggle::make('is_active')
                    ->default(true)
                    ->required(),
                Forms\Components\Toggle::make('use_direct_urls')
                    ->label('Use Direct URLs from Source')
                    ->helperText('When enabled, playlists will contain original source URLs instead of proxied URLs. Note: This exposes source credentials to users and disables connection tracking.')
                    ->default(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('source_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'url' => 'info',
                        'file' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => strtoupper($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('url')
                    ->limit(50)
                    ->searchable()
                    ->placeholder('N/A'),
                Tables\Columns\TextColumn::make('file_path')
                    ->label('File')
                    ->limit(30)
                    ->placeholder('N/A')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\IconColumn::make('use_direct_urls')
                    ->label('Direct URLs')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_fetched_at')
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
            'index' => Pages\ListM3uSources::route('/'),
            'create' => Pages\CreateM3uSource::route('/create'),
            'edit' => Pages\EditM3uSource::route('/{record}/edit'),
        ];
    }
}
