<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Visites — {{ $zone->name }}</title>
<style>
  @page { margin: 28px 32px; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #0F172A; }
  .brand { color: #0B1F3A; font-size: 16px; font-weight: bold; }
  .muted { color: #475569; }
  h1 { font-size: 18px; margin: 12px 0 2px; }
  .kpis { width: 100%; border-collapse: separate; border-spacing: 6px; margin: 12px -6px; }
  .kpis td { border: 1px solid #E2E8F0; border-radius: 6px; padding: 8px; width: 25%; }
  .kpis b { display: block; font-size: 15px; margin-top: 2px; }
  table.list { width: 100%; border-collapse: collapse; margin-top: 8px; }
  table.list th { background: #0B1F3A; color: #fff; text-align: left; padding: 6px; font-size: 9px; text-transform: uppercase; }
  table.list td { padding: 5px 6px; border-bottom: 1px solid #EEF2F6; }
  .right { text-align: right; }
  .ongoing { color: #1D4ED8; font-weight: bold; }
  .bar { background: #DBEAFE; height: 8px; }
  .bar span { display: block; background: #1D4ED8; height: 8px; }
  footer { position: fixed; bottom: -14px; left: 0; right: 0; font-size: 8px; color: #64748B; }
</style>
</head>
<body>
  <footer>FleetTrack · généré le {{ $generatedAt->format('d/m/Y à H:i') }} · données de démonstration</footer>
  <div class="brand">FleetTrack</div>
  <h1>Visites — {{ $zone->name }}</h1>
  <div class="muted">
    {{ $zone->type->label() }} · {{ $zone->shape === \App\Enums\GeofenceShape::Circle ? 'Cercle de '.$zone->radius_m.' m' : 'Polygone' }}
    @if($zone->address) · {{ $zone->address }} @endif
    @if($zone->customer) · client {{ $zone->customer->code }} @endif
    · période du {{ $from->format('d/m/Y') }} au {{ $to->format('d/m/Y') }}
  </div>

  <table class="kpis"><tr>
    <td><span class="muted">Visites</span><b>{{ $stats['visits'] }}</b><span class="muted">dont {{ $stats['ongoing'] }} en cours</span></td>
    <td><span class="muted">Durée moyenne</span><b>{{ \App\Services\Tracking\ZoneVisitReport::fmt($stats['avg_seconds']) }}</b><span class="muted">médiane {{ \App\Services\Tracking\ZoneVisitReport::fmt($stats['median_seconds']) }}</span></td>
    <td><span class="muted">Temps total</span><b>{{ \App\Services\Tracking\ZoneVisitReport::fmt($stats['total_seconds']) }}</b><span class="muted">sur la période</span></td>
    <td><span class="muted">Camions distincts</span><b>{{ $stats['vehicles'] }}</b><span class="muted">sur {{ $stats['fleet_size'] }}</span></td>
  </tr></table>

  <b>Temps passé dans la zone par camion</b>
  @php($max = max(1, collect($stats['by_vehicle'])->max('seconds')))
  <table class="list">
    @foreach($stats['by_vehicle'] as $b)
      <tr><td style="width:18%"><b>{{ $b['vehicle'] }}</b></td><td style="width:52%"><div class="bar"><span style="width: {{ round($b['seconds'] / $max * 100) }}%"></span></div></td>
        <td class="right">{{ \App\Services\Tracking\ZoneVisitReport::fmt($b['seconds']) }} · {{ $b['visits'] }} visite(s)</td></tr>
    @endforeach
  </table>

  <p style="margin-top:14px"><b>Historique des visites</b> <span class="muted">· {{ count($rows) }} visites, plus récentes d’abord</span></p>
  <table class="list">
    <thead><tr><th>Camion</th><th>Chauffeur</th><th>Entrée</th><th>Sortie</th><th class="right">Durée</th><th>Mission</th></tr></thead>
    <tbody>
    @foreach($rows as $r)
      <tr>
        <td><b>{{ $r['vehicle']['name'] ?? '—' }}</b></td><td>{{ $r['driver'] ?? '—' }}</td>
        <td>{{ $fmt($r['entered_at']) }}</td><td class="{{ $r['ongoing'] ? 'ongoing' : '' }}">{{ $fmt($r['exited_at']) }}</td>
        <td class="right">{{ \App\Services\Tracking\ZoneVisitReport::fmt($r['duration_seconds']) }}</td><td>{{ $r['mission']['reference'] ?? '—' }}</td>
      </tr>
    @endforeach
    </tbody>
  </table>
</body>
</html>
