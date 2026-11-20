import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { http } from '@/lib/http'
import { useEcho } from '@/lib/echo'
import type { Activity, LiveVehicle, MapZone } from '@/lib/types'

/**
 * État en direct de la flotte : chargé une fois via l'API, puis tenu à jour
 * par les événements Reverb du canal privé « fleet ».
 */
export const useFleetStore = defineStore('fleet', () => {
  const vehicles = ref<Record<number, LiveVehicle>>({})
  const zones = ref<MapZone[]>([])
  const activity = ref<Activity[]>([])
  const loading = ref(false)
  const error = ref('')
  const connection = ref<'connecting' | 'connected' | 'unavailable'>('connecting')
  const lastUpdate = ref<number | null>(null)
  const simulator = ref<{ running: boolean; heartbeat_at: string | null }>({ running: false, heartbeat_at: null })
  /** Horloge partagée (1 s) pour les « il y a 5 s ». */
  const now = ref(Date.now())
  let clock: ReturnType<typeof setInterval> | null = null
  let subscribed = false
  const listeners = new Set<(v: LiveVehicle) => void>()

  const list = computed(() => Object.values(vehicles.value))
  const counts = computed(() => ({
    all: list.value.length,
    moving: list.value.filter((v) => v.status === 'moving').length,
    stopped: list.value.filter((v) => v.status === 'stopped').length,
    offline: list.value.filter((v) => v.status === 'offline').length,
  }))

  async function load() {
    loading.value = true
    error.value = ''
    try {
      const [live, z] = await Promise.all([
        http.get<{ data: LiveVehicle[]; meta: { simulator: typeof simulator.value } }>('/api/live'),
        zones.value.length ? null : http.get<{ data: MapZone[] }>('/api/live/geofences'),
      ])
      vehicles.value = Object.fromEntries(live.data.data.map((v) => [v.id, v]))
      simulator.value = live.data.meta.simulator
      if (z) zones.value = z.data.data
      lastUpdate.value = Date.now()
    } catch {
      error.value = 'Impossible de charger la position des camions.'
    } finally {
      loading.value = false
    }
  }

  /** Abonnement temps réel (idempotent). */
  function start() {
    if (!clock) clock = setInterval(() => { now.value = Date.now() }, 1000)
    if (subscribed) return
    subscribed = true
    const echo = useEcho()
    const pusher = (echo.connector as unknown as { pusher: { connection: { state: string; bind: (e: string, cb: (s: { current: string }) => void) => void } } }).pusher
    connection.value = pusher.connection.state === 'connected' ? 'connected' : 'connecting'
    pusher.connection.bind('state_change', ({ current }) => {
      connection.value = current === 'connected' ? 'connected' : current === 'unavailable' || current === 'failed' ? 'unavailable' : 'connecting'
      if (current === 'connected') load() // resynchronise après une coupure
    })
    echo.private('fleet')
      .listen('.position.updated', (e: { vehicle: LiveVehicle }) => {
        vehicles.value[e.vehicle.id] = e.vehicle
        lastUpdate.value = Date.now()
        listeners.forEach((fn) => fn(e.vehicle))
      })
      .listen('.activity', (e: Activity) => {
        activity.value = [e, ...activity.value].slice(0, 50)
      })
  }

  function stop() {
    if (clock) clearInterval(clock)
    clock = null
    subscribed = false
  }

  /** Abonnement aux mises à jour individuelles (animation des marqueurs). */
  function onUpdate(fn: (v: LiveVehicle) => void) {
    listeners.add(fn)
    return () => listeners.delete(fn)
  }

  return { vehicles, zones, activity, loading, error, connection, lastUpdate, simulator, now, list, counts, load, start, stop, onUpdate }
})
