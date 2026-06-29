<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { pickErrorMessage, pickFieldErrors } from '../utils/laravelErrors'
const email = ref('')
const password = ref('')
const router = useRouter()
const auth = useAuthStore()
const loading = ref(false)
const error = ref<string | null>(null)
const fieldErrors = ref<Record<string, string[]>>({})

const submit = async () => {
  loading.value = true
  error.value = null
  fieldErrors.value = {}
  try {
    await auth.login(email.value, password.value)
    router.push('/')
  } catch (e: any) {
    fieldErrors.value = pickFieldErrors(e)
    error.value = pickErrorMessage(e, 'Gagal login')
  } finally {
    loading.value = false
  }
}
</script>
<template>
  <div class="min-h-screen grid place-items-center">
    <form class="bg-white p-8 rounded-xl shadow w-[360px] space-y-3" @submit.prevent="submit">
      <h1 class="font-bold text-2xl">Login Nessa POS</h1>

      <div v-if="error" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
        {{ error }}
      </div>

      <div class="space-y-1">
        <input
          v-model="email"
          class="w-full border p-2 rounded outline-none focus:ring-2 focus:ring-slate-200"
          :class="fieldErrors.email ? 'border-red-300 bg-red-50' : ''"
          placeholder="Email"
        />
        <div v-if="fieldErrors.email?.length" class="text-xs text-red-700">{{ fieldErrors.email[0] }}</div>
      </div>

      <div class="space-y-1">
        <input
          v-model="password"
          class="w-full border p-2 rounded outline-none focus:ring-2 focus:ring-slate-200"
          :class="fieldErrors.password ? 'border-red-300 bg-red-50' : ''"
          type="password"
          placeholder="Password"
        />
        <div v-if="fieldErrors.password?.length" class="text-xs text-red-700">{{ fieldErrors.password[0] }}</div>
      </div>

      <button class="w-full bg-slate-900 text-white py-2 rounded disabled:opacity-60 font-semibold" :disabled="loading">
        {{ loading ? 'Masuk...' : 'Login' }}
      </button>

      <div class="relative flex items-center gap-2 pt-1">
        <div class="flex-1 h-px bg-slate-200"></div>
        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">atau</span>
        <div class="flex-1 h-px bg-slate-200"></div>
      </div>

      <router-link
        to="/register"
        class="group flex items-center justify-center gap-2 w-full rounded-lg py-2.5 px-4 font-bold text-sm text-white transition-all duration-200 shadow-md hover:shadow-lg hover:scale-[1.02] active:scale-[0.98]"
        style="background: linear-gradient(135deg, #f59e0b 0%, #ef4444 100%);"
      >
        <span class="text-lg">🏪</span>
        <span>Daftar Toko Baru (Owner)</span>
        <span class="ml-auto opacity-70 group-hover:translate-x-0.5 transition-transform">→</span>
      </router-link>
    </form>
  </div>
</template>
