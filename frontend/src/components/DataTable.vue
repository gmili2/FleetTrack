<script setup lang="ts" generic="T extends { id: number | string }">
import { computed } from 'vue'
import { ArrowDown, ArrowUp, ChevronsUpDown } from 'lucide-vue-next'
import LoadingState from './LoadingState.vue'
import ErrorState from './ErrorState.vue'
import EmptyState from './EmptyState.vue'

/**
 * Tableau de données piloté par le serveur : tri, pagination, états chargement / erreur / vide.
 * Chaque cellule peut être personnalisée avec le slot `cell-<clé>`.
 */
export type Column = { key: string; label: string; align?: 'left' | 'right' | 'center'; sortable?: boolean; class?: string }

const props = withDefaults(defineProps<{
  columns: Column[]; rows: T[]; loading?: boolean; error?: string; total?: number; page?: number; perPage?: number
  sort?: string; dir?: 'asc' | 'desc'; itemLabel?: string; emptyTitle?: string; emptyMessage?: string; clickable?: boolean; minWidth?: string
}>(), { loading: false, error: '', total: 0, page: 1, perPage: 10, dir: 'asc', itemLabel: 'éléments', emptyTitle: 'Aucun résultat', clickable: false, minWidth: '900px' })

const emit = defineEmits<{ 'update:page': [page: number]; 'update:sort': [sort: string, dir: 'asc' | 'desc']; retry: []; rowClick: [row: T] }>()

const lastPage = computed(() => Math.max(1, Math.ceil(props.total / props.perPage)))
const from = computed(() => (props.total ? (props.page - 1) * props.perPage + 1 : 0))
const to = computed(() => Math.min(props.total, props.page * props.perPage))
const pages = computed(() => {
  const n = lastPage.value
  const p = props.page
  const set = new Set([1, n, p - 1, p, p + 1].filter((x) => x >= 1 && x <= n))
  return [...set].sort((a, b) => a - b)
})

function toggleSort(c: Column) {
  if (!c.sortable) return
  emit('update:sort', c.key, props.sort === c.key && props.dir === 'asc' ? 'desc' : 'asc')
}
const align = (c: Column) => (c.align === 'right' ? 'text-right' : c.align === 'center' ? 'text-center' : 'text-left')
</script>

<template>
  <ErrorState v-if="error" :message="error" @retry="emit('retry')" />
  <LoadingState v-else-if="loading && !rows.length" />
  <EmptyState v-else-if="!rows.length" :title="emptyTitle" :message="emptyMessage" />
  <div v-else class="relative">
    <div v-if="loading" class="absolute inset-0 z-10 bg-white/50" aria-hidden="true" />
    <div class="-mx-5 overflow-x-auto">
      <table class="w-full border-collapse text-sm" :style="{ minWidth }">
        <thead>
          <tr class="bg-[#F8FAFC]">
            <th v-for="c in columns" :key="c.key" scope="col" :aria-sort="sort === c.key ? (dir === 'asc' ? 'ascending' : 'descending') : undefined"
              class="border-b border-line px-3.5 py-3 text-xs font-semibold tracking-wide whitespace-nowrap text-muted uppercase first:pl-5 last:pr-5" :class="[align(c), c.class]">
              <button v-if="c.sortable" type="button" class="inline-flex items-center gap-1 uppercase hover:text-ink" @click="toggleSort(c)">
                {{ c.label }}
                <ArrowUp v-if="sort === c.key && dir === 'asc'" :size="13" /><ArrowDown v-else-if="sort === c.key" :size="13" /><ChevronsUpDown v-else :size="13" class="opacity-50" />
              </button>
              <template v-else>{{ c.label }}</template>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="row.id" :class="clickable ? 'cursor-pointer hover:bg-page' : ''" @click="clickable && emit('rowClick', row)">
            <td v-for="c in columns" :key="c.key" class="border-b border-line-soft px-3.5 py-3 align-middle first:pl-5 last:pr-5" :class="[align(c), c.class]">
              <slot :name="`cell-${c.key}`" :row="row">{{ (row as Record<string, unknown>)[c.key] ?? '—' }}</slot>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-if="total > perPage" class="mt-4 flex flex-wrap items-center justify-between gap-3 text-[13px] text-muted">
      <span>Affichage {{ from }}–{{ to }} sur {{ total }} {{ itemLabel }}</span>
      <nav class="flex items-center gap-1.5" aria-label="Pagination">
        <button type="button" class="btn btn-secondary min-h-9 px-3" :disabled="page <= 1" @click="emit('update:page', page - 1)">Précédent</button>
        <template v-for="(p, i) in pages" :key="p">
          <span v-if="i && p - pages[i - 1] > 1" class="px-1">…</span>
          <button type="button" class="min-h-9 min-w-9 rounded-lg border px-2 font-semibold" :aria-current="p === page ? 'page' : undefined"
            :class="p === page ? 'border-brand bg-brand text-white' : 'border-line bg-white text-ink hover:bg-page'" @click="emit('update:page', p)">{{ p }}</button>
        </template>
        <button type="button" class="btn btn-secondary min-h-9 px-3" :disabled="page >= lastPage" @click="emit('update:page', page + 1)">Suivant</button>
      </nav>
    </div>
    <div v-else class="mt-3 text-[13px] text-muted">{{ total }} {{ itemLabel }}</div>
  </div>
</template>
