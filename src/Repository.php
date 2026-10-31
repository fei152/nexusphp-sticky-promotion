<?php

namespace NexusPlugin\StickyPromotion;

use App\Models\Setting;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs\Tab;
use Nexus\Database\NexusDB as DB;
use Nexus\Plugin\BasePlugin;

class Repository extends BasePlugin
{
    public const ID = 'sticky-promotion';
    public const VERSION = '1.0.0';
    public const COMPATIBLE_NP_VERSION = '1.10.2';
    private ?array $activePromotions = null;

    private function activePromotions(): array
    {
        if ($this->activePromotions === null) {
            $this->activePromotions = DB::table('plugin_sticky_promotions')->whereNull('settled_at')
                ->where('ends_at', '>', date('Y-m-d H:i:s'))->get()->keyBy('torrent_id')->all();
        }
        return array_filter($this->activePromotions, fn ($p) => $p->ends_at > date('Y-m-d H:i:s'));
    }

    public function getId(): string { return self::ID; }

    public function install(): void
    {
        // Existing account balances and messages must share transactional storage.
        $engines = DB::select("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('users','messages','torrents')");
        foreach ($engines as $engine) {
            $engine = (array) $engine;
            if (strcasecmp($engine['ENGINE'], 'InnoDB') !== 0) {
                throw new \RuntimeException('请先将 ' . $engine['TABLE_NAME'] . ' 表转换为 InnoDB 后再安装，以保证魔力扣费和结算的事务安全。');
            }
        }
        $this->runMigrations(dirname(__DIR__) . '/database/migrations');
        foreach (['enabled' => 'yes', 'base' => '100', 'min_hours' => '1', 'min_class' => '0'] as $key => $value) {
            Setting::query()->firstOrCreate(['name' => 'sticky_promotion.' . $key], ['value' => $value, 'autoload' => 'yes']);
        }
        foreach (Service::DEFAULT_MULTIPLIERS as $state => $value) {
            Setting::query()->firstOrCreate(['name' => 'sticky_promotion.multiplier_' . $state], ['value' => (string) $value, 'autoload' => 'yes']);
        }
        $source = dirname(__DIR__) . '/stubs/app/Filament/Pages/StickyPromotionSettingPage.php';
        $target = app_path('Filament/Pages/StickyPromotionSettingPage.php');
        copy($source, $target);
        clear_setting_cache();
    }

    public function uninstall(): void
    {
        (new Service())->settle();
        if (DB::table('plugin_sticky_promotions')->whereNull('settled_at')->exists()) {
            throw new \RuntimeException('还有未结束的促销，请先在设置中关闭新购买，等待全部结算后再卸载。');
        }
    }

    public function boot(): void
    {
        if (!DB::hasTable('plugin_sticky_promotions')) { return; }
        add_filter('nexus_setting_tabs', [$this, 'settings']);
        add_filter('torrent_detail_actions', [$this, 'actions'], 10, 2);
        add_filter('torrent_detail_description', [$this, 'description'], 10, 3);
        add_filter('torrent_detail', [$this, 'promotion'], 100);
        add_filter('torrent_promotion', [$this, 'promotion'], 100);
        add_filter('torrent_list', [$this, 'listRows'], 5, 4);
        add_filter('sticky_icon', [$this, 'icon'], 10, 2);
        add_filter('torrent_title_suffix', [$this, 'suffix'], 10, 2);
        add_action('announced', [$this, 'announced'], 100, 3);
    }

    public function settings(array $tabs): array
    {
        $tabs[] = Tab::make('置顶促销')->id('sticky-promotion')->schema(self::fields('sticky_promotion.'))->columns(2);
        return $tabs;
    }

    public static function fields(string $prefix = ''): array
    {
        return [
            Radio::make($prefix . 'enabled')->label('开启种子促销')->options(['yes' => '开启', 'no' => '关闭'])->inline()->default('yes')->required()
                ->helperText('关闭后不接受新购买或追加；已购买的促销继续生效并正常结算。'),
            TextInput::make($prefix . 'base')->label('占位魔力基数')->numeric()->minValue(0)->maxValue(1000000)->default(100)->required()
                ->helperText('占位魔力＝基数 × 小时数 × 当前种子的系统时魔系数 × 所选促销收费倍率。'),
            TextInput::make($prefix . 'min_hours')->label('最小购买 / 追加时长（小时）')->integer()->minValue(1)->maxValue(8760)->default(1)->required(),
            TextInput::make($prefix . 'min_class')->label('最低发起用户等级')->integer()->minValue(0)->maxValue(20)->default(0)->required(),
            ...array_map(fn ($state) => TextInput::make($prefix . 'multiplier_' . $state)
                ->label(\App\Models\Torrent::$promotionTypes[$state]['text'] . ' 收费倍率')
                ->numeric()->minValue(0)->maxValue(1000000)->default(Service::DEFAULT_MULTIPLIERS[$state])->required()
                ->helperText('仅影响占位费；0 表示该类型免占位费。新购买和追加按保存后的倍率计算，已扣费用不追溯。'),
                array_keys(Service::DEFAULT_MULTIPLIERS)),
        ];
    }

    public function actions(array $actions, array $row): array
    {
        if (Setting::get('sticky_promotion.enabled', 'yes') !== 'yes' && !isset($this->activePromotions()[(int) $row['id']])) {
            return $actions;
        }
        $url = '/web/sticky-promotion/' . (int) $row['id'];
        $actions[] = '<a href="' . $url . '" onclick="if(window.layer){layer.open({type:2,title:\'种子促销\',area:[\'90%\',\'85%\'],content:this.href,end:function(){location.reload();}});return false;}"><b>★ 种子促销</b></a>';
        return $actions;
    }

    public function promotion(array $row): array
    {
        $p = $this->activePromotions()[(int) $row['id']] ?? null;
        if ($p) {
            $global = function_exists('get_global_sp_state') ? (int) get_global_sp_state() : 1;
            $row['sp_state'] = Math::strongestState([(int) $p->sp_state, (int) ($row['sp_state'] ?? 1), $global]);
            $row['promotion_time_type'] = 2;
            $row['promotion_until'] = $p->ends_at;
            $row['__ignore_global_sp_state'] = true;
            $row['__sticky_promotion'] = $p;
        }
        return $row;
    }

    public function listRows(array $rows, ...$unused): array
    {
        global $query, $orderby, $shouldUseMeili;
        $hasActive = !empty($this->activePromotions());
        // Reuse the site's fully filtered SQL without editing it or any core script.
        // Run early so subsequent plugins still receive and decorate the final page.
        if ($hasActive && !$shouldUseMeili && is_string($query) && is_string($orderby) && $orderby !== '') {
            $position = strrpos($query, $orderby);
            if ($position !== false) {
                $orderedQuery = substr_replace($query, $this->order($orderby), $position, strlen($orderby));
                $rows = array_map(fn ($row) => (array) $row, DB::select($orderedQuery));
            }
        } elseif ($hasActive && $shouldUseMeili) {
            // Keep Meilisearch result membership and pagination intact; sort within its page.
            $bids = array_map(fn ($p) => $p->bid, $this->activePromotions());
            $positions = array_flip(array_column($rows, 'id'));
            usort($rows, fn ($a, $b) => isset($bids[$b['id']]) <=> isset($bids[$a['id']])
                ?: ((float) ($bids[$b['id']] ?? 0) <=> (float) ($bids[$a['id']] ?? 0))
                ?: ($positions[$a['id']] <=> $positions[$b['id']]));
        }
        return array_map([$this, 'promotion'], $rows);
    }

    public function announced(array $torrent, array $user, array $request): void
    {
        global $self, $trueupthis, $is_cheater;
        if (empty($self) || !empty($is_cheater) || empty($trueupthis)) { return; }
        (new Service())->record($torrent, $user, (string) $trueupthis,
            (int) $self['last_action_unix_timestamp'], (int) $self['id'], (string) $request['uploaded']);
    }

    public function icon(string $html, array $row): string
    {
        return !empty($row['__sticky_promotion']) ? '<span title="置顶促销，高于一级置顶">🌟</span>&nbsp;' . $html : $html;
    }

    public function suffix(string $html, array $row): string
    {
        $p = $row['__sticky_promotion'] ?? null;
        return $p && (float) $p->reward > 0 ? $html . ' <a href="/web/sticky-promotion/' . (int) $row['id'] . '">[奖励 ' . htmlspecialchars($p->reward) . ' 魔力]</a>' : $html;
    }

    public function description(string $html, int $tid, int $uid): string
    {
        $p = $this->activePromotions()[$tid] ?? null;
        if (!$p) { return $html; }
        return '<div style="border:1px solid #d99a26;padding:12px;margin-bottom:12px"><b>★ 置顶促销</b> · 截止 '
            . htmlspecialchars($p->ends_at) . ' · 竞价 ' . htmlspecialchars($p->bid) . ' · 奖励 ' . htmlspecialchars($p->reward)
            . ' 魔力 / 前 ' . (int) $p->winners . ' 名 <a href="/web/sticky-promotion/' . $tid . '">查看排名与预估收益</a></div>' . $html;
    }

    public function order(string $order): string
    {
        // Before LIMIT: promotes across pages without bypassing search, category or visibility filters.
        $sub = "SELECT sp.bid FROM plugin_sticky_promotions sp WHERE sp.torrent_id=torrents.id AND sp.settled_at IS NULL AND sp.ends_at>'" . date('Y-m-d H:i:s') . "' LIMIT 1";
        return 'ORDER BY (' . $sub . ') IS NOT NULL DESC, (' . $sub . ') DESC, ' . preg_replace('/^ORDER BY\s+/i', '', $order);
    }

}
