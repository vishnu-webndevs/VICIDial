<?php

namespace App\Support;

class FeatureFlags
{
    public function enabled(string $flag): bool
    {
        return (bool) config("features.{$flag}", false);
    }

    public function all(): array
    {
        return config('features', []);
    }
}
