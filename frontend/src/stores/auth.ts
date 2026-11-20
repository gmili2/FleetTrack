import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { csrf, http } from '@/lib/http'
import { disconnectEcho } from '@/lib/echo'

export type AuthUser = {
  id: number; name: string; email: string; phone: string | null; initials: string
  role: string; role_label: string; permissions: string[]; last_login_at: string | null
  driver: { id: number; employee_number: string; badge_number: string } | null
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref<AuthUser | null>(null)
  const ready = ref(false)
  const perms = computed(() => new Set(user.value?.permissions ?? []))

  const can = (permission: string) => perms.value.has(permission)
  /** Entrée de menu accessible (l'espace chauffeur exige un compte lié à un chauffeur). */
  const canSee = (item: { permission: string; driverOnly?: boolean }) => can(item.permission) && (!item.driverOnly || !!user.value?.driver)

  /** Charge la session existante (cookie) au démarrage de l'application. */
  async function init() {
    if (ready.value) return
    try {
      const { data } = await http.get<{ data: AuthUser }>('/api/auth/me')
      user.value = data.data
    } catch {
      user.value = null
    } finally {
      ready.value = true
    }
  }

  async function login(email: string, password: string, remember: boolean) {
    await csrf()
    const { data } = await http.post<{ data: AuthUser }>('/api/auth/login', { email, password, remember })
    user.value = data.data
  }

  async function logout() {
    try { await http.post('/api/auth/logout') } finally { reset() }
  }

  function reset() {
    user.value = null
    disconnectEcho()
  }

  return { user, ready, can, canSee, init, login, logout, reset }
})
