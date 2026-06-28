<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../services/api'
import { pickErrorMessage, pickFieldErrors } from '../utils/laravelErrors'

const router = useRouter()
const auth = useAuthStore()

// State
const currentStep = ref(1)
const totalSteps = 2

const company_name = ref('')
const store_name = ref('')
const store_address = ref('')
const store_phone = ref('')

const loading = ref(false)
const error = ref<string | null>(null)
const fieldErrors = ref<Record<string, string[]>>({})

const nextStep = () => {
  if (currentStep.value === 1) {
    if (!company_name.value.trim()) {
      fieldErrors.value = { company_name: ['Nama perusahaan wajib diisi.'] }
      return
    }
    fieldErrors.value = {}
    currentStep.value = 2
  }
}

const prevStep = () => {
  if (currentStep.value === 2) {
    currentStep.value = 1
  }
}

const submit = async () => {
  if (!store_name.value.trim()) {
    fieldErrors.value = { store_name: ['Nama toko wajib diisi.'] }
    return
  }

  loading.value = true
  error.value = null
  fieldErrors.value = {}

  try {
    const { data } = await api.post('/setup-wizard', {
      company_name: company_name.value.trim(),
      store_name: store_name.value.trim(),
      store_address: store_address.value.trim(),
      store_phone: store_phone.value.trim(),
    })

    // Update the local auth state with the complete user object (with tenant and store)
    auth.user = data.user
    
    // Redirect to home/dashboard
    router.push('/')
  } catch (err: any) {
    fieldErrors.value = pickFieldErrors(err)
    error.value = pickErrorMessage(err, 'Gagal menyelesaikan pengaturan awal')
    // If error was in step 1, revert to step 1
    if (fieldErrors.value.company_name) {
      currentStep.value = 1
    }
  } finally {
    loading.value = false
  }
}

const progressPercentage = computed(() => {
  return (currentStep.value / totalSteps) * 100
})
</script>

<template>
  <div class="min-h-screen bg-slate-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl border border-slate-100 max-w-lg w-full overflow-hidden transition-all duration-300">
      
      <!-- Progress Bar Area -->
      <div class="bg-slate-900 px-6 py-4 text-white">
        <h1 class="text-xl font-bold tracking-wide">Pengaturan Awal Nessa POS</h1>
        <p class="text-xs text-slate-300 mt-1">Lengkapi data usaha Anda untuk mulai mengelola transaksi penjualan.</p>
        
        <!-- Progress bar visual -->
        <div class="mt-4">
          <div class="flex justify-between text-[10px] font-semibold text-slate-400 mb-1">
            <span>LANGKAH {{ currentStep }} DARI {{ totalSteps }}</span>
            <span>{{ Math.round(progressPercentage) }}% SELESAI</span>
          </div>
          <div class="h-1.5 w-full bg-slate-800 rounded-full overflow-hidden">
            <div 
              class="h-full bg-emerald-500 transition-all duration-500 ease-out"
              :style="{ width: `${progressPercentage}%` }"
            ></div>
          </div>
        </div>
      </div>

      <!-- Main Form Area -->
      <div class="p-6 md:p-8 space-y-6">
        
        <div v-if="error" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
          {{ error }}
        </div>

        <!-- STEP 1: Profil Perusahaan -->
        <div v-if="currentStep === 1" class="space-y-4 animate-fade-in">
          <div>
            <h2 class="text-lg font-bold text-slate-800">1. Profil Perusahaan & Bisnis</h2>
            <p class="text-xs text-slate-500">Masukkan nama perusahaan atau merek dagang utama Anda.</p>
          </div>

          <div class="space-y-1">
            <label class="text-xs font-semibold text-slate-600 block">Nama Perusahaan / Bisnis Utama <span class="text-red-500">*</span></label>
            <input
              v-model="company_name"
              class="w-full border px-3 py-2.5 rounded-lg outline-none focus:ring-2 focus:ring-slate-200 text-sm"
              :class="fieldErrors.company_name ? 'border-red-300 bg-red-50' : ''"
              placeholder="Contoh: Nessa Bakery Group"
              @keyup.enter="nextStep"
            />
            <div v-if="fieldErrors.company_name?.length" class="text-xs text-red-700">{{ fieldErrors.company_name[0] }}</div>
          </div>

          <div class="pt-4 flex justify-end">
            <button 
              type="button" 
              class="bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold px-5 py-2.5 rounded-lg transition-all"
              @click="nextStep"
            >
              Lanjutkan →
            </button>
          </div>
        </div>

        <!-- STEP 2: Toko / Cabang Pertama -->
        <div v-if="currentStep === 2" class="space-y-4 animate-fade-in">
          <div>
            <h2 class="text-lg font-bold text-slate-800">2. Cabang Toko Pertama</h2>
            <p class="text-xs text-slate-500">Cabang toko pertama ini akan langsung terhubung untuk transaksi kasir Anda.</p>
          </div>

          <div class="space-y-1">
            <label class="text-xs font-semibold text-slate-600 block">Nama Cabang Toko <span class="text-red-500">*</span></label>
            <input
              v-model="store_name"
              class="w-full border px-3 py-2.5 rounded-lg outline-none focus:ring-2 focus:ring-slate-200 text-sm"
              :class="fieldErrors.store_name ? 'border-red-300 bg-red-50' : ''"
              placeholder="Contoh: Nessa Bakery - Cabang Sudirman"
            />
            <div v-if="fieldErrors.store_name?.length" class="text-xs text-red-700">{{ fieldErrors.store_name[0] }}</div>
          </div>

          <div class="space-y-1">
            <label class="text-xs font-semibold text-slate-600 block">Nomor Telepon Toko (Opsional)</label>
            <input
              v-model="store_phone"
              class="w-full border px-3 py-2.5 rounded-lg outline-none focus:ring-2 focus:ring-slate-200 text-sm"
              placeholder="Contoh: 021-1234567"
            />
          </div>

          <div class="space-y-1">
            <label class="text-xs font-semibold text-slate-600 block">Alamat Toko (Opsional)</label>
            <textarea
              v-model="store_address"
              rows="3"
              class="w-full border px-3 py-2.5 rounded-lg outline-none focus:ring-2 focus:ring-slate-200 text-sm"
              placeholder="Contoh: Jl. Sudirman No. 12, Jakarta Pusat"
            ></textarea>
          </div>

          <div class="pt-4 flex justify-between gap-3">
            <button 
              type="button" 
              class="border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-semibold px-5 py-2.5 rounded-lg transition-all"
              @click="prevStep"
              :disabled="loading"
            >
              ← Kembali
            </button>
            <button 
              type="button" 
              class="bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold px-6 py-2.5 rounded-lg transition-all disabled:opacity-60"
              @click="submit"
              :disabled="loading"
            >
              {{ loading ? 'Menyimpan...' : 'Selesaikan Pengaturan ✓' }}
            </button>
          </div>
        </div>

      </div>
    </div>
  </div>
</template>

<style scoped>
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(8px); }
  to { opacity: 1; transform: translateY(0); }
}
.animate-fade-in {
  animation: fadeIn 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
</style>
