<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\TestCase;

class XtreamApiCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite extension is not available in this environment.');
        }

        config([
            'app.url' => 'https://novatv.novadevlabs.com',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);

        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        $this->createMinimalSchema();
    }

    public function test_player_api_login_response_includes_smarters_compatibility_fields(): void
    {
        $accountId = $this->createAccount();
        $createdAt = (int) DB::table('iptv_accounts')->where('id', $accountId)->value('created_at');

        $response = $this->get('/player_api.php?username=tester&password=secret');

        $response->assertOk()
            ->assertJsonPath('user_info.username', 'tester')
            ->assertJsonPath('user_info.password', 'secret')
            ->assertJsonPath('user_info.auth', 1)
            ->assertJsonPath('user_info.message', 'Login successful')
            ->assertJsonPath('user_info.status', 'Active')
            ->assertJsonPath('user_info.created_at', (string) $createdAt)
            ->assertJsonPath('user_info.exp_date', '0')
            ->assertJsonPath('user_info.active_cons', '0')
            ->assertJsonPath('user_info.max_connections', '1')
            ->assertJsonPath('server_info.url', 'localhost')
            ->assertJsonPath('server_info.server_protocol', 'http');
    }

    public function test_panel_api_matches_player_api_login_response(): void
    {
        $this->createAccount();

        $playerResponse = $this->getJson('/player_api.php?username=tester&password=secret')->json();
        $panelResponse = $this->getJson('/panel_api.php?username=tester&password=secret')->json();

        $this->assertSame($playerResponse, $panelResponse);
    }

    public function test_get_account_info_action_returns_login_payload(): void
    {
        $this->createAccount();

        $defaultResponse = $this->getJson('/player_api.php?username=tester&password=secret')->json();
        $accountInfoResponse = $this->getJson('/player_api.php?username=tester&password=secret&action=get_account_info')->json();

        $this->assertSame($defaultResponse, $accountInfoResponse);
    }

    public function test_get_epg_returns_placeholder_payload(): void
    {
        $this->createAccount();

        $response = $this->getJson('/player_api.php?username=tester&password=secret&action=get_epg');

        $response->assertOk()->assertExactJson([]);
    }

    private function createMinimalSchema(): void
    {
        Schema::dropIfExists('access_logs');
        Schema::dropIfExists('stream_sessions');
        Schema::dropIfExists('account_channel_groups');
        Schema::dropIfExists('channels');
        Schema::dropIfExists('channel_groups');
        Schema::dropIfExists('iptv_accounts');
        Schema::dropIfExists('m3u_sources');

        Schema::create('m3u_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('use_direct_urls')->default(false);
            $table->string('stream_extension')->nullable();
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
            $table->string('tvg_id')->nullable();
            $table->string('tvg_name')->nullable();
            $table->string('logo_url')->nullable();
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

        Schema::create('stream_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('channel_id')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('access_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('username')->nullable();
            $table->string('action')->nullable();
            $table->string('status')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });
    }

    private function createAccount(): int
    {
        $sourceId = (int) DB::table('m3u_sources')->insertGetId([
            'name' => 'Main Source',
            'use_direct_urls' => false,
            'stream_extension' => 'ts',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('iptv_accounts')->insertGetId([
            'username' => 'tester',
            'password' => 'secret',
            'max_connections' => 1,
            'status' => 'active',
            'm3u_source_id' => $sourceId,
            'has_group_restrictions' => false,
            'allow_adult' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
