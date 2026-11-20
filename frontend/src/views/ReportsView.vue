<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Bar } from 'vue-chartjs'
import { BarElement, CategoryScale, Chart as ChartJS, Legend, LinearScale, Tooltip } from 'chart.js'
import { BarChart3, Clock, FileDown, FileSpreadsheet, Hexagon, Route, Truck, Users, Building2 } from 'lucide-vue-next'
import { errorMessage, http } from '@/lib/http'
import { download } from '@/lib/download'
import { fDuration, fNumber, TZ } from '@/lib/format'
import { toast } from '@/lib/toast'
import type { Paginated, VehicleRow } from '@/lib/types'
import { useAuthStore } from '@/stores/auth'
import LoadingState from '@/components/LoadingState.vue'
import ErrorState from '@/components/ErrorState.vue'
import EmptyState from '@/components/EmptyState.vue'

ChartJS.register(BarElement, CategoryScale, LinearScale, Tooltip, Legend)

type Report = {
  type: string; title: string; from: string; to: string; summary: Record<string, string | number>
  chart: { type: 'bar' | 'hbar' | 'stacked'; labels: string[]; unit: string; datasets: { label: string; data: number[] }[] }
  columns: { key: string; label: string; align: 'left' | 'right'; format: 'duration' | 'km' | 'number' | 'speed' | null }[]
  rows: Record<string, string | number | null>[]
}
const REPORTS = [
  { key: 'mileage', label: 'Kilométrage', icon: Route }, { key: 'driving', label: 'Temps de conduite et d’arrêt', icon: Clock },
  { key: 'customers', label: 'Temps passé chez les clients', icon: Building2 }, { key: 'zones', label: 'Visites par zone', icon: Hexagon },
  { key: 'drivers', label: 'Activité par chauffeur', icon: Users }, { key: 'vehicles', label: 'Activité par camion', icon: Truck },
]

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const day = (d: Date) => new Intl.DateTimeFormat('en-CA', { timeZone: TZ }).format(d)
const now = new Date()
const PRESETS = computed(() => {
  const y = now.getFullYear(), m = now.getMonth()
  const monday = new Date(now); monday.setDate(now.getDate() - ((now.getDay() + 6) % 7))
  return [
    { key: 'month', label: 'Ce mois', from: day(new Date(y, m, 1)), to: day(now) },
    { key: 'week', label: 'Cette semaine', from: day(monday), to: day(now) },
    { key: 'last_month', label: 'Mois dernier', from: day(new Date(y, m - 1, 1)), to: day(new Date(y, m, 0)) },
    { key: '30d', label: '30 derniers jours', from: day(new Date(Date.now() - 29 * 86400000)), to: day(now) },
  ]
})
const f = reactive({ type: String(route.query.type ?? 'mileage'), preset: '30d', from: '', to: '', group: 'day', vehicle_ids: [] as number[] })
Object.assign(f, { from: PRESETS.value[3].from, to: PRESETS.value[3].to })
const vehicles = ref<VehicleRow[]>([])
const report = ref<Report | null>(null)
const loading = ref(false)
const error = ref('')

const params = () => ({ from: f.from, to: f.to, group: f.group, 'vehicle_ids[]': f.vehicle_ids.length ? f.vehicle_ids : undefined })
async function generate() {
  loading.value = true
  error.value = ''
  router.replace({ query: { type: f.type } })
  try {
    report.value = (await http.get<{ data: Report }>(`/api/reports/${f.type}`, { params: params() })).data.data
  } catch (e) {
    error.value = errorMessage(e, 'Impossible de générer le rapport.')
  } finally {
    loading.value = false
  }
}
watch(() => [f.type, f.group], generate)
function preset(p: { key: string; from: string; to: string }) { f.preset = p.key; f.from = p.from; f.to = p.to; generate() }
async function exportAs(format: 'pdf' | 'xlsx') {
  try { await download(`/api/reports/${f.type}/export`, { ...params(), format }, `rapport.${format}`) } catch (e) { toast(errorMessage(e, 'Export impossible.'), 'error') }
}

const COLORS = ['#1D4ED8', '#FDBA74']
const chartData = computed(() => ({
  labels: report.value?.chart.labels ?? [],
  datasets: (report.value?.chart.datasets ?? []).map((d, i) => ({ ...d, backgroundColor: COLORS[i], borderRadius: 4, maxBarThickness: 36 })),
}))
const chartOptions = computed(() => {
  const c = report.value?.chart
  const horizontal = c?.type === 'hbar' || c?.type === 'stacked'
  const value = { beginAtZero: true, stacked: c?.type === 'stacked', grid: { color: '#EEF2F6' }, title: { display: true, text: c?.unit ?? '' } }
  const cat = { stacked: c?.type === 'stacked', grid: { display: false } }
  return {
    indexAxis: horizontal ? 'y' : 'x', responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: (c?.datasets.length ?? 0) > 1, position: 'bottom' }, tooltip: { callbacks: { label: (x: { dataset: { label: string }; parsed: { x: number; y: number } }) => `${x.dataset.label} : ${fNumber(horizontal ? x.parsed.x : x.parsed.y, 1)} ${c?.unit}` } } },
    scales: horizontal ? { x: value, y: cat } : { x: cat, y: value },
  }
})
const chartHeight = computed(() => (report.value && ['hbar', 'stacked'].includes(report.value.chart.type) ? Math.max(220, report.value.chart.labels.length * 32 + 60) : 300))

const fmt = (v: unknown, format: string | null) => {
  if (v === null || v === undefined || v === '') return '—'
  if (format === 'duration') return fDuration(Number(v))
  if (format === 'km') return `${fNumber(Number(v))} km`
  if (format === 'number') return fNumber(Number(v))
  if (format === 'speed') return `${v} km/h`
  return String(v)
}
onMounted(async () => {
  generate()
  vehicles.value = (await http.get<Paginated<VehicleRow>>('/api/vehicles', { params: { per_page: 100 } }).catch(() => ({ data: { data: [] } }))).data.data as VehicleRow[]
})
</script>

<template>
  <div class="grid gap-5 lg:grid-cols-[260px_minmax(0,1fr)]">
    <nav class="card self-start p-2" aria-label="Rapports">
      <button v-for="r in REPORTS" :key="r.key" type="button" class="flex w-full items-center gap-3 rounded-[10px] px-3 py-2.5 text-left text-sm" :aria-current="f.type === r.key ? 'page' : undefined"
        :class="f.type === r.key ? 'bg-brand font-semibold text-white' : 'text-ink hover:bg-page'" @click="f.type = r.key"><component :is="r.icon" :size="17" />{{ r.label }}</button>
      <RouterLink v-if="auth.can('history.view')" to="/historique" class="flex items-center gap-3 rounded-[10px] px-3 py-2.5 text-sm text-ink no-underline hover:bg-page"><BarChart3 :size="17" />Historique des trajets</RouterLink>
    </nav>

    <div class="flex min-w-0 flex-col gap-5">
      <section class="card flex flex-wrap items-end gap-3 p-4">
        <div class="field">Période
          <div class="flex flex-wrap gap-1.5">
            <button v-for="p in PRESETS" :key="p.key" type="button" class="rounded-full border px-3 py-1.5 text-[13px] font-semibold" :aria-pressed="f.preset === p.key" :class="f.preset === p.key ? 'border-brand bg-brand text-white' : 'border-line hover:bg-page'" @click="preset(p)">{{ p.label }}</button>
          </div>
        </div>
        <label class="field">Du<input v-model="f.from" type="date" class="input" @change="f.preset = 'custom'" /></label>
        <label class="field">Au<input v-model="f.to" type="date" class="input" @change="f.preset = 'custom'" /></label>
        <label class="field min-w-44">Camions
          <select class="input" :value="f.vehicle_ids[0] ?? 0" @change="(e) => { const v = Number((e.target as HTMLSelectElement).value); f.vehicle_ids = v ? [v] : [] }">
            <option :value="0">Tous les camions ({{ vehicles.length }})</option><option v-for="v in vehicles" :key="v.id" :value="v.id">{{ v.name }}</option>
          </select>
        </label>
        <div v-if="f.type === 'mileage'" class="field">Regrouper par
          <div class="flex rounded-[10px] border border-line p-0.5">
            <button v-for="g in [['day', 'Jour'], ['week', 'Semaine'], ['month', 'Mois']]" :key="g[0]" type="button" class="rounded-lg px-3 py-1.5 text-[13px] font-semibold" :aria-pressed="f.group === g[0]" :class="f.group === g[0] ? 'bg-brand text-white' : 'text-muted hover:bg-page'" @click="f.group = g[0]">{{ g[1] }}</button>
          </div>
        </div>
        <button type="button" class="btn btn-primary" :disabled="loading" @click="generate">{{ loading ? 'Génération…' : 'Générer' }}</button>
        <div v-if="auth.can('reports.export') && report" class="ml-auto flex gap-2">
          <button type="button" class="btn btn-secondary" @click="exportAs('pdf')"><FileDown :size="16" />PDF</button>
          <button type="button" class="btn btn-secondary" @click="exportAs('xlsx')"><FileSpreadsheet :size="16" />Excel</button>
        </div>
      </section>

      <ErrorState v-if="error" :message="error" @retry="generate" />
      <LoadingState v-else-if="loading && !report" label="Calcul du rapport…" />
      <template v-else-if="report">
        <div class="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-3.5">
          <div v-for="(v, k) in report.summary" :key="k" class="card p-4"><div class="text-[13px] text-muted">{{ k }}</div><div class="num text-2xl font-bold">{{ v }}</div></div>
        </div>
        <section class="card p-5" :class="loading ? 'opacity-60' : ''">
          <h2 class="m-0 text-base font-bold">{{ report.title }}<template v-if="!f.vehicle_ids.length"> — toute la flotte</template></h2>
          <p class="m-0 mb-3 text-[13px] text-muted">du {{ new Date(report.from + 'T12:00').toLocaleDateString('fr-FR') }} au {{ new Date(report.to + 'T12:00').toLocaleDateString('fr-FR') }} · {{ report.chart.datasets.map((d) => d.label).join(' / ') }}</p>
          <EmptyState v-if="!report.chart.labels.length" title="Aucune donnée" message="Aucune activité sur cette période." />
          <div v-else :style="{ height: chartHeight + 'px' }" role="img" :aria-label="`${report.title} : graphique`"><Bar :data="chartData" :options="chartOptions as never" /></div>
        </section>
        <section class="card p-5">
          <div class="mb-3 flex items-center justify-between"><h2 class="m-0 text-base font-bold">Détail</h2><span class="text-[13px] text-muted">{{ report.rows.length }} lignes</span></div>
          <div class="-mx-5 overflow-x-auto">
            <table class="w-full min-w-[720px] border-collapse text-sm">
              <thead><tr class="bg-[#F8FAFC]"><th v-for="c in report.columns" :key="c.key" class="border-b border-line px-3.5 py-3 text-xs font-semibold tracking-wide text-muted uppercase first:pl-5 last:pr-5" :class="c.align === 'right' ? 'text-right' : 'text-left'">{{ c.label }}</th></tr></thead>
              <tbody>
                <tr v-for="(r, i) in report.rows" :key="i" class="hover:bg-page">
                  <td v-for="(c, j) in report.columns" :key="c.key" class="border-b border-line-soft px-3.5 py-2.5 first:pl-5 last:pr-5" :class="[c.align === 'right' ? 'num text-right' : '', j === 0 ? 'font-bold' : '']">{{ fmt(r[c.key], c.format) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </template>
    </div>
  </div>
</template>
