<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import AppShell from '../components/AppShell.vue'
import api from '../services/api'

const tenants = ref<any[]>([])
const loading = ref(true)
const error = ref('')
const fetchTenants = async () => {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/superadmin/tenants')
    tenants.value = data ?? []
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Gagal memuat data dashboard.'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchTenants()
})

const stats = computed(() => {
  const list = tenants.value
  const total = list.length
  const active = list.filter((t) => t.subscription_status === 'active').length
  const trial = list.filter((t) => t.subscription_status === 'trial').length
  const expired = list.filter((t) => t.subscription_status === 'expired' || t.subscription_status === 'cancelled').length

  const totalStores = list.reduce((sum, t) => sum + (t.stores_count || 0), 0)
  const totalUsers = list.reduce((sum, t) => sum + (t.users_count || 0), 0)

  return { total, active, trial, expired, totalStores, totalUsers }
})
</script>

<template>
  <AppShell>
    <div class="space-y-6">
      <!-- Title -->
      <div>
        <h2 class="text-2xl font-black tracking-tight text-slate-900">Dashboard Superadmin</h2>
        <p class="text-sm text-slate-500">Ringkasan operasional platform SaaS Nessa POS.</p>
      </div>

      <div v-if="error" class="bg-red-50 text-red-800 p-3 rounded-lg text-sm font-medium">
        {{ error }}
      </div>

      <!-- Stats Grid -->
      <div class="grid gap-4 grid-cols-2 lg:grid-cols-4">
        <!-- Card 1 -->
        <div class="bg-white p-5 rounded-2xl border border-slate-100 shadow-sm space-y-2">
          <div class="text-xs font-bold text-slate-400 uppercase tracking-wider">Total Tenant</div>
          <div class="text-3xl font-black text-slate-900">{{ loading ? '...' : stats.total }}</div>
          <div class="text-xs text-slate-500 font-semibold">Perusahaan terdaftar</div>
        </div>
        <!-- Card 2 -->
        <div class="bg-emerald-50/50 p-5 rounded-2xl border border-emerald-100 shadow-sm space-y-2">
          <div class="text-xs font-bold text-emerald-600 uppercase tracking-wider">Langganan Aktif</div>
          <div class="text-3xl font-black text-emerald-700">{{ loading ? '...' : stats.active }}</div>
          <div class="text-xs text-emerald-600 font-semibold">Membayar biaya langganan</div>
        </div>
        <!-- Card 3 -->
        <div class="bg-blue-50/50 p-5 rounded-2xl border border-blue-100 shadow-sm space-y-2">
          <div class="text-xs font-bold text-blue-600 uppercase tracking-wider">Masa Uji Coba (Trial)</div>
          <div class="text-3xl font-black text-blue-700">{{ loading ? '...' : stats.trial }}</div>
          <div class="text-xs text-blue-600 font-semibold">Trial 14 hari aktif</div>
        </div>
        <!-- Card 4 -->
        <div class="bg-rose-50/50 p-5 rounded-2xl border border-rose-100 shadow-sm space-y-2">
          <div class="text-xs font-bold text-rose-600 uppercase tracking-wider">Kedaluwarsa</div>
          <div class="text-3xl font-black text-rose-700">{{ loading ? '...' : stats.expired }}</div>
          <div class="text-xs text-rose-600 font-semibold">Habis masa aktif / dibatalkan</div>
        </div>
      </div>

      <!-- Detail Grid -->
      <div class="grid gap-6 md:grid-cols-2">
        <!-- Infrastructure -->
        <div class="bg-white p-6 rounded-2xl border border-slate-100 shadow-sm space-y-4">
          <h3 class="font-extrabold text-slate-900 text-base">Infrastruktur Platform</h3>
          <div class="grid grid-cols-2 gap-4">
            <div class="bg-slate-50 p-4 rounded-xl text-center">
              <div class="text-2xl font-bold text-slate-800">{{ loading ? '...' : stats.totalStores }}</div>
              <div class="text-xs font-semibold text-slate-500 mt-1">Total Toko / Cabang</div>
            </div>
            <div class="bg-slate-50 p-4 rounded-xl text-center">
              <div class="text-2xl font-bold text-slate-800">{{ loading ? '...' : stats.totalUsers }}</div>
              <div class="text-xs font-semibold text-slate-500 mt-1">Total Pengguna Aktif</div>
            </div>
          </div>
        </div>

        <!-- SaaS Admin Info Banner -->
        <div class="bg-slate-900 text-white p-6 rounded-2xl flex flex-col justify-between shadow-md relative overflow-hidden">
          <div class="absolute -right-10 -bottom-10 text-9xl opacity-10 select-none">🌐</div>
          <div class="space-y-2">
            <span class="inline-flex rounded-full bg-emerald-500 text-slate-950 px-2 py-0.5 text-[9px] font-black uppercase tracking-wider">
              Superadmin Mode
            </span>
            <h3 class="font-black text-lg">Keamanan & Kontrol SaaS</h3>
            <p class="text-xs text-slate-300 leading-relaxed max-w-sm">
              Gunakan menu di sidebar untuk mengelola limitasi toko, pengguna, memantau status pembayaran Midtrans, dan memperbarui konfigurasi biaya dasar berlangganan secara dinamis.
            </p>
          </div>
        </div>
      </div>
    </div>
  </AppShell>
</template>
