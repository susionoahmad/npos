<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../services/api'
import { pickErrorMessage, pickFieldErrors } from '../utils/laravelErrors'

const name = ref('')
const email = ref('')
const password = ref('')
const password_confirmation = ref('')

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
    const { data } = await api.post('/register', {
      name: name.value,
      email: email.value,
      password: password.value,
      password_confirmation: password_confirmation.value,
    })
    
    // Save token and update user state
    localStorage.setItem('nessa_token', data.token)
    auth.user = data.user
    
    // Go to setup wizard page
    router.push('/setup')
  } catch (e: any) {
    fieldErrors.value = pickFieldErrors(e)
    error.value = pickErrorMessage(e, 'Gagal mendaftar')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="min-h-screen grid place-items-center">
    <form class="bg-white p-8 rounded-xl shadow w-[380px] space-y-3" @submit.prevent="submit">
      <h1 class="font-bold text-2xl text-slate-800">Daftar Akun Owner</h1>
      <p class="text-xs text-slate-500 pb-2">Buat akun untuk mengelola usaha dan cabang toko Anda secara terpusat.</p>

      <div v-if="error" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
        {{ error }}
      </div>

      <div class="space-y-1">
        <label class="text-xs font-semibold text-slate-600">Nama Lengkap</label>
        <input
          v-model="name"
          class="w-full border p-2 rounded outline-none focus:ring-2 focus:ring-slate-200"
          :class="fieldErrors.name ? 'border-red-300 bg-red-50' : ''"
          placeholder="Contoh: Budi Santoso"
        />
        <div v-if="fieldErrors.name?.length" class="text-xs text-red-700">{{ fieldErrors.name[0] }}</div>
      </div>

      <div class="space-y-1">
        <label class="text-xs font-semibold text-slate-600">Alamat Email</label>
        <input
          v-model="email"
          type="email"
          class="w-full border p-2 rounded outline-none focus:ring-2 focus:ring-slate-200"
          :class="fieldErrors.email ? 'border-red-300 bg-red-50' : ''"
          placeholder="Contoh: owner@toko.com"
        />
        <div v-if="fieldErrors.email?.length" class="text-xs text-red-700">{{ fieldErrors.email[0] }}</div>
      </div>

      <div class="space-y-1">
        <label class="text-xs font-semibold text-slate-600">Password</label>
        <input
          v-model="password"
          type="password"
          class="w-full border p-2 rounded outline-none focus:ring-2 focus:ring-slate-200"
          :class="fieldErrors.password ? 'border-red-300 bg-red-50' : ''"
          placeholder="Minimal 6 karakter"
        />
        <div v-if="fieldErrors.password?.length" class="text-xs text-red-700">{{ fieldErrors.password[0] }}</div>
      </div>

      <div class="space-y-1">
        <label class="text-xs font-semibold text-slate-600">Konfirmasi Password</label>
        <input
          v-model="password_confirmation"
          type="password"
          class="w-full border p-2 rounded outline-none focus:ring-2 focus:ring-slate-200"
          :class="fieldErrors.password_confirmation ? 'border-red-300 bg-red-50' : ''"
          placeholder="Ulangi password"
        />
        <div v-if="fieldErrors.password_confirmation?.length" class="text-xs text-red-700">{{ fieldErrors.password_confirmation[0] }}</div>
      </div>

      <button class="w-full bg-slate-900 text-white py-2.5 rounded font-semibold disabled:opacity-60 transition-all hover:bg-slate-800" :disabled="loading">
        {{ loading ? 'Mendaftar...' : 'Daftar & Masuk' }}
      </button>

      <div class="text-center pt-2 border-t border-slate-100">
        <router-link to="/login" class="text-xs text-slate-600 hover:text-slate-900 font-semibold hover:underline">
          Sudah punya akun? Login di sini
        </router-link>
      </div>
    </form>
  </div>
</template>
