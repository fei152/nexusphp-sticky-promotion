<?php

namespace NexusPlugin\StickyPromotion;

use App\Http\Controllers\Controller as BaseController;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Nexus\Database\NexusDB as DB;

class Controller extends BaseController
{
    private function data(int $torrent): array
    {
        $row = DB::table('torrents')->where('id', $torrent)->first();
        abort_unless($row && $row->visible === 'yes' && $row->banned !== 'yes' && (int) $row->approval_status !== 2, 404);
        $service = new Service();
        $p = DB::table('plugin_sticky_promotions')->where('torrent_id', $torrent)->orderByDesc('id')->first();
        $rank = $p ? $service->ranking($p) : [];
        $names = DB::table('users')->whereIn('id', array_column($rank, 'user_id'))->pluck('username', 'id');
        foreach ($rank as $r) { $r->username = $names[$r->user_id] ?? '已删除用户'; }
        return ['torrent' => $row, 'promotion' => $p, 'ranking' => $rank,
            'user' => Auth::guard('nexus-web')->user(), 'requestKey' => bin2hex(random_bytes(32)),
            'minHours' => max(1, (int) Setting::get('sticky_promotion.min_hours', 1)),
            'hourCost' => $service->quote((array) $row, 1, 4),
            'hourRate' => $service->hourRate((array) $row),
            'multipliers' => $service->multipliers(),
            'enabled' => Setting::get('sticky_promotion.enabled', 'yes') === 'yes'];
    }

    public function show(int $torrent)
    {
        (new Service())->settle();
        return view('sticky-promotion::modal', $this->data($torrent));
    }

    public function status(int $torrent)
    {
        $data = $this->data($torrent);
        return response()->json(['promotion' => $data['promotion'], 'ranking' => $data['ranking']]);
    }

    public function store(Request $request, int $torrent)
    {
        try {
            (new Service())->purchase((int) Auth::guard('nexus-web')->id(), $torrent, $request->all());
            return response()->json(['message' => '促销已保存，魔力已扣除。']);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
