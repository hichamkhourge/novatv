<?php

namespace Tests\Feature;

use App\Jobs\GenerateProviderAccountJob;
use App\Models\IptvAccount;
use App\Services\ProviderAutomationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PDO;
use Tests\TestCase;

class LayerSevenProviderAutomationTest extends TestCase
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

    public function test_layerseven_generation_uses_layerseven_automation_endpoint(): void
    {
        $account = IptvAccount::query()->create([
            'username' => 'client-layer',
            'password' => 'pending',
            'provider' => 'layerseven',
            'status' => 'active',
            'max_connections' => 1,
        ]);

        $automation = Mockery::mock(ProviderAutomationService::class);
        $automation->shouldReceive('generateLayerSevenViaScript')
            ->once()
            ->with($account->id)
            ->andReturn(['success' => true, 'message' => 'started', 'error' => null]);

        (new GenerateProviderAccountJob($account->id, isRenewal: false))->handle($automation);

        $this->assertSame('pending', $account->fresh()->provider_status);
    }

    public function test_layerseven_renewal_uses_same_layerseven_generation_method(): void
    {
        $account = IptvAccount::query()->create([
            'username' => 'client-layer-renew',
            'password' => 'pending',
            'provider' => 'layerseven',
            'status' => 'active',
            'max_connections' => 1,
        ]);

        $automation = Mockery::mock(ProviderAutomationService::class);
        $automation->shouldReceive('generateLayerSevenViaScript')
            ->once()
            ->with($account->id)
            ->andReturn(['success' => true, 'message' => 'started', 'error' => null]);

        (new GenerateProviderAccountJob($account->id, isRenewal: true))->handle($automation);

        $this->assertSame('pending', $account->fresh()->provider_status);
    }

    public function test_layerseven_trigger_failure_marks_account_failed(): void
    {
        $account = IptvAccount::query()->create([
            'username' => 'client-layer-failed',
            'password' => 'pending',
            'provider' => 'layerseven',
            'status' => 'active',
            'max_connections' => 1,
        ]);

        $automation = Mockery::mock(ProviderAutomationService::class);
        $automation->shouldReceive('generateLayerSevenViaScript')
            ->once()
            ->with($account->id)
            ->andReturn(['success' => false, 'message' => 'failed', 'error' => 'automation api unavailable']);

        (new GenerateProviderAccountJob($account->id, isRenewal: false))->handle($automation);

        $account->refresh();

        $this->assertSame('failed', $account->provider_status);
        $this->assertSame('automation api unavailable', $account->provider_error);
    }

    private function createMinimalSchema(): void
    {
        Schema::dropIfExists('iptv_accounts');

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
