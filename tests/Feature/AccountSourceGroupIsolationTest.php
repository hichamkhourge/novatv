<?php

namespace Tests\Feature;

use App\Models\IptvAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use PDO;
use Tests\TestCase;

class AccountSourceGroupIsolationTest extends TestCase
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
        ]);

        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        $this->createMinimalSchema();
    }

    public function test_cleanup_migration_removes_cross_source_groups_and_unrestricts_empty_accounts(): void
    {
        $sourceA = DB::table('m3u_sources')->insertGetId(['name' => 'Source A']);
        $sourceB = DB::table('m3u_sources')->insertGetId(['name' => 'Source B']);

        $groupA = $this->createGroup('Group A');
        $groupB = $this->createGroup('Group B');
        $groupC = $this->createGroup('Group C');

        DB::table('channels')->insert([
            ['channel_group_id' => $groupA, 'm3u_source_id' => $sourceA, 'is_active' => true],
            ['channel_group_id' => $groupB, 'm3u_source_id' => $sourceB, 'is_active' => true],
            ['channel_group_id' => $groupC, 'm3u_source_id' => $sourceA, 'is_active' => true],
        ]);

        $account1 = $this->createAccount('acc1', (int) $sourceA, true);
        $account2 = $this->createAccount('acc2', (int) $sourceB, true);
        $account3 = $this->createAccount('acc3', null, true);

        DB::table('account_channel_groups')->insert([
            ['account_id' => $account1, 'channel_group_id' => $groupA, 'sort_order' => 0], // valid
            ['account_id' => $account1, 'channel_group_id' => $groupB, 'sort_order' => 1], // invalid for source A
            ['account_id' => $account2, 'channel_group_id' => $groupA, 'sort_order' => 0], // invalid for source B
            ['account_id' => $account3, 'channel_group_id' => $groupC, 'sort_order' => 0], // no source
        ]);

        $migration = require base_path('database/migrations/2026_04_26_000003_cleanup_cross_source_account_channel_groups.php');
        $migration->up();

        $account1Groups = DB::table('account_channel_groups')
            ->where('account_id', $account1)
            ->pluck('channel_group_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $this->assertSame([$groupA], $account1Groups);
        $this->assertFalse((bool) DB::table('iptv_accounts')->where('id', $account2)->value('has_group_restrictions'));
        $this->assertFalse((bool) DB::table('iptv_accounts')->where('id', $account3)->value('has_group_restrictions'));
        $this->assertFalse(DB::table('account_channel_groups')->where('account_id', $account2)->exists());
        $this->assertFalse(DB::table('account_channel_groups')->where('account_id', $account3)->exists());
    }

    public function test_changing_account_source_resets_restrictions_and_detaches_groups(): void
    {
        $sourceA = DB::table('m3u_sources')->insertGetId(['name' => 'Source A']);
        $sourceB = DB::table('m3u_sources')->insertGetId(['name' => 'Source B']);
        $groupA = $this->createGroup('Group A');

        $accountId = $this->createAccount('acc_switch', (int) $sourceA, true);

        DB::table('account_channel_groups')->insert([
            'account_id' => $accountId,
            'channel_group_id' => $groupA,
            'sort_order' => 0,
        ]);

        $account = IptvAccount::query()->findOrFail($accountId);
        $account->update([
            'm3u_source_id' => $sourceB,
            'has_group_restrictions' => true, // should be forced to false on source change
        ]);

        $account->refresh();

        $this->assertFalse($account->has_group_restrictions);
        $this->assertFalse(DB::table('account_channel_groups')->where('account_id', $accountId)->exists());
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

    private function createGroup(string $name): int
    {
        return (int) DB::table('channel_groups')->insertGetId([
            'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $name)),
            'sort_order' => 0,
            'is_active' => true,
            'is_adult' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createAccount(string $username, ?int $sourceId, bool $restricted): int
    {
        return (int) DB::table('iptv_accounts')->insertGetId([
            'username' => $username,
            'password' => 'pass',
            'max_connections' => 1,
            'status' => 'active',
            'm3u_source_id' => $sourceId,
            'has_group_restrictions' => $restricted,
            'allow_adult' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
