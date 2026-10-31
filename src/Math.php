<?php

namespace NexusPlugin\StickyPromotion;

final class Math
{
    public static function coefficient(array $torrent, array $bonus, int $time): float
    {
        $weeks = max(0, $time - strtotime($torrent['added'])) / 604800;
        $a = (1 - exp(log(0.1) * $weeks / max(0.001, (float) ($bonus['tzero'] ?? 4))))
            * ($torrent['size'] / 1073741824)
            * (1 + sqrt(2) * exp(log(0.1) * (max(1, $torrent['seeders']) - 1) / max(0.001, (float) ($bonus['nzero'] ?? 7) - 1)));
        return max(0, (float) ($bonus['bzero'] ?? 100) * 2 / M_PI
            * atan($a / max(0.001, (float) ($bonus['l'] ?? 300))) + (float) ($bonus['perseeding'] ?? 0));
    }

    /** NP seedbonus has one decimal: integer tenths prevent payout rounding inflation. */
    public static function shares(string $reward, array $uploads): array
    {
        $total = '0';
        foreach ($uploads as $bytes) {
            $total = bcadd($total, (string) $bytes, 0);
        }
        if (bccomp($total, '0', 0) <= 0) {
            return [];
        }
        $cents = bcmul($reward, '10', 0);
        $remaining = $cents;
        $result = [];
        $last = array_key_last($uploads);
        foreach ($uploads as $uid => $bytes) {
            $share = $uid === $last ? $remaining : bcdiv(bcmul($cents, (string) $bytes, 0), $total, 0);
            $remaining = bcsub($remaining, $share, 0);
            $result[$uid] = bcdiv($share, '10', 2);
        }
        return $result;
    }

    public static function strongestState(array $states): int
    {
        $types = \App\Models\Torrent::$promotionTypes;
        $up = 1; $down = 1;
        foreach ($states as $state) {
            $up = max($up, $types[$state]['up_multiplier'] ?? 1);
            $down = min($down, $types[$state]['down_multiplier'] ?? 1);
        }
        $best = 4;
        foreach ($types as $state => $type) {
            if ($type['up_multiplier'] >= $up && $type['down_multiplier'] <= $down
                && ($type['down_multiplier'] > $types[$best]['down_multiplier']
                    || ($type['down_multiplier'] === $types[$best]['down_multiplier'] && $type['up_multiplier'] < $types[$best]['up_multiplier']))) {
                $best = $state;
            }
        }
        return $best;
    }
}
