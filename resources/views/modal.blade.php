<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>种子促销</title>
    <style>
        :root{color-scheme:light}*{box-sizing:border-box}body{margin:0;padding:22px;background:#f3f6fa;color:#26364c;font:14px/1.6 system-ui,sans-serif}h1{font-size:23px;margin:0 0 4px}h2{font-size:17px;margin:0 0 14px}.muted{color:#65758b}.card{background:white;border:1px solid #dce3eb;border-radius:12px;padding:20px;margin-top:16px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}label{display:block;font-weight:600}input,select{width:100%;padding:9px 12px;border:1px solid #bdcbdc;border-radius:7px;font:inherit;margin-top:5px;background:#fff}small{font-weight:400;color:#65758b;display:block;margin-top:3px}.summary{background:#f0f6ff;padding:14px;border-radius:8px;margin:18px 0}.summary strong{font-size:21px;color:#255bbe}button{padding:10px 22px;border:0;border-radius:7px;background:#285fc4;color:#fff;font:inherit;cursor:pointer}button:disabled{opacity:.5;cursor:default}table{width:100%;border-collapse:collapse}td,th{text-align:left;padding:10px 8px;border-bottom:1px solid #e2e8ef}th{color:#65758b;font-weight:500}.scroll{overflow:auto}.notice{background:#fff8e6;padding:12px;border-radius:8px}.message{padding:10px;color:#b42318;white-space:pre-wrap}a{color:#285fc4}@media(max-width:540px){body{padding:12px}.grid{grid-template-columns:1fr}.card{padding:14px}}
    </style>
</head>
<body>
@php
    $active = $promotion && !$promotion->settled_at && $promotion->ends_at > date('Y-m-d H:i:s');
    $pending = $promotion && !$promotion->settled_at && !$active;
    $mine = $active && (int) $promotion->user_id === (int) $user->id;
    $canBuy = $enabled && !$pending && (!$active || $mine);
@endphp
<h1>★ 种子促销</h1>
<div class="muted">{{ $torrent->name }}</div>
<div class="card">
    <h2>{{ $active ? '当前促销' : '促销说明' }}</h2>
    @if($active)
        <p>{{ \App\Models\Torrent::$promotionTypes[$promotion->sp_state]['text'] }} · 截止 {{ $promotion->ends_at }}</p>
        <div class="grid"><div>竞价魔力：<b>{{ $promotion->bid }}</b></div><div>奖励池：<b>{{ $promotion->reward }}</b> 魔力 / 前 {{ $promotion->winners }} 名</div></div>
    @endif
    <p class="muted">促销种子高于一级置顶，同类按竞价魔力从高到低排序。费用由占位、竞价和奖励魔力组成；奖励按有效上传量排名，由前几名按上传比例瓜分。发起人不参与奖励。</p>
    <div class="notice">购买后不退款，下载未完成也不退款。结束前发起人可追加时长、竞价、奖励和奖励人数；促销类型在发起后固定。无人有效上传时奖励不退回。</div>
</div>
@if($canBuy)
<form id="promotionForm" class="card">
    <h2>{{ $mine ? '追加促销' : '发起促销' }}</h2>
    <input type="hidden" name="request_key" value="{{ $requestKey }}">
    <div class="grid">
        <label>促销种类
            <select name="sp_state" {{ $mine ? 'disabled' : '' }}>
            @foreach([2 => 'Free · 免费下载', 3 => '2X · 双倍上传', 4 => '2X Free · 双倍上传 + 免费下载', 5 => '50% · 半价下载', 6 => '2X 50% · 双倍上传 + 半价下载', 7 => '30% · 30% 下载'] as $value => $label)
                <option value="{{ $value }}" @selected(($mine ? $promotion->sp_state : 4) == $value)>{{ $label }}</option>
            @endforeach
            </select>
        </label>
        <label>{{ $mine ? '追加时长' : '促销时长' }}（小时）<input name="hours" type="number" min="{{ $mine ? 0 : $minHours }}" max="8760" step="1" value="{{ $mine ? 0 : max(24, $minHours) }}" required><small>每次购买或延长至少 {{ $minHours }} 小时；追加魔力时可以填 0。</small></label>
        <label>{{ $mine ? '追加竞价魔力' : '竞价魔力' }}<input name="bid" type="number" min="0" max="999999999" step="0.1" value="0" required><small>竞价越高，种子排序越靠前。</small></label>
        <label>{{ $mine ? '追加奖励魔力' : '奖励魔力' }}<input name="reward" type="number" min="0" max="999999999" step="0.1" value="0" required><small>用于奖励促销期间提供上传的用户。</small></label>
        <label>奖励人数（{{ $mine ? '追加后总人数' : '前几名' }}）<input name="winners" type="number" min="{{ $mine ? $promotion->winners : 0 }}" max="1000" step="1" value="{{ $mine ? $promotion->winners : 3 }}" required></label>
        <div><label>当前魔力</label><p>{{ number_format((float) $user->seedbonus, 2) }}</p></div>
    </div>
    <div class="summary">占位 <span id="occupancy">0</span> + 竞价 <span id="bidCost">0</span> + 奖励 <span id="rewardCost">0</span> = <strong id="total">0</strong> 魔力<small>占位费会根据促销种类变化；提交时服务端会重新计算。当前 2X Free 每小时约 {{ $hourCost }} 魔力。</small></div>
    <div id="message" role="alert" class="message"></div>
    <button type="submit">{{ $mine ? '确认追加并扣除魔力' : '确认购买并扣除魔力' }}</button>
</form>
@else
    <div class="card muted">{{ !$enabled ? '站点已暂停发起和追加促销。' : ($pending ? '促销已到期，正在等待结算。' : '只有发起人可以追加当前促销。') }}</div>
@endif
@if($promotion)
<div class="card">
    <h2>参与排名 <span id="count" class="muted">（{{ count($ranking) }} 人）</span></h2>
    <p class="muted">{{ $promotion->settled_at ? '已结算，显示实际到账奖励。' : '每 15 秒更新。预估收益随排名变化，以最终结算为准。' }} 上传量按原始字节计算，不含 2X 等倍率；跨越开始时间的汇报按时间比例计入，结束后汇报不计入。</p>
    <div class="scroll"><table><thead><tr><th>排名</th><th>用户</th><th>有效上传</th><th>{{ $promotion->settled_at ? '实际奖励' : '预估奖励' }}</th></tr></thead><tbody id="ranking">
        @forelse($ranking as $row)<tr><td>{{ $loop->iteration }}</td><td>{{ $row->username }}</td><td>{{ number_format($row->uploaded / 1073741824, 3) }} GB</td><td>{{ $promotion->settled_at ? $row->paid : $row->estimated }} 魔力</td></tr>@empty<tr><td colspan="4">暂无有效上传</td></tr>@endforelse
    </tbody></table></div>
</div>
@endif
<p class="muted"><a href="/details.php?id={{ $torrent->id }}" target="_top">返回种子详情</a></p>
<script>
(() => {
    const form = document.getElementById('promotionForm');
    if (form) {
        const field = name => form.elements.namedItem(name);
        const update = () => {
            const multipliers = @json($multipliers ?? \NexusPlugin\StickyPromotion\Service::DEFAULT_MULTIPLIERS);
            const multiplier = Number(multipliers[field('sp_state').value] ?? multipliers[4]);
            const occupancy = Math.ceil(Number(@json($hourRate ?? $hourCost)) * multiplier * Number(field('hours').value) * 10) / 10;
            const bid = Number(field('bid').value), reward = Number(field('reward').value);
            document.getElementById('occupancy').textContent = occupancy.toFixed(2);
            document.getElementById('bidCost').textContent = bid.toFixed(2);
            document.getElementById('rewardCost').textContent = reward.toFixed(2);
            document.getElementById('total').textContent = (occupancy + bid + reward).toFixed(2);
        };
        form.addEventListener('input', update);
        form.addEventListener('change', update);
        update();
        form.addEventListener('submit', async event => {
            event.preventDefault();
            const button = form.querySelector('button'), message = document.getElementById('message');
            if (button.disabled) return;
            button.disabled = true; message.textContent = '';
            try {
                const response = await fetch(location.pathname, {method:'POST', credentials:'same-origin', headers:{'Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:new FormData(form)});
                const data = await response.json();
                if (!response.ok) throw new Error(data.message || '提交失败，请刷新后重试。');
                message.style.color = '#16803c'; message.textContent = data.message;
                location.reload();
            } catch (error) { message.textContent = error.message; button.disabled = false; }
        });
    }
    @if($promotion && !$promotion->settled_at)
    const timer = setInterval(async () => {
        try {
            const response = await fetch(location.pathname + '/status', {headers:{'Accept':'application/json'}});
            if (!response.ok) return;
            const data = await response.json();
            if (data.promotion && data.promotion.settled_at) { clearInterval(timer); location.reload(); return; }
            const body = document.getElementById('ranking'); body.replaceChildren();
            document.getElementById('count').textContent = '（' + data.ranking.length + ' 人）';
            data.ranking.forEach((row, index) => {
                const tr = document.createElement('tr');
                [index + 1,row.username,(Number(row.uploaded)/1073741824).toFixed(3)+' GB',row.estimated+' 魔力'].forEach(value => {const td=document.createElement('td');td.textContent=value;tr.appendChild(td);});
                body.appendChild(tr);
            });
            if (!data.ranking.length) {const tr=body.insertRow();const td=tr.insertCell();td.colSpan=4;td.textContent='暂无有效上传';}
        } catch (_) {}
    }, 15000);
    @endif
})();
</script>
</body></html>
