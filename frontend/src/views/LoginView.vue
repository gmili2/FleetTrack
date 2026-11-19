<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { BarChart3, Eye, EyeOff, Hexagon, MapPin, ShieldCheck, Truck } from 'lucide-vue-next'
import { AxiosError } from 'axios'
import { errorMessage, fieldErrors } from '@/lib/http'
import { useAuthStore } from '@/stores/auth'
import LoginIllustration from '@/components/LoginIllustration.vue'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const email = ref('')
const password = ref('')
const remember = ref(true)
const showPwd = ref(false)
const busy = ref(false)
const error = ref('')
const locked = ref(false)
const errors = ref<Record<string, string>>({})

const DEMO = [
  ['superadmin@demo.ma', 'Super Administrateur'],
  ['admin@demo.ma', 'Administrateur'],
  ['flotte@demo.ma', 'Responsable de flotte'],
  ['operateur@demo.ma', 'Opérateur'],
] as const
const fillDemo = (e: string) => { email.value = e; password.value = 'password'; error.value = ''; errors.value = {} }

async function submit() {
  error.value = ''
  errors.value = {}
  locked.value = false
  busy.value = true
  try {
    await auth.login(email.value.trim(), password.value, remember.value)
    const redirect = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/') ? route.query.redirect : '/'
    await router.replace(redirect)
  } catch (e) {
    locked.value = e instanceof AxiosError && e.response?.status === 423
    errors.value = fieldErrors(e)
    error.value = errors.value.email ?? (Object.keys(errors.value).length ? '' : errorMessage(e, 'Connexion impossible. Réessayez.'))
    if (errors.value.email) delete errors.value.email
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen flex-wrap font-sans">
    <section class="hidden flex-[1_1_520px] flex-col gap-8 bg-navy p-12 text-white md:flex">
      <div class="flex items-center gap-2.5">
        <div class="flex size-9 items-center justify-center rounded-[10px] bg-brand"><Truck :size="20" color="#fff" :stroke-width="2" aria-hidden="true" /></div>
        <div class="text-lg font-bold tracking-tight">FleetTrack</div>
      </div>
      <div>
        <h1 class="m-0 max-w-[520px] text-[40px] leading-[1.15] font-bold tracking-tight">Votre flotte, en temps réel.</h1>
        <p class="mt-3.5 max-w-[480px] text-base leading-relaxed text-[#C7D3E3]">Positions GPS, trajets, temps passé chez vos clients et missions : tout au même endroit.</p>
      </div>
      <div class="relative min-h-[300px] flex-1 overflow-hidden rounded-2xl border border-navy-line">
        <LoginIllustration class="!absolute inset-0 !h-full" />
      </div>
      <div class="flex flex-wrap gap-6 text-sm text-[#C7D3E3]">
        <span class="inline-flex items-center gap-2"><MapPin :size="18" color="#86EFAC" aria-hidden="true" />Suivi en direct</span>
        <span class="inline-flex items-center gap-2"><Hexagon :size="18" color="#86EFAC" aria-hidden="true" />Zones et durées</span>
        <span class="inline-flex items-center gap-2"><BarChart3 :size="18" color="#86EFAC" aria-hidden="true" />Rapports PDF et Excel</span>
      </div>
    </section>

    <section class="flex flex-[1_1_420px] items-center justify-center bg-white px-6 py-12">
      <form class="flex w-full max-w-[400px] flex-col gap-5" novalidate @submit.prevent="submit">
        <div class="flex items-center gap-2.5 md:hidden">
          <div class="flex size-9 items-center justify-center rounded-[10px] bg-brand"><Truck :size="20" color="#fff" aria-hidden="true" /></div>
          <div class="text-lg font-bold text-ink">FleetTrack</div>
        </div>
        <div>
          <h2 class="m-0 text-[28px] font-bold text-ink">Connexion</h2>
          <p class="mt-2 text-[15px] text-muted">Accédez à votre espace de gestion de flotte.</p>
        </div>

        <div v-if="error" role="alert" class="rounded-[10px] border px-3.5 py-2.5 text-sm" :class="locked ? 'border-[#FED7AA] bg-[#FFF7ED] text-[#9A3412]' : 'border-[#FECACA] bg-[#FEF2F2] text-[#991B1B]'">
          {{ error }}
        </div>

        <label class="field">Adresse e-mail
          <input v-model="email" type="email" autocomplete="username" required class="input" :aria-invalid="!!error" placeholder="nom@entreprise.ma" />
        </label>
        <label class="field">Mot de passe
          <span class="relative">
            <input v-model="password" :type="showPwd ? 'text' : 'password'" autocomplete="current-password" required class="input pr-11" :aria-invalid="!!errors.password" />
            <button type="button" class="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-muted" :aria-label="showPwd ? 'Masquer le mot de passe' : 'Afficher le mot de passe'" @click="showPwd = !showPwd">
              <EyeOff v-if="showPwd" :size="18" /><Eye v-else :size="18" />
            </button>
          </span>
          <span v-if="errors.password" class="text-xs font-normal text-danger">{{ errors.password }}</span>
        </label>

        <div class="flex flex-wrap items-center justify-between gap-3">
          <label class="inline-flex min-h-11 items-center gap-2 text-sm text-ink">
            <input v-model="remember" type="checkbox" class="size-[18px] accent-brand" />Se souvenir de moi
          </label>
          <a href="#" class="text-sm font-semibold" @click.prevent="error = 'Contactez votre administrateur pour réinitialiser votre mot de passe.'">Mot de passe oublié ?</a>
        </div>

        <button type="submit" class="btn btn-primary h-12 text-[15px]" :disabled="busy || !email || !password">
          {{ busy ? 'Connexion…' : 'Se connecter' }}
        </button>

        <div class="flex items-center gap-2 text-[13px] text-muted">
          <ShieldCheck :size="16" color="#15803D" aria-hidden="true" class="flex-none" />
          Connexion chiffrée (HTTPS). Après 5 échecs, le compte est verrouillé 15 minutes.
        </div>

        <div class="rounded-xl border border-dashed border-field p-3.5 text-[13px]">
          <div class="mb-2 font-semibold text-ink">Comptes de démonstration <span class="font-normal text-muted">(mot de passe : password)</span></div>
          <div class="flex flex-wrap gap-2">
            <button v-for="[e, r] in DEMO" :key="e" type="button" class="rounded-lg border border-line bg-page px-2.5 py-1.5 text-left text-xs text-ink hover:border-brand" @click="fillDemo(e)">
              <span class="font-semibold">{{ r }}</span><br /><span class="text-muted">{{ e }}</span>
            </button>
          </div>
        </div>
      </form>
    </section>
  </div>
</template>
