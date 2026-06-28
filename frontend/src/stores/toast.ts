import { defineStore } from 'pinia'

type Variant = 'success' | 'error'

export const useToastStore = defineStore('toast', {
  state: () => ({
    message: '' as string,
    title: '' as string,
    variant: 'success' as Variant,
    timeoutId: null as number | null,
  }),
  actions: {
    show(message: string, variant: Variant = 'success', title = '') {
      this.message = message
      this.title = title
      this.variant = variant
      if (this.timeoutId) {
        window.clearTimeout(this.timeoutId)
      }
      this.timeoutId = window.setTimeout(() => {
        this.clear()
      }, 3000)
    },
    success(message: string, title = 'Berhasil') {
      this.show(message, 'success', title)
    },
    error(message: string, title = 'Gagal') {
      this.show(message, 'error', title)
    },
    clear() {
      this.message = ''
      this.title = ''
      this.timeoutId && window.clearTimeout(this.timeoutId)
      this.timeoutId = null
    },
  },
})

