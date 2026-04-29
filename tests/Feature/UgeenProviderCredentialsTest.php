<?php

namespace Tests\Feature;

use App\Jobs\GenerateProviderAccountJob;
use App\Models\IptvAccount;
use App\Models\M3uSource;
use App\Services\ProviderAutomationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PDO;
use Tests\TestCase;

class UgeenProviderCredentialsTest extends TestCase
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

    public function test_ugeen_login_credentials_are_persisted_encrypted_on_iptv_account(): void
    {
        $account = IptvAccount::query()->create([
            'username' => 'client001',
            'password' => 'client-secret',
            'provider' => 'ugeen',
            'provider_login_email' => 'admin@example.com',
            'provider_login_password' => 'ugeen-secret',
            'status' => 'active',
            'max_connections' => 1,
        ]);

        $raw = DB::table('iptv_accounts')->where('id', $account->id)->first();

        $this->assertNotSame('admin@example.com', $raw->provider_login_email);
        $this->assertNotSame('ugeen-secret', $raw->provider_login_password);
        $this->assertSame('admin@example.com', $account->fresh()->provider_login_email);
        $this->assertSame('ugeen-secret', $account->fresh()->provider_login_password);
    }

    public function test_ugeen_generation_uses_account_level_login_credentials(): void
    {
        $account = IptvAccount::query()->create([
            'username' => 'client002',
            'password' => 'client-secret',
            'provider' => 'ugeen',
            'provider_login_email' => 'admin@example.com',
            'provider_login_password' => 'ugeen-secret',
            'status' => 'active',
            'max_connections' => 1,
        ]);

        $automation = Mockery::mock(ProviderAutomationService::class);
        $automation->shouldReceive('generateUgeenViaScript')
            ->once()
            ->with($account->id, 'admin@example.com', 'ugeen-secret')
            ->andReturn(['success' => true, 'message' => 'started', 'error' => null]);

        (new GenerateProviderAccountJob($account->id, isRenewal: false))->handle($automation);

        $this->assertSame('pending', $account->fresh()->provider_status);
    }

    public function test_ugeen_renewal_prefers_account_level_login_credentials(): void
    {
        $source = M3uSource::query()->create([
            'name' => 'Linked Ugeen',
            'source_type' => 'xtream',
            'provider_type' => 'ugeen',
            'provider_username' => 'legacy@example.com',
            'provider_password' => 'legacy-secret',
            'provider_config' => ['package_id' => '384'],
            'status' => 'active',
            'is_active' => true,
        ]);

        $account = IptvAccount::query()->create([
            'username' => 'client004',
            'password' => 'client-secret',
            'provider' => 'ugeen',
            'provider_login_email' => 'admin@example.com',
            'provider_login_password' => 'ugeen-secret',
            'm3u_source_id' => $source->id,
            'status' => 'active',
            'max_connections' => 1,
        ]);

        $automation = Mockery::mock(ProviderAutomationService::class);
        $automation->shouldReceive('renewUgeenViaScript')
            ->once()
            ->with($account->id, 'admin@example.com', 'ugeen-secret', '384')
            ->andReturn(['success' => true, 'message' => 'started', 'error' => null]);

        (new GenerateProviderAccountJob($account->id, isRenewal: true))->handle($automation);

        $this->assertSame('pending', $account->fresh()->provider_status);
    }

    public function test_ugeen_renewal_falls_back_to_linked_source_credentials_for_older_accounts(): void
    {
        $source = M3uSource::query()->create([
            'name' => 'Legacy Ugeen',
            'source_type' => 'xtream',
            'provider_type' => 'ugeen',
            'provider_username' => 'legacy@example.com',
            'provider_password' => 'legacy-secret',
            'provider_config' => ['package_id' => '384'],
            'status' => 'active',
            'is_active' => true,
        ]);

        $account = IptvAccount::query()->create([
            'username' => 'client003',
            'password' => 'client-secret',
            'provider' => 'ugeen',
            'm3u_source_id' => $source->id,
            'status' => 'active',
            'max_connections' => 1,
        ]);

        $automation = Mockery::mock(ProviderAutomationService::class);
        $automation->shouldReceive('renewUgeenViaScript')
            ->once()
            ->with($account->id, 'legacy@example.com', 'legacy-secret', '384')
            ->andReturn(['success' => true, 'message' => 'started', 'error' => null]);

        (new GenerateProviderAccountJob($account->id, isRenewal: true))->handle($automation);

        $this->assertSame('pending', $account->fresh()->provider_status);
    }

    private function createMinimalSchema(): void
    {
        Schema::dropIfExists('iptv_accounts');
        Schema::dropIfExists('m3u_sources');

        Schema::create('m3u_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('source_type')->nullable();
            $table->string('provider_type')->nullable();
            $table->text('provider_username')->nullable();
            $table->text('provider_password')->nullable();
            $table->text('provider_config')->nullable();
            $table->string('status')->default('idle');
            $table->boolean('is_active')->default(true);
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
            $table->text('provider_login_email')->nullable();
            $table->text('provider_login_password')->nullable();
            $table->string('provider_status')->nullable();
            $table->text('provider_error')->nullable();
            $table->timestamp('provider_synced_at')->nullable();
            $table->timestamps();
        });
    }
}
