<?php

// Run from CLI only. Read-only application checks; financial checks always roll back.
if (PHP_SAPI !== 'cli') { exit(1); }
require $argv[1] . '/vendor/autoload.php';
$app = require $argv[1] . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use NexusPlugin\StickyPromotion\Service;
use NexusPlugin\StickyPromotion\Math;

$user = \App\Models\User::query()->where('class', '>=', 15)->where('enabled', 'yes')->firstOrFail();
Auth::guard('nexus-web')->setUser($user);
$torrent = DB::table('torrents')->where('visible', 'yes')->where('banned', 'no')->where('approval_status', 1)->orderByDesc('id')->first();
if (!$torrent) { throw new RuntimeException('No visible torrent for smoke check'); }
$output = $argv[2];
if (!is_dir($output)) { mkdir($output, 0755, true); }
$html = (new \NexusPlugin\StickyPromotion\Controller())->show((int) $torrent->id)->render();
file_put_contents($output . '/live-modal.html', $html);
if (!str_contains($html, '2X Free') || !str_contains($html, '确认购买并扣除魔力')) { throw new RuntimeException('Modal rendering failed'); }
echo "PASS live controller and Blade render\n";
if (!\App\Filament\Pages\StickyPromotionSettingPage::canAccess()) { throw new RuntimeException('Admin page permission check failed'); }
echo "PASS new admin page class and permissions\n";
\Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
$component = \Livewire\Livewire::test(\App\Filament\Pages\StickyPromotionSettingPage::class);
$adminHtml = $component->html();
if (!str_contains($adminHtml, '占位魔力基数')) { throw new RuntimeException('Admin settings schema failed to render'); }
file_put_contents($output . '/live-admin.html', $adminHtml);
echo "PASS live Filament admin settings render\n";
foreach ([[2,1,1,2], [3,2,1,4], [5,2,1,2], [4,7,1,4]] as [$selected,$native,$global,$expected]) {
    if (Math::strongestState([$selected,$native,$global]) !== $expected) { throw new RuntimeException('Promotion compatibility failed'); }
}
echo "PASS existing promotion benefits preserved\n";
$service = new Service();
$oldBalance = (string) DB::table('users')->where('id', $user->id)->value('seedbonus');
$oldCount = DB::table('plugin_sticky_promotions')->count();
DB::beginTransaction();
try {
    $id = $service->purchase((int) $user->id, (int) $torrent->id, ['hours' => 1, 'winners' => 1,
        'sp_state' => 4, 'bid' => '0', 'reward' => '0', 'request_key' => bin2hex(random_bytes(32))]);
    if ($service->active((int) $torrent->id)?->id !== $id) { throw new RuntimeException('Live MySQL purchase did not become active'); }
    echo "PASS live MySQL purchase, row locks and fee precision (transaction rolled back)\n";
} finally { DB::rollBack(); }
if ((string) DB::table('users')->where('id', $user->id)->value('seedbonus') !== $oldBalance || DB::table('plugin_sticky_promotions')->count() !== $oldCount) {
    throw new RuntimeException('Smoke test left financial changes');
}
echo "PASS balances and promotion records unchanged after smoke test\n";
