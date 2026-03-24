# IPTV Provider Platform

A complete IPTV management platform built with Laravel 11, PostgreSQL, Redis, Nginx, and Filament v3.

## Features

- **User Management**: Create and manage IPTV users with credentials, packages, and expiry dates
- **Package System**: Define subscription packages with channel groups and pricing
- **M3U Playlist Support**: Import M3U playlists from external sources
- **Xtream Codes API**: Compatible with Xtream Codes API protocol
- **Connection Tracking**: Monitor active connections and enforce limits
- **Admin Panel**: Full-featured Filament v3 admin panel at `/admin`
- **Docker Ready**: Production-ready Docker setup compatible with Dokploy/Coolify

## Quick Start with Docker

```bash
# 1. Clone and setup environment
cp .env.example .env
# Edit .env with your settings

# 2. Generate application key
docker-compose run --rm app php artisan key:generate

# 3. Start services
docker-compose up -d

# 4. Access admin panel
# URL: http://localhost:8080/admin
# Login: admin@iptv.local / password
```

## API Endpoints

### M3U Playlist
```
GET /get.php?username={username}&password={password}&type=m3u_plus
```

### Xtream Codes API
```
GET /player_api.php?username={username}&password={password}
GET /player_api.php?username={username}&password={password}&action=get_live_categories
GET /player_api.php?username={username}&password={password}&action=get_live_streams
```

### Stream Proxy
```
GET /live/{username}/{password}/{stream_id}.ts
```

## Artisan Commands

### Create IPTV User
```bash
docker-compose exec app php artisan iptv:create-user
```

### Refresh M3U Sources
```bash
docker-compose exec app php artisan iptv:refresh-m3u
```

### Cleanup Sessions
```bash
docker-compose exec app php artisan iptv:cleanup-sessions
```

## Admin Panel

Access the admin panel at `/admin`:
- **Email**: admin@iptv.local
- **Password**: password (change after first login!)

### Completing Filament Resources

The Filament resources have been scaffolded. Complete them by editing files in `app/Filament/Resources/`:

**Example for IptvUserResource.php:**

```php
use Filament\Forms;
use Filament\Tables;

public static function form(Form $form): Form
{
    return $form->schema([
        Forms\Components\TextInput::make('username')->required()->unique(),
        Forms\Components\TextInput::make('password')->password()->required(),
        Forms\Components\TextInput::make('email')->email(),
        Forms\Components\Select::make('package_id')->relationship('package', 'name'),
        Forms\Components\TextInput::make('max_connections')->numeric()->default(1),
        Forms\Components\Toggle::make('is_active')->default(true),
        Forms\Components\DateTimePicker::make('expires_at'),
        Forms\Components\Textarea::make('notes'),
    ]);
}

public static function table(Table $table): Table
{
    return $table
        ->columns([
            Tables\Columns\TextColumn::make('username')->searchable(),
            Tables\Columns\TextColumn::make('package.name'),
            Tables\Columns\IconColumn::make('is_active')->boolean(),
            Tables\Columns\TextColumn::make('expires_at')->dateTime(),
        ])
        ->filters([Tables\Filters\TernaryFilter::make('is_active')])
        ->actions([Tables\Actions\EditAction::make()])
        ->bulkActions([Tables\Actions\DeleteBulkAction::make()]);
}
```

Apply similar patterns to: `PackageResource`, `ChannelGroupResource`, `M3uSourceResource`, `StreamSessionResource`.

## Project Structure

```
app/
├── Console/Commands/       # iptv:refresh-m3u, iptv:cleanup-sessions, iptv:create-user
├── Http/Controllers/       # PlaylistController (API endpoints)
├── Models/                 # IptvUser, Package, ChannelGroup, StreamSession, M3uSource
├── Services/               # M3UParserService, M3UGeneratorService, ConnectionTrackerService
└── Filament/Resources/     # Admin panel resources

database/migrations/        # All table schemas
docker/                     # Docker configuration
docker-compose.yml          # Production-ready container orchestration
```

## Configuration

### Environment Variables

Key variables in `.env`:

```env
APP_URL=http://your-domain.com
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_DATABASE=iptv
REDIS_HOST=redis
REDIS_CLIENT=predis
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
```

### Scheduled Tasks

Configured in `bootstrap/app.php`:
- `iptv:refresh-m3u` - Runs hourly
- `iptv:cleanup-sessions` - Runs every minute

### Rate Limiting

- `/get.php`, `/player_api.php`: 60 requests/minute
- `/live/*`: 300 requests/minute

## Deployment

### Docker (Recommended)

The project includes a complete Docker setup with:
- PHP 8.3-FPM
- PostgreSQL 16
- Redis 7
- Nginx
- Queue worker
- Scheduler

```bash
docker-compose up -d
```

### Manual Deployment

```bash
composer install --no-dev --optimize-autoloader
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force
php artisan db:seed --class=AdminSeeder --force
```

## Usage Workflow

1. **Add M3U Source**: Admin Panel → M3U Sources → Add source URL
2. **Import Channels**: Run `iptv:refresh-m3u` or wait for hourly cron
3. **Create Packages**: Admin Panel → Packages → Define pricing and groups
4. **Create Users**: Run `iptv:create-user` or use Admin Panel
5. **Generate Playlist**: Users access `/get.php` with credentials

## Security

1. Change default admin password immediately
2. Use strong database passwords
3. Enable HTTPS in production
4. Consider hashing IPTV user passwords
5. Review and adjust rate limits

## Troubleshooting

**Database connection issues:**
```bash
docker-compose ps postgres
docker-compose logs postgres
```

**M3U not loading:**
```bash
docker-compose exec app php artisan iptv:refresh-m3u
tail -f storage/logs/laravel.log
```

**Check services:**
```bash
docker-compose ps
docker-compose logs -f
```

## License

Open-source software. Built with Laravel 11.
