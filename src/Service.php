<?php

namespace NexusPlugin\StickyPromotion;

use App\Models\Setting;
use Nexus\Database\NexusDB as DB;

class Service
{
    public const DEFAULT_MULTIPLIERS = [2 => 1.5, 3 => 2, 4 => 3, 5 => 1.25, 6 => 2.5, 7 => 1.35];

    public function multipliers(): array
    {
        $result = [];
        foreach (self::DEFAULT_MULTIPLIERS as $state => $default) {
            $value = Setting::get('sticky_promotion.multiplier_' . $state, $default);
            $result[$state] = is_numeric($value) && is_finite((float) $value) && (float) $value >= 0
                && (float) $value <= 1000000 ? (float) $value : $default;
        }
        return $result;
    }

    public function active(int $torrentId): ?object
    {
        return DB::table('plugin_sticky_promotions')->where('torrent_id', $torrentId)
            ->whereNull('settled_at')->where('ends_at', '>', date('Y-m-d H:i:s'))->first();
    }

    public function quote(array $torrent, int $hours, int $state = 4): string
    {
        $multiplier = $this->multipliers()[$state] ?? $this->multipliers()[4];
        return number_format(ceil($this->hourRate($torrent) * $multiplier * $hours * 10) / 10, 2, '.', '');
    }

    public function hourRate(array $torrent): float
    {
        return Math::coefficient($torrent, Setting::get('bonus', []), time())
            * max(0, (float) Setting::get('sticky_promotion.base', 100));
    }

    public function purchase(int $uid, int $tid, array $input): int
    {
        if (Setting::get('sticky_promotion.enabled', 'yes') !== 'yes') {
            throw new \DomainException('置顶促销已暂停发起和追加。');
        }
        $hours = filter_var($input['hours'] ?? null, FILTER_VALIDATE_INT);
        $winners = filter_var($input['winners'] ?? null, FILTER_VALIDATE_INT);
        $state = (int) ($input['sp_state'] ?? 4);
        $key = (string) ($input['request_key'] ?? '');
        if ($hours === false || $hours < 0 || $hours > 8760 || $winners === false || $winners < 0 || $winners > 1000
            || !in_array($state, [2, 3, 4, 5, 6, 7], true) || !preg_match('/^[a-f0-9]{64}$/D', $key)) {
            throw new \DomainException('时长、奖励人数或请求参数不正确。');
        }
        foreach (['bid', 'reward'] as $field) {
            if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/D', (string) ($input[$field] ?? ''))
                || bccomp((string) $input[$field], bcdiv((string) $input[$field], '1', 1), 2) !== 0) {
                throw new \DomainException('魔力必须为非负金额，精度为 0.1 魔力。');
            }
        }
        return DB::transaction(function () use ($uid, $tid, $input, $hours, $winners, $state, $key) {
            // A torrent lock serializes first purchases as well as competing creators.
            $torrent = DB::table('torrents')->where('id', $tid)->lockForUpdate()->first();
            if (!$torrent || $torrent->banned === 'yes' || $torrent->visible !== 'yes'
                || (int) $torrent->approval_status === 2) {
                throw new \DomainException('该种子当前不可促销。');
            }
            $previous = DB::table('plugin_sticky_payments')->where('request_key', $key)->first();
            if ($previous) {
                if ((int) $previous->user_id !== $uid) {
                    throw new \DomainException('无效的请求。');
                }
                return (int) $previous->promotion_id;
            }
            $p = DB::table('plugin_sticky_promotions')->where('torrent_id', $tid)->whereNull('settled_at')->lockForUpdate()->first();
            if ($p && ($p->ends_at <= date('Y-m-d H:i:s') || (int) $p->user_id !== $uid)) {
                throw new \DomainException('已有促销尚待结算，或只有发起人可以追加。');
            }
            $min = max(1, (int) Setting::get('sticky_promotion.min_hours', 1));
            if ((!$p && $hours < $min) || ($p && $hours > 0 && $hours < $min)) {
                throw new \DomainException("购买或延长时长至少 {$min} 小时。");
            }
            if ($p && $winners < (int) $p->winners) {
                throw new \DomainException('奖励人数只能增加。');
            }
            if (bccomp(bcadd((string) ($p->reward ?? '0'), $input['reward'], 2), '0', 2) > 0 && $winners < 1) {
                throw new \DomainException('设置奖励魔力时必须设置奖励人数。');
            }
            $occupancy = $this->quote((array) $torrent, $hours, $p ? (int) $p->sp_state : $state);
            $cost = bcadd(bcadd($occupancy, $input['bid'], 2), $input['reward'], 2);
            if ($p && $hours === 0 && bccomp($cost, '0', 2) === 0 && $winners === (int) $p->winners) {
                throw new \DomainException('请填写追加时长、魔力或增加奖励人数。');
            }
            $user = DB::table('users')->where('id', $uid)->lockForUpdate()->first();
            if (!$user || $user->enabled !== 'yes' || $user->parked !== 'no' || (int) $user->class < (int) Setting::get('sticky_promotion.min_class', 0)) {
                throw new \DomainException('当前账号不能发起置顶促销。');
            }
            if (bccomp((string) $user->seedbonus, $cost, 2) < 0) {
                throw new \DomainException('魔力不足。');
            }
            $now = date('Y-m-d H:i:s');
            $end = date('Y-m-d H:i:s', ($p ? strtotime($p->ends_at) : time()) + $hours * 3600);
            $data = ['ends_at' => $end, 'winners' => $winners,
                'occupancy' => bcadd((string) ($p->occupancy ?? 0), $occupancy, 2),
                'bid' => bcadd((string) ($p->bid ?? 0), $input['bid'], 2),
                'reward' => bcadd((string) ($p->reward ?? 0), $input['reward'], 2)];
            if ($p) {
                $id = (int) $p->id;
                DB::table('plugin_sticky_promotions')->where('id', $id)->update($data);
            } else {
                $id = DB::table('plugin_sticky_promotions')->insertGetId($data + [
                    'torrent_id' => $tid, 'user_id' => $uid, 'sp_state' => $state, 'starts_at' => $now]);
            }
            DB::table('users')->where('id', $uid)->update(['seedbonus' => bcsub((string) $user->seedbonus, $cost, 2)]);
            DB::table('plugin_sticky_payments')->insert(['promotion_id' => $id, 'user_id' => $uid,
                'request_key' => $key, 'occupancy' => $occupancy, 'bid' => $input['bid'], 'reward' => $input['reward'], 'created_at' => $now]);
            return $id;
        }, 3);
    }

    /** Called only for tracker-accepted deltas; never uses a client's lifetime counters. */
    public function record(array $torrent, array $user, string $bytes, int $previousTime, int $peerId, string $counter): void
    {
        if (bccomp($bytes, '0', 0) <= 0) {
            return;
        }
        DB::transaction(function () use ($torrent, $user, $bytes, $previousTime, $peerId, $counter) {
            $p = DB::table('plugin_sticky_promotions')->where('torrent_id', $torrent['id'])->whereNull('settled_at')
                ->where('ends_at', '>', date('Y-m-d H:i:s'))->lockForUpdate()->first();
            if (!$p || (int) $p->user_id === (int) $user['id']) {
                return;
            }
            $seen = DB::table('plugin_sticky_peer_counters')->where('promotion_id', $p->id)->where('peer_id', $peerId)->first();
            $baseline = bcsub($counter, $bytes, 0);
            if ($seen && bccomp((string) $seen->counter, $baseline, 0) > 0) { $baseline = (string) $seen->counter; }
            $bytes = bcsub($counter, $baseline, 0);
            if (bccomp($bytes, '0', 0) <= 0) { return; }
            DB::table('plugin_sticky_peer_counters')->updateOrInsert(['promotion_id' => $p->id, 'peer_id' => $peerId], ['counter' => $counter]);
            // An announce crossing the start boundary is apportioned by elapsed time.
            $now = time();
            $elapsed = max(1, $now - $previousTime);
            $overlap = max(0, $now - max($previousTime, strtotime($p->starts_at)));
            $accepted = bcdiv(bcmul($bytes, (string) min($elapsed, $overlap), 0), (string) $elapsed, 0);
            if (bccomp($accepted, '0', 0) <= 0) {
                return;
            }
            DB::table('plugin_sticky_contributions')->insertOrIgnore(['promotion_id' => $p->id, 'user_id' => $user['id'], 'uploaded' => 0, 'paid' => 0]);
            DB::table('plugin_sticky_contributions')->where('promotion_id', $p->id)->where('user_id', $user['id'])->increment('uploaded', $accepted);
        }, 3);
    }

    public function ranking(object $p): array
    {
        $rows = DB::table('plugin_sticky_contributions')->where('promotion_id', $p->id)->where('uploaded', '>', 0)
            ->orderByDesc('uploaded')->orderBy('user_id')->get()->all();
        $uploads = [];
        foreach (array_slice($rows, 0, (int) $p->winners) as $row) {
            $uploads[$row->user_id] = (string) $row->uploaded;
        }
        $shares = Math::shares((string) $p->reward, $uploads);
        foreach ($rows as $row) {
            $row->estimated = $shares[$row->user_id] ?? '0.00';
        }
        return $rows;
    }

    public function settle(): void
    {
        $ids = DB::table('plugin_sticky_promotions')->whereNull('settled_at')->where('ends_at', '<=', date('Y-m-d H:i:s'))->orderBy('id')->limit(100)->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id) {
                $p = DB::table('plugin_sticky_promotions')->where('id', $id)->lockForUpdate()->first();
                if (!$p || $p->settled_at || $p->ends_at > date('Y-m-d H:i:s')) {
                    return;
                }
                $rows = $this->ranking($p);
                // Lock recipients in a stable order across settlements.
                usort($rows, fn ($a, $b) => $a->user_id <=> $b->user_id);
                foreach ($rows as $row) {
                    if (bccomp($row->estimated, '0', 2) <= 0) {
                        continue;
                    }
                    $user = DB::table('users')->where('id', $row->user_id)->lockForUpdate()->first();
                    if (!$user) {
                        continue; // Deleted users' shares are burned, never reassigned or refunded.
                    }
                    DB::table('users')->where('id', $user->id)->increment('seedbonus', $row->estimated);
                    DB::table('plugin_sticky_contributions')->where('id', $row->id)->update(['paid' => $row->estimated]);
                    DB::table('messages')->insert(['sender' => 0, 'receiver' => $user->id, 'added' => date('Y-m-d H:i:s'),
                        'subject' => '置顶促销奖励到账', 'msg' => "种子 #{$p->torrent_id} 的置顶促销已结束。你的有效上传为 {$row->uploaded} 字节，获得 {$row->estimated} 魔力。",
                        'unread' => 'yes', 'location' => 1, 'saved' => 'no']);
                    if (function_exists('clear_inbox_count_cache')) {
                        clear_inbox_count_cache($user->id);
                    }
                }
                DB::table('plugin_sticky_promotions')->where('id', $id)->update(['settled_at' => date('Y-m-d H:i:s')]);
            }, 3);
        }
    }
}
