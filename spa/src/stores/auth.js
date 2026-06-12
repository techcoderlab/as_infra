import { defineStore } from 'pinia'
import api from '../utils/request'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    userRoles: [],
    token: localStorage.getItem('token') || null,
    permissions: [],
    activeTenant: null,
    enabledModules: [],
    moduleNav: [],
    // NEW: Track if we have already loaded data
    isBootstrapped: false,
  }),
  actions: {
    async bootstrap() {
      // If already loaded, don't run again (optimization)
      if (this.isBootstrapped) return

      const pluck = (arr, key) => arr.map((item) => item[key])

      try {
        const { data } = await api.get('/bootstrap')
        this.user = data.user
        this.userRoles = pluck(data.user?.roles || [], 'name')
        this.permissions = data.permissions
        this.activeTenant = data.active_tenant
        this.enabledModules = data.enabled_modules
        this.moduleNav = data.module_nav
        // console.log(data)
      } catch (error) {
        // If bootstrap fails (e.g. 401), logout
        await this.logout()
        // We don't throw here to prevent app crash, just let it fail gracefully
      } finally {
        // Mark as bootstrapped regardless of success/fail so we don't loop forever
        this.isBootstrapped = true
      }
    },
    async login(credentials) {
      const { data } = await api.post('/login', credentials)
      this.token = data.token
      localStorage.setItem('token', this.token)
      this.isBootstrapped = false // Reset so bootstrap runs again
      await this.bootstrap()
    },
    async logout() {
      this.user = null
      this.userRoles = []
      this.token = null
      this.permissions = []
      this.activeTenant = null
      this.enabledModules = []
      this.moduleNav = []
      this.isBootstrapped = false // Reset
      localStorage.removeItem('token')
    },
  },
})
