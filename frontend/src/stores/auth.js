import { reactive } from 'vue'
import {
  jarvisApi,
  getStoredToken,
  setStoredToken,
  onUnauthorized
} from '@/services/api'

export const authStore = reactive({
  token: getStoredToken(),
  user: null,
  loading: false,
  error: '',

  get isAuthenticated() {
    return Boolean(this.token)
  },

  async restoreSession() {
    if (!this.token) {
      return false
    }
    try {
      this.user = await jarvisApi.me()
      return true
    } catch {
      this.clearSession()
      return false
    }
  },

  async login(email, password) {
    this.loading = true
    this.error = ''
    try {
      const data = await jarvisApi.login(email, password)
      this.token = data.token
      this.user = data.user || null
      setStoredToken(data.token)
      return true
    } catch (err) {
      this.error = err.message || 'Échec de connexion.'
      return false
    } finally {
      this.loading = false
    }
  },

  async logout() {
    try {
      if (this.token) {
        await jarvisApi.logout()
      }
    } catch {
      // Ignore network errors on logout
    } finally {
      this.clearSession()
    }
  },

  clearSession() {
    this.token = ''
    this.user = null
    setStoredToken('')
  }
})

onUnauthorized(() => {
  authStore.clearSession()
})
