<?php

// Read-only preflight, prints only compatibility metadata.
require $argv[1] . '/vendor/autoload.php';
$app = require $argv[1] . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (\Illuminate\Support\Facades\DB::select("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('users','messages','torrents')") as $row) {
    echo $row->TABLE_NAME . ': ' . $row->ENGINE . "\n";
}
$column = \Illuminate\Support\Facades\DB::selectOne("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='seedbonus'");
echo 'seedbonus: ' . $column->COLUMN_TYPE . "\n";
echo 'NP: ' . VERSION_NUMBER . "\n";
echo 'meilisearch enabled: ' . \App\Models\Setting::get('meilisearch.enabled', 'unknown') . "\n";
echo 'plugins: ' . implode(', ', array_keys(\Nexus\Plugin\Plugin::listEnabled())) . "\n";
