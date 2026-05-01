<?php

namespace Tests\Feature;

use App\Jobs\ImportXtreamJob;
use App\Models\IptvAccount;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\TestCase;

class ZazyWebhookControllerTest extends TestCase
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
            'services.zazy_automation.webhook_token' => '',
        ]);

        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        $this->createMinimalSchema();
    }

    public function test_successful_zazy_webhook_creates_new_source_links_account_and_dispatches_import(): void
    {
        Queue::fake();

        $oldSourceId = DB::table('m3u_sources')->insertGetId([
            'name' => 'Legacy Source',
            'source_type' => 'xtream',
            'status' => 'idle',
            'is_active' => true,
            'channels_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $accountId = DB::table('iptv_accounts')->insertGetId([
            'username' => 'client123',
            'password' => 'pending',
            'provider' => 'zazy',
            'provider_status' => 'pending',
            'status' => 'active',
            'm3u_source_id' => $oldSourceId,
            'max_connections' => 1,
            'allow_adult' => false,
            'has_group_restrictions' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/webhooks/zazy-automation', [
            'user_id' => $accountId,
            'status' => 'success',
            'username' => 'zazy_upstream_user',
            'password' => 'zazy_upstream_pass',
            'host' => 'http://live.zazytv.com',
            'm3u_url' => 'http://live.zazytv.com/get.php?username=zazy_upstream_user&password=zazy_upstream_pass&type=m3u_plus',
        ]);

        $response->assertOk();

        $account = IptvAccount::query()->findOrFail($accountId);
        $newSourceId = $account->m3u_source_id;

        $this->assertNotNull($newSourceId);
        $this->assertNotSame((int) $oldSourceId, (int) $newSourceId);

        $this->assertDatabaseHas('m3u_sources', [
            'id' => $newSourceId,
            'name' => 'Zazy - client123',
            'source_type' => 'xtream',
            'xtream_host' => 'http://live.zazytv.com',
            'xtream_username' => 'zazy_upstream_user',
            'xtream_password' => 'zazy_upstream_pass',
            'status' => 'active',
            'is_active' => 1,
        ]);

        $this->assertSame(
            ['live'],
            json_decode(DB::table('m3u_sources')->where('id', $newSourceId)->value('xtream_stream_types'), true)
        );

        $this->assertSame('done', $account->provider_status);
        $this->assertNull($account->provider_error);
        $this->assertNotNull($account->provider_synced_at);

        Queue::assertPushed(ImportXtreamJob::class, function (ImportXtreamJob $job) use ($newSourceId) {
            return $job->sourceId === (int) $newSourceId;
        });
    }

    public function test_failed_zazy_webhook_marks_account_failed_without_creating_source(): void
    {
        Queue::fake();

        $accountId = DB::table('iptv_accounts')->insertGetId([
            'username' => 'client124',
            'password' => 'pending',
            'provider' => 'zazy',
            'provider_status' => 'pending',
            'status' => 'active',
            'm3u_source_id' => null,
            'max_connections' => 1,
            'allow_adult' => false,
            'has_group_restrictions' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/webhooks/zazy-automation', [
            'user_id' => $accountId,
            'status' => 'failed',
            'error' => 'captcha failed',
        ]);

        $response->assertOk();

        $account = IptvAccount::query()->findOrFail($accountId);

        $this->assertSame('failed', $account->provider_status);
        $this->assertSame('captcha failed', $account->provider_error);
        $this->assertNull($account->m3u_source_id);
        $this->assertSame(0, DB::table('m3u_sources')->count());

        Queue::assertNothingPushed();
    }

    private function createMinimalSchema(): void
    {
        Schema::dropIfExists('iptv_accounts');
        Schema::dropIfExists('m3u_sources');

        Schema::create('m3u_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('source_type')->nullable();
            $table->string('url')->nullable();
            $table->string('file_path')->nullable();
            $table->string('xtream_host')->nullable();
            $table->string('xtream_username')->nullable();
            $table->string('xtream_password')->nullable();
            $table->text('xtream_stream_types')->nullable();
            $table->text('excluded_groups')->nullable();
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
            $table->string('provider')->nullable();
            $table->string('provider_account_id')->nullable();
            $table->string('provider_status')->nullable();
            $table->text('provider_error')->nullable();
            $table->timestamp('provider_synced_at')->nullable();
            $table->timestamps();
        });
    }
}
