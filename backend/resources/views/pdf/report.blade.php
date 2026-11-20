<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>{{ $r['title'] }}</title>
<style>
  @page { margin: 28px 32px; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #0F172A; }
  .brand { color: #0B1F3A; font-size: 16px; font-weight: bold; }
  .muted { color: #475569; }
  h1 { font-size: 18px; margin: 10px 0 2px; }
  .kpis { width: 100%; border-collapse: separate; border-spacing: 6px; margin: 10px -6px; }
  .kpis td { border: 1px solid #E2E8F0; padding: 8px; }
  .kpis b { display: block; font-size: 14px; margin-top: 2px; }
  table.list { width: 100%; border-collapse: collapse; margin-top: 6px; }
  table.list th { background: #0B1F3A; color: #fff; text-align: left; padding: 6px; font-size: 9px; text-transform: uppercase; }
  table.list td { padding: 5px 6px; border-bottom: 1px solid #EEF2F6; }
  .right { text-align: right; }
  .bar { background: #DBEAFE; height: 9px; } .bar span { display: block; height: 9px; background: #1D4ED8; }
  .bar2 span { display: inline-block; height: 9px; }
  footer { position: fixed; bottom: -14px; left: 0; right: 0; font-size: 8px; color: #64748B; }
</style>
</head>
<body>
  <footer>FleetTrack · {{ $r['title'] }} · généré le {{ $generatedAt->format('d/m/Y à H:i') }} · données de démonstration</footer>
  <div class="brand">FleetTrack</div>
  <h1>{{ $r['title'] }}</h1>
  <div class="muted">Période du {{ \Carbon\Carbon::parse($r['from'])->format('d/m/Y') }} au {{ \Carbon\Carbon::parse($r['to'])->format('d/m/Y') }}</div>

  <table class="kpis"><tr>
    @foreach($r['summary'] as $label => $value)<td><span class="muted">{{ $label }}</span><b>{{ $value }}</b></td>@endforeach
  </tr></table>

  @php($ds = $r['chart']['datasets'])
  @php($max = max(1, collect($ds)->map(fn ($d) => collect($d['data'])->max())->max(), $r['chart']['type'] === 'stacked' ? collect($ds[0]['data'])->zip($ds[1]['data'] ?? [])->map(fn ($p) => array_sum($p->all()))->max() : 0))
  <b>{{ $ds[0]['label'] }}{{ count($ds) > 1 ? ' / '.$ds[1]['label'] : '' }}</b>
  <table class="list">
    @foreach($r['chart']['labels'] as $i => $label)
      <tr>
        <td style="width:22%">{{ $label }}</td>
        <td style="width:58%">
          @if($r['chart']['type'] === 'stacked')
            <div class="bar2"><span style="background:#1D4ED8;width:{{ round($ds[0]['data'][$i] / $max * 100) }}%"></span><span style="background:#FDBA74;width:{{ round(($ds[1]['data'][$i] ?? 0) / $max * 100) }}%"></span></div>
          @else
            <div class="bar"><span style="width:{{ round($ds[0]['data'][$i] / $max * 100) }}%"></span></div>
          @endif
        </td>
        <td class="right">{{ number_format($ds[0]['data'][$i], 1, ',', ' ') }}@if(count($ds) > 1) / {{ number_format($ds[1]['data'][$i], 1, ',', ' ') }}@endif {{ $r['chart']['unit'] }}</td>
      </tr>
    @endforeach
  </table>

  <p style="margin-top:12px"><b>Détail</b> <span class="muted">· {{ count($r['rows']) }} lignes</span></p>
  <table class="list">
    <thead><tr>@foreach($r['columns'] as $c)<th class="{{ $c['align'] === 'right' ? 'right' : '' }}">{{ $c['label'] }}</th>@endforeach</tr></thead>
    <tbody>
      @foreach($r['rows'] as $row)
        <tr>@foreach($r['columns'] as $c)<td class="{{ $c['align'] === 'right' ? 'right' : '' }}">{{ $cell($row, $c) }}</td>@endforeach</tr>
      @endforeach
    </tbody>
  </table>
</body>
</html>
