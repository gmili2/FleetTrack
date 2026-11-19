import { createApp } from 'vue'
import { createPinia } from 'pinia'
import '@fontsource-variable/inter'
import 'leaflet/dist/leaflet.css'
import './style.css'
import App from './App.vue'
import { router } from './router'
import { setUnauthorizedHandler } from './lib/http'
import { useAuthStore } from './stores/auth'

const app = createApp(App)
app.use(createPinia())
app.use(router)

// Session expirée : retour à l'écran de connexion.
setUnauthorizedHandler(() => {
  useAuthStore().reset()
  if (router.currentRoute.value.name !== 'login') {
    router.replace({ name: 'login', query: { redirect: router.currentRoute.value.fullPath } })
  }
})

app.mount('#app')
