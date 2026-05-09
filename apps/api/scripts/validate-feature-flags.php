<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$featuresPath = $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'features.php';
$envExamplePath = $root . DIRECTORY_SEPARATOR . '.env.example';

if (!is_file($featuresPath)) {
    fwrite(STDERR, "Missing features config: {$featuresPath}\n");
    exit(1);
}

if (!is_file($envExamplePath)) {
    fwrite(STDERR, "Missing .env.example: {$envExamplePath}\n");
    exit(1);
}

$featuresContent = file_get_contents($featuresPath);
$envContent = file_get_contents($envExamplePath);

if ($featuresContent === false || $envContent === false) {
    fwrite(STDERR, "Unable to read feature flag files.\n");
    exit(1);
}

preg_match_all("/env\\('((FF_[A-Z0-9_]+))'\\s*,/m", $featuresContent, $featureMatches);
preg_match_all("/^(FF_[A-Z0-9_]+)=/m", $envContent, $envMatches);

$featureFlags = array_values(array_unique($featureMatches[1] ?? []));
$envFlags = array_values(array_unique($envMatches[1] ?? []));

sort($featureFlags);
sort($envFlags);

$missingInEnv = array_values(array_diff($featureFlags, $envFlags));
$extraInEnv = array_values(array_diff($envFlags, $featureFlags));

if ($missingInEnv !== []) {
    fwrite(STDERR, "Feature flags missing from .env.example:\n");
    foreach ($missingInEnv as $flag) {
        fwrite(STDERR, " - {$flag}\n");
    }
}

if ($extraInEnv !== []) {
    fwrite(STDERR, "Stale feature flags present in .env.example:\n");
    foreach ($extraInEnv as $flag) {
        fwrite(STDERR, " - {$flag}\n");
    }
}

if ($missingInEnv !== [] || $extraInEnv !== []) {
    exit(1);
}

fwrite(STDOUT, "Feature flag snapshot is in sync.\n");
