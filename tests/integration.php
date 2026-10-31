<?php

// Standalone integration test: actual Illuminate query builder + an in-memory SQLite DB.
// Does not bootstrap NexusPHP or read .env, Redis, or the production database.
namespace App\Models {
    class Setting {
        public static array $values = ['sticky_promotion.enabled' => 'yes', 'sticky_promotion.base' => 100, 'sticky_promotion.min_hours' => 1];
        public static function get($key, $default = null) { return self::$values[$key] ?? $default; }
    }
}
namespace {
    require $argv[1] ?? __DIR__ . '/../../../vendor/autoload.php';
    define('IN_NEXUS', true);
    $root = dirname(__DIR__);
    require_once $root . '/src/Math.php';
    require_once $root . '/src/Service.php';
    $capsule = new \Illuminate\Database\Capsule\Manager();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'mysql');
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    // Schema facade resolves to the SAME isolated connection for the real migration.
    $container = new \Illuminate\Container\Container();
    $container->instance('db', $capsule->getDatabaseManager());
    $capsule->getDatabaseManager()->setDefaultConnection('mysql');
    $container->bind('db.schema', fn () => $capsule->getConnection('mysql')->getSchemaBuilder());
    \Illuminate\Support\Facades\Facade::setFacadeApplication($container);
    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('users', function ($t) { $t->id(); $t->string('username'); $t->decimal('seedbonus', 18, 2); $t->string('enabled')->default('yes'); $t->string('parked')->default('no'); $t->integer('class')->default(1); });
    $schema->create('torrents', function ($t) { $t->id(); $t->string('name')->default('Test'); $t->bigInteger('size'); $t->integer('seeders'); $t->dateTime('added'); $t->string('visible')->default('yes'); $t->string('banned')->default('no'); $t->integer('approval_status')->default(1); });
    $schema->create('messages', function ($t) { $t->id(); $t->integer('sender'); $t->integer('receiver'); $t->dateTime('added'); $t->string('subject'); $t->text('msg'); $t->string('unread'); $t->integer('location'); $t->string('saved'); });
    $migration = require $root . '/database/migrations/2026_10_31_000001_create_sticky_promotions.php';
    $migration->up();
    $db = fn ($table) => \Nexus\Database\NexusDB::table($table);
    $passed = 0;
    function check(bool $ok, string $name): void { global $passed; if (!$ok) { throw new \RuntimeException('FAIL: ' . $name); } ++$passed; echo "PASS $name\n"; }
    function rejects(callable $fn, string $name): void { try { $fn(); } catch (\DomainException) { check(true, $name); return; } check(false, $name); }
    foreach ([1, 2, 3, 4] as $uid) { $db('users')->insert(['id' => $uid, 'username' => 'user' . $uid, 'seedbonus' => $uid === 4 ? 0 : 1000000]); }
    foreach ([1, 2, 3, 4] as $tid) { $db('torrents')->insert(['id' => $tid, 'size' => 50 * 1073741824, 'seeders' => 1, 'added' => date('Y-m-d H:i:s', time() - 31536000)]); }
    $service = new \NexusPlugin\StickyPromotion\Service();
    $quoteTorrent = ['added' => date('Y-m-d H:i:s', time() - 31536000), 'size' => 50 * 1073741824, 'seeders' => 1];
    $quotes = array_map(fn ($state) => $service->quote($quoteTorrent, 24, $state), [2, 3, 4, 5, 6, 7]);
    check(count(array_unique($quotes)) > 1 && $quotes[2] > $quotes[1] && $quotes[4] > $quotes[0], 'occupancy quote changes with selected promotion type');
    \App\Models\Setting::$values['sticky_promotion.multiplier_2'] = '4.2';
    check($service->multipliers()[2] === 4.2 && $service->quote($quoteTorrent, 24, 2) > $quotes[2], 'admin multiplier changes server quote');
    \App\Models\Setting::$values['sticky_promotion.multiplier_2'] = '0';
    check($service->quote($quoteTorrent, 24, 2) === '0.00', 'zero multiplier waives occupancy only');
    \App\Models\Setting::$values['sticky_promotion.multiplier_2'] = '-1';
    check($service->multipliers()[2] === 1.5, 'invalid stored multiplier falls back safely');
    unset(\App\Models\Setting::$values['sticky_promotion.multiplier_2']);
    $input = ['hours' => '24', 'winners' => '2', 'sp_state' => '4', 'bid' => '100', 'reward' => '100.1', 'request_key' => str_repeat('a', 64)];
    $before = (string) $db('users')->where('id', 1)->value('seedbonus');
    $id = $service->purchase(1, 1, $input);
    $p = $db('plugin_sticky_promotions')->where('id', $id)->first();
    $after = (string) $db('users')->where('id', 1)->value('seedbonus');
    check(bccomp(bcsub($before, $after, 2), bcadd($p->occupancy, '200.1', 2), 2) === 0, 'purchase deducts exactly occupancy + bid + reward');
    check($service->purchase(1, 1, $input) === $id && (string) $db('users')->where('id', 1)->value('seedbonus') === $after, 'retry does not charge twice');
    $new = $input; $new['request_key'] = str_repeat('b', 64);
    rejects(fn () => $service->purchase(2, 1, $new), 'non-owner cannot append');
    rejects(fn () => $service->purchase(4, 2, $new), 'insufficient balance rolls back');
    check($db('plugin_sticky_promotions')->where('torrent_id', 2)->count() === 0, 'failed purchase creates no promotion');
    $bad = $new; $bad['bid'] = '-1'; rejects(fn () => $service->purchase(1, 2, $bad), 'negative amounts rejected');
    $bad = $new; $bad['reward'] = 'NaN'; rejects(fn () => $service->purchase(1, 2, $bad), 'non-finite amounts rejected');
    $bad = $new; $bad['winners'] = '0'; rejects(fn () => $service->purchase(1, 2, $bad), 'reward requires winners');
    $bad = $new; $bad['hours'] = '0'; rejects(fn () => $service->purchase(1, 2, $bad), 'new promotion requires minimum duration');
    $append = $new; $append['hours'] = '1'; $append['bid'] = '50'; $append['reward'] = '0';
    $service->purchase(1, 1, $append);
    $updated = $service->active(1);
    check((string) $updated->bid === '150' && strtotime($updated->ends_at) - strtotime($p->ends_at) === 3600, 'append increases bid and duration');
    \App\Models\Setting::$values['sticky_promotion.enabled'] = 'no';
    $bad = $new; $bad['request_key'] = str_repeat('c', 64);
    rejects(fn () => $service->purchase(1, 2, $bad), 'disabled setting blocks purchases');
    check($service->active(1) !== null, 'disabled setting honors existing promotion');
    \App\Models\Setting::$values['sticky_promotion.enabled'] = 'yes';
    $db('plugin_sticky_promotions')->where('id', $id)->update(['starts_at' => date('Y-m-d H:i:s', time() - 1000)]);
    $service->record(['id' => 1], ['id' => 2], '300', time() - 100, 101, '1300');
    $service->record(['id' => 1], ['id' => 2], '300', time() - 100, 101, '1300');
    $service->record(['id' => 1], ['id' => 3], '100', time() - 100, 102, '100');
    $service->record(['id' => 1], ['id' => 1], '999999', time() - 100, 103, '999999');
    check((int) $db('plugin_sticky_contributions')->where('user_id', 2)->value('uploaded') === 300, 'duplicate tracker report counts once');
    check($db('plugin_sticky_contributions')->where('user_id', 1)->count() === 0, 'initiator excluded from rewards');
    $rank = $service->ranking($service->active(1));
    check(count($rank) === 2 && $rank[0]->estimated === '75.00' && $rank[1]->estimated === '25.10', 'top uploaders split full pool with cent remainder');
    $db('plugin_sticky_promotions')->where('id', $id)->update(['ends_at' => date('Y-m-d H:i:s', time() - 1)]);
    $service->record(['id' => 1], ['id' => 3], '900', time() - 100, 102, '1000');
    check((int) $db('plugin_sticky_contributions')->where('user_id', 3)->value('uploaded') === 100, 'after-deadline upload excluded');
    $service->settle(); $service->settle();
    check((float) $db('users')->where('id', 2)->value('seedbonus') === 1000075.0, 'winner paid exactly once');
    check($db('messages')->count() === 2, 'one notification per winner despite repeated settlement');
    check($service->active(1) === null, 'expired promotion no longer overrides torrent');
    $empty = $new; $empty['request_key'] = str_repeat('d', 64);
    $emptyId = $service->purchase(1, 2, $empty);
    $db('plugin_sticky_promotions')->where('id', $emptyId)->update(['ends_at' => date('Y-m-d H:i:s', time() - 1)]);
    $balance = $db('users')->where('id', 1)->value('seedbonus'); $service->settle();
    check($db('users')->where('id', 1)->value('seedbonus') === $balance, 'empty participation does not refund');
    $other = $new; $other['request_key'] = str_repeat('e', 64);
    $crossId = $service->purchase(1, 3, $other);
    $db('plugin_sticky_promotions')->where('id', $crossId)->update(['starts_at' => date('Y-m-d H:i:s', time() - 50)]);
    $service->record(['id' => 3], ['id' => 2], '1000', time() - 100, 105, '1000');
    $credited = (int) $db('plugin_sticky_contributions')->where('promotion_id', $crossId)->value('uploaded');
    check($credited >= 490 && $credited <= 510, 'start-boundary report apportions upload interval');
    $shares = \NexusPlugin\StickyPromotion\Math::shares('0.10', [2 => '1844674407370955', 3 => '1844674407370955']);
    check(bcadd($shares[2], $shares[3], 2) === '0.10', 'large byte counts preserve cents');
    require_once $root . '/src/Repository.php';
    $repo = new \NexusPlugin\StickyPromotion\Repository();
    $order = $repo->order('ORDER BY torrents.id DESC');
    $db('plugin_sticky_promotions')->where('id', $crossId)->update(['bid' => 999]);
    $sqlRows = $capsule->getConnection('mysql')->select('SELECT torrents.id FROM torrents ' . $order . ' LIMIT 1');
    check((int) $sqlRows[0]->id === 3, 'promotion sorts ahead of newer torrents before LIMIT');
    $promoted = $repo->promotion(['id' => 3, 'sp_state' => 1]);
    check($promoted['sp_state'] === 4 && $promoted['__ignore_global_sp_state'], 'tracker receives selected 2X Free promotion');
    check(str_contains(implode('', $repo->actions([], ['id' => 3])), 'layer.open'), 'behavior action opens a modal');
    echo "ALL $passed CHECKS PASSED (isolated in-memory database)\n";
}
