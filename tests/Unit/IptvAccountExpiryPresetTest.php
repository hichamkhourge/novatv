<?php

namespace Tests\Unit;

use App\Filament\Resources\IptvAccountResource;
use Carbon\Carbon;
use Tests\TestCase;

class IptvAccountExpiryPresetTest extends TestCase
{
    public function test_apply_expiry_form_data_uses_default_one_day_preset(): void
    {
        Carbon::setTestNow('2026-04-28 10:15:00');

        $data = IptvAccountResource::applyExpiryFormData([
            'expires_at_preset' => IptvAccountResource::EXPIRY_PRESET_1_DAY,
        ]);

        $this->assertSame('2026-04-29 23:59:59', $data['expires_at']->format('Y-m-d H:i:s'));
        $this->assertArrayNotHasKey('expires_at_preset', $data);
        $this->assertArrayNotHasKey('expires_at_custom_date', $data);
    }

    public function test_apply_expiry_form_data_uses_custom_date_end_of_day(): void
    {
        Carbon::setTestNow('2026-04-28 10:15:00');

        $data = IptvAccountResource::applyExpiryFormData([
            'expires_at_preset' => IptvAccountResource::EXPIRY_PRESET_CUSTOM,
            'expires_at_custom_date' => '2026-05-10',
        ]);

        $this->assertSame('2026-05-10 23:59:59', $data['expires_at']->format('Y-m-d H:i:s'));
    }

    public function test_hydrate_expiry_form_data_marks_non_preset_values_as_custom(): void
    {
        Carbon::setTestNow('2026-04-28 10:15:00');

        $data = IptvAccountResource::hydrateExpiryFormData([
            'expires_at' => '2026-05-07 23:59:59',
        ]);

        $this->assertSame(IptvAccountResource::EXPIRY_PRESET_CUSTOM, $data['expires_at_preset']);
        $this->assertSame('2026-05-07', $data['expires_at_custom_date']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
