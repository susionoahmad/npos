<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { useAuthStore } from '../stores/auth'
import AppShell from '../components/AppShell.vue'
import api from '../services/api'

const auth = useAuthStore()
const storeName = computed(() => activeSession.value?.store?.name || auth.user?.store?.name || 'Toko Nessa POS')

// State
const activeSession = ref<any>(null)
const mutations = ref<any[]>([])
const loading = ref(true)
const saving = ref(false)

// Form fields
const type = ref('tambah')
const direction = ref('in') // for koreksi
const amount = ref<number | null>(null)
const notes = ref('')
const referenceNumber = ref('')

const error = ref('')
const success = ref('')

// Print state
const printingMutation = ref<any>(null)

const money = (n: number) =>
  new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(Math.round(n))

const formatTime = (dateStr: string) => {
  return new Date(dateStr).toLocaleTimeString('id-ID', {
    hour: '2-digit',
    minute: '2-digit'
  })
}

const formatDate = (dateStr: string) => {
  return new Date(dateStr).toLocaleDateString('id-ID', {
    dateStyle: 'medium'
  })
}

// Fetch active session and mutations
async function loadData() {
  loading.value = true
  error.value = ''
  try {
    const sessRes = await api.get('/cashier-sessions/active')
    const sess = sessRes.data && Object.keys(sessRes.data).length > 0 ? sessRes.data : null
    activeSession.value = sess

    if (sess) {
      const mutRes = await api.get('/cashier-mutations', {
        params: { cashier_session_id: sess.id }
      })
      mutations.value = mutRes.data.data || []
    }
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Gagal memuat data mutasi kas.'
  } finally {
    loading.value = false
  }
}

// Save mutation
async function onSubmit() {
  if (!activeSession.value) return

  if (amount.value === null || amount.value <= 0) {
    error.value = 'Nominal mutasi wajib diisi dan harus lebih besar dari 0.'
    return
  }

  if (!notes.value.trim()) {
    error.value = 'Keterangan mutasi wajib diisi.'
    return
  }

  saving.value = true
  error.value = ''
  success.value = ''

  try {
    const payload: any = {
      type: type.value,
      amount: amount.value,
      notes: notes.value.trim(),
      reference_number: referenceNumber.value.trim() || undefined,
    }

    if (type.value === 'koreksi') {
      payload.direction = direction.value
    }

    const { data } = await api.post('/cashier-mutations', payload)
    success.value = data.message || 'Mutasi kas berhasil disimpan.'
    
    // Reset form
    amount.value = null
    notes.value = ''
    referenceNumber.value = ''

    // Reload list
    await loadData()
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Gagal menyimpan mutasi kas.'
  } finally {
    saving.value = false
  }
}

// Print mutation slip
function printMutation(mutation: any) {
  printingMutation.value = mutation
  setTimeout(() => {
    window.print()
  }, 100)
}

const getTypeLabel = (t: string, dir: string) => {
  switch (t) {
    case 'tambah': return 'Tambah Kas'
    case 'kurang': return 'Kurang Kas'
    case 'koreksi': return `Koreksi (${dir === 'in' ? 'Tambah' : 'Kurang'})`
    case 'pengeluaran': return 'Operasional'
    case 'penjualan_tunai': return 'Penjualan Tunai'
    case 'modal_awal': return 'Modal Awal'
    case 'setor_kas': return 'Setoran ke Kas Besar'
    default: return t
  }
}

const getTypeBadgeClass = (t: string, dir: string) => {
  switch (t) {
    case 'tambah': return 'bg-emerald-50 text-emerald-700 border-emerald-200'
    case 'kurang': return 'bg-rose-50 text-rose-700 border-rose-200'
    case 'koreksi': 
      return dir === 'in' 
        ? 'bg-blue-50 text-blue-700 border-blue-200'
        : 'bg-amber-50 text-amber-700 border-amber-200'
    case 'pengeluaran': return 'bg-slate-100 text-slate-700 border-slate-300'
    case 'penjualan_tunai': return 'bg-cyan-50 text-cyan-700 border-cyan-200'
    case 'modal_awal': return 'bg-indigo-50 text-indigo-700 border-indigo-200'
    case 'setor_kas': return 'bg-purple-50 text-purple-700 border-purple-200'
    default: return 'bg-slate-50 text-slate-600 border-slate-200'
  }
}

onMounted(() => {
  loadData()
})
</script>

<template>
  <AppShell>
    <div class="space-y-6">
      
      <!-- Top Header Row -->
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 class="text-2xl font-bold tracking-tight text-slate-900">Mutasi Kas Laci Kasir</h2>
          <p class="text-xs text-slate-500 font-medium">Catat penambahan, penarikan, koreksi saldo, dan pengeluaran operasional secara live</p>
        </div>
      </div>

      <!-- Warning Alert if no active session -->
      <div v-if="!loading && !activeSession" class="bg-amber-50 border border-amber-200 rounded-2xl p-6 shadow-sm flex flex-col md:flex-row items-center justify-between gap-4">
        <div class="flex items-center gap-3">
          <span class="text-3xl">⚠️</span>
          <div>
            <h3 class="font-extrabold text-amber-800 text-sm">Sesi Kasir Belum Dibuka</h3>
            <p class="text-xs text-amber-600 font-semibold mt-0.5">Anda wajib membuka sesi operasional kasir terlebih dahulu untuk dapat mencatat mutasi laci.</p>
          </div>
        </div>
        <RouterLink to="/session/open" class="w-full md:w-auto text-center rounded-xl bg-amber-600 px-5 py-2.5 text-xs font-bold text-white hover:bg-amber-700 transition-all shadow-sm">
          Buka Sesi Kasir Sekarang
        </RouterLink>
      </div>

      <div v-else class="grid gap-6 lg:grid-cols-[1fr_380px] items-start">
        
        <!-- Left: Mutations List -->
        <div class="space-y-4">
          <!-- Active Session Mini Header Info -->
          <div v-if="activeSession" class="bg-slate-900 text-white rounded-2xl p-4 shadow-sm flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
              <span class="text-xl">🏪</span>
              <div>
                <span class="block text-[10px] text-slate-400 font-bold uppercase tracking-wider">Sesi Kasir Aktif</span>
                <span class="block text-xs font-extrabold font-mono text-emerald-400">
                  {{ activeSession.session_number }} — Shift {{ activeSession.shift }}
                </span>
              </div>
            </div>
            <div class="text-right">
              <span class="block text-[10px] text-slate-400 font-bold uppercase tracking-wider">Kasir Aktif</span>
              <span class="block text-xs font-bold">{{ auth.user?.name || '-' }}</span>
            </div>
          </div>

          <!-- Mutation list container -->
          <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm space-y-4">
            <h3 class="font-extrabold text-slate-900 text-sm">Riwayat Mutasi Kas Sesi Ini</h3>
            
            <div v-if="loading" class="flex items-center justify-center py-16">
              <div class="h-8 w-8 animate-spin rounded-full border-4 border-slate-900 border-t-transparent"></div>
            </div>

            <div v-else-if="!mutations.length" class="text-center py-12 text-slate-400 text-xs font-medium">
              Belum ada pencatatan mutasi kas untuk sesi aktif saat ini.
            </div>

            <div v-else class="divide-y divide-slate-100 max-h-[60vh] overflow-y-auto pr-1">
              <div v-for="mut in mutations" :key="mut.id" class="py-3 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 first:pt-0 last:pb-0">
                <div class="min-w-0 space-y-1">
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="font-mono text-xs font-bold text-slate-900">{{ mut.mutation_number }}</span>
                    <span class="px-2 py-0.5 rounded-full border text-[9px] font-extrabold uppercase" :class="getTypeBadgeClass(mut.type, mut.direction)">
                      {{ getTypeLabel(mut.type, mut.direction) }}
                    </span>
                    <span v-if="mut.reference_number" class="text-[10px] font-mono font-semibold text-slate-400">Ref: {{ mut.reference_number }}</span>
                  </div>
                  <p class="text-xs text-slate-600 font-semibold leading-relaxed">{{ mut.notes }}</p>
                  <p class="text-[10px] text-slate-400 font-semibold">🕒 {{ formatDate(mut.created_at) }} pukul {{ formatTime(mut.created_at) }} WIB</p>
                </div>

                <div class="flex items-center gap-3 shrink-0 self-end sm:self-center">
                  <span class="font-mono font-extrabold text-sm" :class="mut.direction === 'in' ? 'text-emerald-600' : 'text-red-600'">
                    {{ mut.direction === 'in' ? '+' : '-' }} Rp {{ money(mut.amount) }}
                  </span>
                  
                  <button
                    type="button"
                    class="rounded-lg border border-slate-200 bg-white p-1.5 text-xs text-slate-500 hover:text-slate-900 hover:bg-slate-50"
                    title="Cetak Bukti Mutasi"
                    @click="printMutation(mut)"
                  >
                    🖨️
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Right: Form to create mutation -->
        <div class="bg-white rounded-xl p-5 shadow-sm border border-slate-200 space-y-4">
          <div>
            <h3 class="font-extrabold text-slate-800 text-base">Catat Mutasi Kas</h3>
            <p class="text-[11px] text-slate-400 font-medium">Isi nominal dan keterangan dengan jujur untuk proses audit laci</p>
          </div>

          <form @submit.prevent="onSubmit" class="space-y-4">
            <p v-if="error" class="rounded-xl bg-red-50 border border-red-200 px-3.5 py-2.5 text-xs font-bold text-red-800 leading-normal">{{ error }}</p>
            <p v-if="success" class="rounded-xl bg-emerald-50 border border-emerald-200 px-3.5 py-2.5 text-xs font-bold text-emerald-800 leading-normal">{{ success }}</p>

            <!-- Mutation Type -->
            <div class="space-y-1.5">
              <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wide">Jenis Mutasi</label>
              <select
                v-model="type"
                class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-800 focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900"
              >
                <option value="tambah">Tambah Kas (Modal Masuk)</option>
                <option value="kurang">Kurang Kas (Penarikan)</option>
                <option value="koreksi">Koreksi Kas (Adjustment)</option>
                <option value="pengeluaran">Pengeluaran Operasional</option>
              </select>
            </div>

            <!-- Direction selection (only for koreksi) -->
            <div v-if="type === 'koreksi'" class="space-y-1.5 animate-slide-up">
              <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wide">Arah Koreksi</label>
              <div class="grid grid-cols-2 gap-2">
                <button
                  type="button"
                  class="py-2 rounded-xl border text-xs font-bold text-center transition-all"
                  :class="direction === 'in'
                    ? 'bg-emerald-600 border-emerald-600 text-white shadow-sm font-black'
                    : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50'"
                  @click="direction = 'in'"
                >
                  🟢 Tambah Saldo
                </button>
                <button
                  type="button"
                  class="py-2 rounded-xl border text-xs font-bold text-center transition-all"
                  :class="direction === 'out'
                    ? 'bg-red-600 border-red-600 text-white shadow-sm font-black'
                    : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50'"
                  @click="direction = 'out'"
                >
                  🔴 Kurang Saldo
                </button>
              </div>
            </div>

            <!-- Nominal Amount -->
            <div class="space-y-1.5">
              <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wide">Nominal Uang (Rupiah)</label>
              <div class="relative rounded-xl shadow-sm">
                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-500 font-extrabold text-xs">
                  Rp
                </div>
                <input
                  v-model.number="amount"
                  type="number"
                  min="0.01"
                  step="any"
                  class="w-full rounded-xl border border-slate-300 bg-white pl-9 pr-4 py-2.5 text-sm font-extrabold font-mono text-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900 text-right"
                  placeholder="0"
                  required
                />
              </div>
            </div>

            <!-- Reference number -->
            <div class="space-y-1.5">
              <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wide">Nomor Referensi (Opsional)</label>
              <input
                v-model="referenceNumber"
                type="text"
                class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-xs font-semibold focus:border-slate-900 focus:outline-none"
                placeholder="No struk / faktur / kuintansi..."
              />
            </div>

            <!-- Notes -->
            <div class="space-y-1.5">
              <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wide">Keterangan / Alasan *</label>
              <textarea
                v-model="notes"
                rows="3"
                class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900"
                placeholder="Contoh: Beli sapu & ember kain pel, atau setoran kasir tengah hari..."
                required
              ></textarea>
            </div>

            <!-- Buttons -->
            <button
              type="submit"
              class="w-full rounded-xl bg-slate-900 py-3 text-xs font-extrabold text-white hover:bg-slate-800 transition-all shadow-md flex items-center justify-center gap-1.5"
              :disabled="saving || amount === null || amount <= 0"
            >
              <span v-if="saving" class="h-3 w-3 animate-spin rounded-full border-2 border-white border-t-transparent"></span>
              Simpan Mutasi Kas
            </button>
          </form>
        </div>
      </div>
    </div>

    <!-- Hidden Printing Slip template -->
    <div v-if="printingMutation" class="hidden print:block font-mono text-xs text-black p-4">
      <div id="mutation-slip-print">
        <p class="text-center font-bold text-base leading-tight uppercase">{{ storeName }}</p>
        <p class="text-center text-[10px] mt-0.5">BUKTI MUTASI KAS KASIR</p>
        <hr class="my-2 border-dashed border-black" />
        
        <p>No Mutasi : {{ printingMutation.mutation_number }}</p>
        <p>No Sesi   : {{ activeSession?.session_number || '-' }}</p>
        <p>Kasir     : {{ auth.user?.name || '-' }}</p>
        <p>Waktu     : {{ formatDate(printingMutation.created_at) }} {{ formatTime(printingMutation.created_at) }} WIB</p>
        <p>No Ref    : {{ printingMutation.reference_number || '-' }}</p>
        <hr class="my-2 border-dashed border-black" />

        <div class="flex justify-between text-sm font-bold">
          <span>Jenis Mutasi :</span>
          <span>{{ getTypeLabel(printingMutation.type, printingMutation.direction) }}</span>
        </div>
        <div class="flex justify-between text-base font-extrabold mt-1">
          <span>Nominal :</span>
          <span>Rp {{ money(printingMutation.amount) }}</span>
        </div>
        <hr class="my-2 border-dashed border-black" />
        
        <p class="font-bold">Keterangan:</p>
        <p class="italic leading-normal text-[11px] mt-0.5">"{{ printingMutation.notes }}"</p>

        <hr class="my-4 border-dashed border-black" />
        <div class="grid grid-cols-2 gap-4 text-[10px] text-center pt-1">
          <div>
            <p>Kasir Shift,</p>
            <br/><br/>
            <p>( __________________ )</p>
            <p class="font-semibold mt-1">{{ auth.user?.name || '-' }}</p>
          </div>
          <div>
            <p>Supervisor,</p>
            <br/><br/>
            <p>( __________________ )</p>
            <p class="font-semibold mt-1">Tanda Tangan</p>
          </div>
        </div>
      </div>
    </div>
  </AppShell>
</template>

<style scoped>
@media print {
  body * {
    visibility: hidden !important;
  }
  #mutation-slip-print,
  #mutation-slip-print * {
    visibility: visible !important;
  }
  #mutation-slip-print {
    position: absolute;
    left: 0;
    top: 0;
    width: 80mm;
    max-width: 100%;
    background-color: white !important;
    color: black !important;
  }
}

@keyframes slideUp {
  from {
    transform: translateY(5%);
    opacity: 0;
  }
  to {
    transform: translateY(0);
    opacity: 1;
  }
}
.animate-slide-up {
  animation: slideUp 0.15s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
</style>
