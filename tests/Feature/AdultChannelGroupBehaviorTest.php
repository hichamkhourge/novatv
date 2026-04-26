<?php

namespace Tests\Feature;

use App\Jobs\ImportM3uJob;
use App\Jobs\ImportXtreamJob;
use App\Models\IptvAccount;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\TestCase;

class AdultChannelGroupBehaviorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite extension is not available in this environment.');
        }

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'app.url' => 'http://panel.test',
        ]);

        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        $this->createMinimalSchema();
    }

    public function test_m3u_import_auto_tags_new_adult_groups_and_preserves_existing_flags(): void
    {
        $sourceId = $this->createSource([
            'name' => 'File Source',
            'source_type' => 'file',
        ]);

        DB::table('channel_groups')->insert([
            'name' => 'Adults (18+)',
            'slug' => 'adults-18',
            'sort_order' => 0,
            'is_active' => true,
            'is_adult' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $playlist = tempnam(sys_get_temp_dir(), 'iptv-adult-');
        file_put_contents($playlist, <<<M3U
#EXTM3U
#EXTINF:-1 group-title="Adults (18+)",Late Show
http://example.com/1
#EXTINF:-1 group-title="Playboy XXX",Night Show
http://example.com/3
#EXTINF:-1 group-title="Kids",Cartoon Time
http://example.com/2
M3U);

        try {
            (new ImportM3uJob($playlist, $sourceId))->handle();
        } finally {
            @unlink($playlist);
        }

        $this->assertFalse((bool) DB::table('channel_groups')->where('name', 'Adults (18+)')->value('is_adult'));
        $this->assertTrue((bool) DB::table('channel_groups')->where('name', 'Playboy XXX')->value('is_adult'));
        $this->assertFalse((bool) DB::table('channel_groups')->where('name', 'Kids')->value('is_adult'));
    }

    public function test_flag_migration_adds_required_columns(): void
    {
        Schema::dropIfExists('account_channel_groups');
        Schema::dropIfExists('channel_groups');
        Schema::dropIfExists('iptv_accounts');

        Schema::create('iptv_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('username')->nullable();
            $table->timestamps();
        });

        Schema::create('channel_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('account_channel_groups', function (Blueprint $table): void {
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('channel_group_id');
        });

        $migration = require base_path('database/migrations/2026_04_26_000002_add_account_group_flags_and_adult_categories.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumn('iptv_accounts', 'allow_adult'));
        $this->assertTrue(Schema::hasColumn('iptv_accounts', 'has_group_restrictions'));
        $this->assertTrue(Schema::hasColumn('channel_groups', 'is_adult'));
    }

    public function test_xtream_import_auto_tags_new_adult_groups(): void
    {
        $sourceId = $this->createSource([
            'name' => 'Xtream Source',
            'source_type' => 'xtream',
            'xtream_host' => 'http://upstream.test',
            'xtream_username' => 'user',
            'xtream_password' => 'pass',
            'xtream_stream_types' => json_encode(['live']),
        ]);

        Http::fake([
            'http://upstream.test/player_api.php*action=get_live_categories*' => Http::response([
                ['category_id' => '1', 'category_name' => 'Adults 24-7'],
                ['category_id' => '2', 'category_name' => 'Sports'],
            ]),
            'http://upstream.test/player_api.php*action=get_live_streams*' => Http::response([
                ['stream_id' => 101, 'name' => 'Adult Stream', 'category_id' => '1', 'stream_icon' => null],
                ['stream_id' => 102, 'name' => 'Sports Stream', 'category_id' => '2', 'stream_icon' => null],
            ]),
        ]);

        (new ImportXtreamJob($sourceId))->handle();

        $this->assertTrue((bool) DB::table('channel_groups')->where('name', 'Adults 24-7')->value('is_adult'));
        $this->assertFalse((bool) DB::table('channel_groups')->where('name', 'Sports')->value('is_adult'));
    }

    public function test_backfill_migration_marks_existing_adult_like_groups(): void
    {
        $adultGroup = $this->createGroup('Adults (18+)', false);
        $swimGroup = $this->createGroup('Adult Swim', false);
        $sportsGroup = $this->createGroup('Sports', false);

        $migration = require base_path('database/migrations/2026_04_26_000004_backfill_adult_channel_groups.php');
        $migration->up();

        $this->assertTrue((bool) DB::table('channel_groups')->where('id', $adultGroup)->value('is_adult'));
        $this->assertFalse((bool) DB::table('channel_groups')->where('id', $swimGroup)->value('is_adult'));
        $this->assertFalse((bool) DB::table('channel_groups')->where('id', $sportsGroup)->value('is_adult'));
    }

    public function test_resolved_channel_groups_hide_adult_groups_when_disabled(): void
    {
        $sourceId = $this->createSource(['name' => 'Source A']);
        $sportsGroup = $this->createGroup('Sports', false);
        $adultGroup = $this->createGroup('Adults (18+)', true);

        DB::table('channels')->insert([
            [
                'channel_group_id' => $sportsGroup,
                'm3u_source_id' => $sourceId,
                'name' => 'Sports Stream',
                'stream_url' => 'http://example.com/sports',
                'sort_order' => 0,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'channel_group_id' => $adultGroup,
                'm3u_source_id' => $sourceId,
                'name' => 'Adult Stream',
                'stream_url' => 'http://example.com/adult',
                'sort_order' => 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $accountId = $this->createAccount('adult_filter', $sourceId, false, false);
        $account = IptvAccount::query()->findOrFail($accountId);

        $this->assertSame(['Sports'], $account->resolvedChannelGroups()->pluck('name')->all());

        $account->update(['allow_adult' => true]);
        $account->refresh();

        $this->assertSame(['Adults (18+)', 'Sports'], $account->resolvedChannelGroups()->pluck('name')->sort()->values()->all());

        $noSourceAccountId = $this->createAccount('no_source', null, false, false);
        $noSourceAccount = IptvAccount::query()->findOrFail($noSourceAccountId);

        $this->assertCount(0, $noSourceAccount->resolvedChannelGroups());
    }

    private function createMinimalSchema(): void
    {
        Schema::dropIfExists('account_channel_groups');
        Schema::dropIfExists('channels');
        Schema::dropIfExists('channel_groups');
        Schema::dropIfExists('iptv_accounts');
        Schema::dropIfExists('m3u_sources');

        Schema::create('m3u_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('source_type')->default('url');
            $table->text('url')->nullable();
            $table->string('file_path')->nullable();
            $table->string('xtream_host')->nullable();
            $table->string('xtream_username')->nullable();
            $table->string('xtream_password')->nullable();
            $table->json('xtream_stream_types')->nullable();
            $table->json('excluded_groups')->nullable();
            $table->string('status')->default('idle');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('channels_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('iptv_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('username')->nullable();
            $table->string('password')->nullable();
            $table->unsignedInteger('max_connections')->default(1);
            $table->timestamp('expires_at')->nullable();
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('m3u_source_id')->nullable();
            $table->boolean('has_group_restrictions')->default(false);
            $table->boolean('allow_adult')->default(false);
            $table->timestamps();
        });

        Schema::create('channel_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_adult')->default(false);
            $table->timestamps();
        });

        Schema::create('channels', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('channel_group_id')->nullable();
            $table->unsignedBigInteger('m3u_source_id')->nullable();
            $table->string('name')->default('ch');
            $table->string('stream_url')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('tvg_id')->nullable();
            $table->string('tvg_name')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('account_channel_groups', function (Blueprint $table): void {
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('channel_group_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->primary(['account_id', 'channel_group_id']);
        });
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createSource(array $overrides = []): int
    {
        return (int) DB::table('m3u_sources')->insertGetId(array_merge([
            'name' => 'Source',
            'source_type' => 'url',
            'status' => 'idle',
            'is_active' => true,
            'channels_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function createGroup(string $name, bool $isAdult): int
    {
        return (int) DB::table('channel_groups')->insertGetId([
            'name' => $name,
            'slug' => strtolower(str_replace([' ', '(', ')', '+'], ['-', '', '', ''], $name)),
            'sort_order' => 0,
            'is_active' => true,
            'is_adult' => $isAdult,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createAccount(string $username, ?int $sourceId, bool $restricted, bool $allowAdult): int
    {
        return (int) DB::table('iptv_accounts')->insertGetId([
            'username' => $username,
            'password' => 'pass',
            'max_connections' => 1,
            'status' => 'active',
            'm3u_source_id' => $sourceId,
            'has_group_restrictions' => $restricted,
            'allow_adult' => $allowAdult,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
