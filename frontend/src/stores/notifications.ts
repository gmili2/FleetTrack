import { defineStore } from 'pinia'
import { ref } from 'vue'
import { http } from '@/lib/http'
import { useEcho } from '@/lib/echo'
import type { AppNotification } from '@/lib/types'

export const useNotificationStore = defineStore('notifications', () => {
  const items = ref<AppNotification[]>([])
  const unread = ref(0)
  const loading = ref(false)
  let listening: number | null = null

  async function load() {
    loading.value = true
    try {
      const { data } = await http.get<{ data: AppNotification[]; meta: { unread: number } }>('/api/notifications')
      items.value = data.data
      unread.value = data.meta.unread
    } catch { /* la cloche reste vide */ } finally {
      loading.value = false
    }
  }

  function listen(userId: number) {
    if (listening === userId) return
    listening = userId
    useEcho().private(`App.Models.User.${userId}`).notification((n: Omit<AppNotification, 'read'> & { type?: string }) => {
      items.value = [{ ...n, read: false, created_at: n.created_at ?? new Date().toISOString() } as AppNotification, ...items.value].slice(0, 30)
      unread.value++
    })
  }

  async function markAllRead() {
    unread.value = 0
    items.value = items.value.map((n) => ({ ...n, read: true }))
    await http.post('/api/notifications/read-all').catch(() => undefined)
  }

  async function markRead(n: AppNotification) {
    if (n.read) return
    n.read = true
    unread.value = Math.max(0, unread.value - 1)
    await http.post(`/api/notifications/${n.id}/read`).catch(() => undefined)
  }

  function reset() {
    items.value = []
    unread.value = 0
    listening = null
  }

  return { items, unread, loading, load, listen, markAllRead, markRead, reset }
})
