import { defineStore } from 'pinia'
import api from '../services/api'

export const useAuthStore = defineStore('auth', {
  state: () => ({ user: null as any }),
  getters: {
    isAuthenticated: (state) => !!state.user,
    isSuperAdmin: (state) => state.user?.role === 'superadmin',
    isOwner: (state) => state.user?.role === 'owner',
    isAdmin: (state) => state.user?.role === 'admin',
    isCashier: (state) => state.user?.role === 'cashier',
    isAdminOrOwner: (state) =>
      state.user?.role === 'admin' || state.user?.role === 'owner' || state.user?.role === 'superadmin',
  },
  actions: {
    async login(email: string, password: string) {
      const { data } = await api.post('/login', { email, password })
      localStorage.setItem('nessa_token', data.token)
      this.user = data.user
    },
    async fetchMe() {
      const { data } = await api.get('/me')
      this.user = data
    },
    async switchStore(storeId: number) {
      const { data } = await api.post('/tenant/switch-store', { store_id: storeId })
      this.user = data.user
    },
    async logout() {
      await api.post('/logout')
      localStorage.removeItem('nessa_token')
      this.user = null
    },
  },
})
