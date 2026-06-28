<script setup lang="ts">
import { ref } from 'vue'
import AppShell from '../components/AppShell.vue'
import api from '../services/api'
import { useToastStore } from '../stores/toast'
import { pickErrorMessage, pickFieldErrors } from '../utils/laravelErrors'

const toast = useToastStore()

const loading = ref(false)
const error = ref<string | null>(null)
const success = ref<string | null>(null)
const fieldErrors = ref<Record<string, string[]>>({})

const form = ref({
  current_password: '',
  new_password: '',
  new_password_confirmation: '',
})

async function onSubmit() {
  error.value = null
  success.value = null
  fieldErrors.value = {}

  if (!form.value.current_password) {
    error.value = 'Password saat ini harus diisi'
    return
  }
  if (!form.value.new_password) {
    error.value = 'Password baru harus diisi'
    return
  }
  if (form.value.new_password.length < 6) {
    error.value = 'Password baru minimal 6 karakter'
    return
  }
  if (form.value.new_password !== form.value.new_password_confirmation) {
    error.value = 'Konfirmasi password baru tidak cocok'
    return
  }

  loading.value = true
  try {
    await api.post('/change-password', {
      current_password: form.value.current_password,
      new_password: form.value.new_password,
      new_password_confirmation: form.value.new_password_confirmation,
    })

    toast.success('Password berhasil diperbarui')
    success.value = 'Password Anda telah berhasil diperbarui!'
    
    // Clear form
    form.value = {
      current_password: '',
      new_password: '',
      new_password_confirmation: '',
    }
  } catch (err: any) {
    fieldErrors.value = pickFieldErrors(err)
    error.value = pickErrorMessage(err, 'Gagal memperbarui password')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <AppShell>
    <div class="max-w-md mx-auto space-y-6">
      <div>
        <h2 class="text-2xl font-black text-slate-900 tracking-tight">Ganti Password</h2>
        <p class="text-sm text-slate-500 mt-0.5">Perbarui kata sandi akun Anda untuk meningkatkan keamanan</p>
      </div>

      <!-- Success Alert -->
      <div v-if="success" class="flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-xs font-semibold text-emerald-800">
        <span class="text-base shrink-0">✅</span>
        <span>{{ success }}</span>
      </div>

      <!-- Error Alert -->
      <div v-if="error" class="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-xs font-semibold text-red-800">
        <span class="text-base shrink-0">⚠️</span>
        <span>{{ error }}</span>
      </div>

      <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm space-y-4">
        <form @submit.prevent="onSubmit" class="space-y-4">
          <!-- Current Password -->
          <div class="space-y-1">
            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider">Password Sekarang</label>
            <input
              v-model="form.current_password"
              type="password"
              autocomplete="current-password"
              placeholder="Masukkan password saat ini"
              class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-xs outline-none focus:ring-2 focus:ring-slate-300 font-medium"
              :class="fieldErrors.current_password ? 'border-red-300 bg-red-50' : ''"
            />
            <div v-if="fieldErrors.current_password?.length" class="mt-1 text-[10px] text-red-600 font-semibold">
              {{ fieldErrors.current_password[0] }}
            </div>
          </div>

          <!-- New Password -->
          <div class="space-y-1">
            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider">Password Baru</label>
            <input
              v-model="form.new_password"
              type="password"
              autocomplete="new-password"
              placeholder="Minimal 6 karakter"
              class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-xs outline-none focus:ring-2 focus:ring-slate-300 font-medium"
              :class="fieldErrors.new_password ? 'border-red-300 bg-red-50' : ''"
            />
            <div v-if="fieldErrors.new_password?.length" class="mt-1 text-[10px] text-red-600 font-semibold">
              {{ fieldErrors.new_password[0] }}
            </div>
          </div>

          <!-- New Password Confirmation -->
          <div class="space-y-1">
            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider">Konfirmasi Password Baru</label>
            <input
              v-model="form.new_password_confirmation"
              type="password"
              autocomplete="new-password"
              placeholder="Ulangi password baru"
              class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-xs outline-none focus:ring-2 focus:ring-slate-300 font-medium"
              :class="fieldErrors.new_password_confirmation ? 'border-red-300 bg-red-50' : ''"
            />
            <div v-if="fieldErrors.new_password_confirmation?.length" class="mt-1 text-[10px] text-red-600 font-semibold">
              {{ fieldErrors.new_password_confirmation[0] }}
            </div>
          </div>

          <div class="pt-2">
            <button
              type="submit"
              :disabled="loading"
              class="w-full rounded-xl bg-slate-900 py-3 text-xs font-bold text-white hover:bg-slate-800 transition-colors disabled:opacity-60"
            >
              {{ loading ? 'Memproses...' : '🔐 Perbarui Password' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </AppShell>
</template>
