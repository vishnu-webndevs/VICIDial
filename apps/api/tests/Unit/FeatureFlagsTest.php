<?php

namespace Tests\Unit;

use App\Support\FeatureFlags;
use Tests\TestCase;

class FeatureFlagsTest extends TestCase
{
    public function test_returns_false_for_unknown_flag(): void
    {
        $flags = new FeatureFlags;

        $this->assertFalse($flags->enabled('unknown_flag'));
    }

    public function test_reads_defined_flag_values_from_config(): void
    {
        config(['features.auth_v2' => true]);
        $flags = new FeatureFlags;

        $this->assertTrue($flags->enabled('auth_v2'));
    }
}
