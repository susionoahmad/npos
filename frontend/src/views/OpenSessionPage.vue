<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import AppShell from '../components/AppShell.vue'
import api from '../services/api'

const router = useRouter()
const auth = useAuthStore()

const shift = ref('Pagi')
const startBalance = ref<number | null>(null)
const notes = ref('')

const loading = ref(false)
const checking = ref(true)
const activeSession = ref<any>(null)
const openedSessionData = ref<any>(null)
const error = ref('')
const success = ref('')

/** Current kas besar balance for validation */
const kasBesarBalance = ref<number | null>(null)

const cashierName = computed(() => auth.user?.name || '-')
const storeName = computed(() => auth.user?.store?.name || 'Toko Nessa POS')
const currentDateStr = computed(() => {
  return new Date().toLocaleDateString('id-ID', {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric'
  })
})

const currentTimeStr = computed(() => {
  return new Date().toLocaleTimeString('id-ID', {
    hour: '2-digit',
    minute: '2-digit'
  })
})

/** True if entered modal awal exceeds kas besar */
const isModalExceedsKasBesar = computed(() => {
  if (kasBesarBalance.value === null) return false
  if (startBalance.value === null || startBalance.value <= 0) return false
  return startBalance.value > kasBesarBalance.value
})

function formatRp(val: number) {
  return 'Rp ' + val.toLocaleString('id-ID', { minimumFractionDigits: 0 })
}

async function checkActiveSession() {
  checking.value = true
  error.value = ''
  try {
    const [sessionRes, balanceRes] = await Promise.all([
      api.get('/cashier-sessions/active'),
      api.get('/kas-besar/balance').catch(() => ({ data: { balance: null } }))
    ])

    kasBesarBalance.value = balanceRes.data?.balance ?? null

    const data = sessionRes.data
    if (data && Object.keys(data).length > 0) {
      activeSession.value = data
      shift.value = data.shift
      startBalance.value = Number(data.start_balance)
      notes.value = data.notes || ''
    } else {
      activeSession.value = null
    }
  } catch (e) {
    console.error('Gagal mengecek sesi aktif', e)
  } finally {
    checking.value = false
  }
}

async function onSubmit() {
  if (activeSession.value) {
    router.push('/pos')
    return
  }

  if (startBalance.value === null || startBalance.value < 0) {
    error.value = 'Modal awal wajib diisi dan minimal Rp 0.'
    return
  }

  if (isModalExceedsKasBesar.value) {
    error.value = `Modal awal melebihi saldo Kas Besar (${formatRp(kasBesarBalance.value!)}). Kurangi nominal atau minta admin menambah saldo Kas Besar.`
    return
  }

  loading.value = true
  error.value = ''
  success.value = ''

  try {
    const { data } = await api.post('/cashier-sessions/open', {
      shift: shift.value,
      start_balance: startBalance.value,
      notes: notes.value.trim() || undefined,
    })
    success.value = data.message || 'Sesi kasir berhasil dibuka.'
    openedSessionData.value = data.session || {
      session_number: 'SES-',
      shift: shift.value,
      start_balance: startBalance.value,
      opened_at: new Date().toISOString(),
      notes: notes.value.trim()
    }
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Gagal membuka sesi kasir baru.'
  } finally {
    loading.value = false
  }
}

function printOpenShiftReport(sessionData: any) {
  if (!sessionData) return

  const store = auth.user?.store || {}
  const isRawBt = store.print_method === 'rawbt'

  const formattedOpened = new Date(sessionData.opened_at).toLocaleString('id-ID', {
    dateStyle: 'medium',
    timeStyle: 'short'
  })

  // Format line row helper
  const addRow = (label: string, val: number, prefix: string = '') => {
    const valStr = `${prefix}Rp ${val.toLocaleString('id-ID', { maximumFractionDigits: 0 })}`
    const spaces = Math.max(1, 32 - label.length - valStr.length)
    return label + ' '.repeat(spaces) + valStr + '\n'
  }

  if (isRawBt) {
    const line = '--------------------------------'
    let text = ''
    text += `${storeName.value.toUpperCase()}\n`
    text += `BUKTI SERAH TERIMA BUKA SHIFT\n`
    text += `${line}\n`
    text += `No Sesi: ${sessionData.session_number}\n`
    text += `Kasir  : ${cashierName.value}\n`
    text += `Shift  : ${sessionData.shift.toUpperCase()}\n`
    text += `Buka   : ${formattedOpened}\n`
    text += `${line}\n`
    text += addRow('Modal Awal Tunai', Number(sessionData.start_balance))
    if (sessionData.notes) {
      text += `Catatan: "${sessionData.notes}"\n`
    }
    text += `${line}\n\n`
    
    text += `  Penerima,        Pemberi,\n\n\n`
    text += `  (__________)   (__________)\n`
    text += `  ${cashierName.value}\n\n\n\n`

    const intentUrl = `intent:#Intent;action=ru.a402d.rawbtprinter.action.PRINT;category=android.intent.category.DEFAULT;type=text/plain;S.text=${encodeURIComponent(text)};end;`
    window.location.href = intentUrl
    return
  }

  // HTML print content for IFrame
  const printContentHtml = `
    <div style="text-align: center; font-weight: bold; font-size: 14px; margin-bottom: 2px; text-transform: uppercase;">
      ${storeName.value}
    </div>
    <div style="text-align: center; font-size: 10px; margin-bottom: 4px;">BUKTI SERAH TERIMA BUKA SHIFT</div>
    <div style="border-top: 1px dashed #000; margin: 6px 0;"></div>
    <div style="font-size: 10px; line-height: 1.3;">
      <div>No Sesi : ${sessionData.session_number}</div>
      <div>Kasir   : ${cashierName.value}</div>
      <div>Shift   : ${sessionData.shift.toUpperCase()}</div>
      <div>Buka    : ${formattedOpened}</div>
    </div>
    <div style="border-top: 1px dashed #000; margin: 6px 0;"></div>
    <div style="font-size: 10px; line-height: 1.3;">
      <div style="display: flex; justify-content: space-between; margin-bottom: 2px; font-weight: bold;">
        <span>Modal Awal Tunai</span>
        <span>Rp ${Number(sessionData.start_balance).toLocaleString('id-ID', { maximumFractionDigits: 0 })}</span>
      </div>
      ${sessionData.notes ? `<div style="font-size: 9px; font-style: italic; margin-top: 4px; line-height: 1.2;">Catatan: "${sessionData.notes}"</div>` : ''}
    </div>
    <div style="border-top: 1px dashed #000; margin: 6px 0;"></div>
    <div style="margin-top: 16px;">
      <div style="float: left; width: 45%; text-align: center; font-size: 9px;">
        <div>Penerima (Kasir),</div>
        <br/><br/><br/>
        <div>( __________________ )</div>
        <div style="font-weight: bold; margin-top: 2px;">${cashierName.value}</div>
      </div>
      <div style="float: right; width: 45%; text-align: center; font-size: 9px;">
        <div>Pemberi / SPV,</div>
        <br/><br/><br/>
        <div>( __________________ )</div>
        <div style="font-weight: bold; margin-top: 2px;">Tanda Tangan</div>
      </div>
      <div style="clear: both;"></div>
    </div>
  `

  const iframe = document.createElement('iframe')
  iframe.style.position = 'fixed'
  iframe.style.width = '0px'
  iframe.style.height = '0px'
  iframe.style.border = 'none'
  iframe.style.opacity = '0'
  document.body.appendChild(iframe)

  const doc = iframe.contentWindow?.document
  if (!doc) return

  doc.write(`
    <!DOCTYPE html>
    <html>
      <head>
        <title>Bukti Serah Terima Buka Shift</title>
        <style>
          @page {
            size: auto;
            margin: 0mm;
          }
          body {
            font-family: 'Courier New', Courier, monospace;
            width: 74mm;
            margin: 0;
            padding: 8px;
            box-sizing: border-box;
            background-color: #ffffff;
            color: #000000;
          }
          * {
            box-sizing: border-box;
          }
        </style>
      </head>
      <body>
        ${printContentHtml}
      </body>
    </html>
  `)
  doc.close()

  setTimeout(() => {
    if (iframe.contentWindow) {
      iframe.contentWindow.focus()
      iframe.contentWindow.print()
      setTimeout(() => {
        document.body.removeChild(iframe)
      }, 500)
    }
  }, 300)
}

function onCancel() {
  router.push('/')
}

onMounted(() => {
  checkActiveSession()
})
</script>

<template>
  <AppShell>
    <div class="p-2 sm:p-4 flex items-center justify-center">
      <!-- Main Modal Form Card -->
      <div class="w-full max-w-lg bg-white rounded-3xl border border-slate-200 shadow-xl overflow-hidden flex flex-col">
        
        <!-- Top Banner Header -->
        <div class="bg-slate-900 text-white p-6 relative">
          <div class="space-y-1">
            <span class="inline-flex rounded-full bg-slate-800 text-slate-300 px-3 py-1 text-[10px] font-extrabold uppercase tracking-widest">
              Shift Sesi Kasir
            </span>
            <h2 class="text-xl font-bold tracking-tight">Buka Sesi Operasional</h2>
            <p class="text-xs text-slate-400 font-medium">Input modal awal kas sebelum memulai transaksi kasir</p>
          </div>
          <span class="absolute top-6 right-6 text-4xl">🏪</span>
        </div>

        <!-- Form Body -->
        <form @submit.prevent="onSubmit" class="p-6 space-y-5 flex-1">
          
          <!-- Checking State spinner -->
          <div v-if="checking" class="py-12 text-center text-slate-400 text-xs font-semibold">
            <div class="h-8 w-8 animate-spin rounded-full border-4 border-slate-900 border-t-transparent mx-auto mb-3"></div>
            Memverifikasi status sesi aktif kasir...
          </div>

          <div v-else class="space-y-5">
            <!-- Case 1: Session successfully opened, show print layout & go to POS -->
            <div v-if="openedSessionData" class="rounded-2xl border border-emerald-200 bg-emerald-50 p-6 space-y-4 text-center">
              <div class="text-emerald-800 font-extrabold text-sm flex items-center justify-center gap-1.5">
                🎉 Sesi Kasir Berhasil Dibuka!
              </div>
              <p class="text-xs text-emerald-700 leading-relaxed font-semibold">
                Sesi <span class="font-mono font-bold">{{ openedSessionData.session_number }}</span> shift <span class="uppercase font-bold">{{ openedSessionData.shift }}</span> telah aktif dengan modal awal <span class="font-bold font-mono">{{ formatRp(Number(openedSessionData.start_balance)) }}</span>.
              </p>
              
              <div class="flex items-center justify-center gap-3 pt-2">
                <button
                  type="button"
                  class="rounded-2xl border border-emerald-300 bg-white text-emerald-800 px-4 py-2.5 text-xs font-bold hover:bg-emerald-50 flex items-center gap-1.5 transition-all shadow-sm"
                  @click="printOpenShiftReport(openedSessionData)"
                >
                  🖨️ Cetak Bukti Buka Shift
                </button>
                <button
                  type="button"
                  class="rounded-2xl bg-emerald-600 text-white px-5 py-2.5 text-xs font-bold hover:bg-emerald-700 transition-all shadow-md"
                  @click="router.push('/pos')"
                >
                  Lanjut ke POS
                </button>
              </div>
            </div>

            <!-- Case 2: No session opened in this load, show form inputs -->
            <div v-else class="space-y-5">
              <!-- Active Session Alert Box -->
              <div v-if="activeSession" class="rounded-2xl border border-amber-200 bg-amber-50/70 p-4 space-y-2.5">
                <div class="flex items-center gap-2 text-amber-800 text-xs font-extrabold">
                  <span>⚠️</span> PERINGATAN: SESI SUDAH AKTIF
                </div>
                <p class="text-xs text-amber-700 leading-relaxed font-semibold">
                  Akun Anda saat ini telah memiliki sesi operasional aktif (<span class="font-mono">{{ activeSession.session_number }}</span>) 
                  pada shift <span class="font-bold uppercase">{{ activeSession.shift }}</span>. 
                  Anda tidak perlu membuka sesi baru.
                </p>
                <div class="flex gap-2 pt-1.5">
                  <button
                    type="button"
                    class="rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-50 flex items-center gap-1 shadow-sm transition-all"
                    @click="printOpenShiftReport(activeSession)"
                  >
                    🖨️ Cetak Bukti Buka Shift
                  </button>
                </div>
              </div>

              <!-- Alert error / success -->
              <p v-if="error" class="rounded-xl bg-red-50 border border-red-200 px-3.5 py-2.5 text-xs font-bold text-red-800 leading-normal">{{ error }}</p>
              <p v-if="success" class="rounded-xl bg-emerald-50 border border-emerald-200 px-3.5 py-2.5 text-xs font-bold text-emerald-800 leading-normal">{{ success }}</p>

              <!-- Automatic Meta Info Grid -->
              <div class="grid grid-cols-2 gap-3 bg-slate-50 p-4 rounded-2xl border border-slate-100 text-xs text-slate-500 font-semibold">
                <div class="space-y-1">
                  <span class="block text-[10px] text-slate-400 uppercase tracking-wide">Cabang Toko</span>
                  <span class="block text-slate-900 truncate font-bold">🏪 {{ storeName }}</span>
                </div>
                <div class="space-y-1">
                  <span class="block text-[10px] text-slate-400 uppercase tracking-wide">Nama Kasir</span>
                  <span class="block text-slate-900 truncate font-bold">👤 {{ cashierName }}</span>
                </div>
                <div class="space-y-1 col-span-2 pt-2 border-t border-slate-200/50">
                  <span class="block text-[10px] text-slate-400 uppercase tracking-wide">Tanggal & Waktu</span>
                  <span class="block text-slate-900 font-bold">📅 {{ currentDateStr }} — {{ currentTimeStr }} WIB</span>
                </div>
              </div>

              <!-- Shift Selection pills -->
              <div class="space-y-1.5">
                <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wide">Pilih Shift</label>
                <div class="grid grid-cols-3 gap-2">
                  <button
                    v-for="s in ['Pagi', 'Siang', 'Malam']"
                    :key="s"
                    type="button"
                    class="py-3 rounded-2xl border text-xs font-extrabold transition-all text-center uppercase tracking-wide"
                    :class="shift === s
                      ? 'bg-slate-900 border-slate-900 text-white shadow-sm font-black'
                      : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50 hover:text-slate-900'"
                    :disabled="!!activeSession"
                    @click="shift = s"
                  >
                    {{ s }}
                  </button>
                </div>
              </div>

              <!-- Initial Cash input -->
              <div class="space-y-1.5">
                <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wide">Modal Awal Tunai (Kas Laci)</label>
                <div class="relative rounded-2xl shadow-sm">
                  <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-500 font-extrabold text-sm font-mono">
                    Rp
                  </div>
                  <input
                    v-model.number="startBalance"
                    type="number"
                    min="0"
                    step="any"
                    class="w-full rounded-2xl border px-3.5 py-3 pl-10 text-base font-extrabold font-mono text-slate-900 focus:outline-none focus:ring-2 transition-all text-right"
                    :class="isModalExceedsKasBesar
                      ? 'border-red-400 bg-red-50 focus:border-red-500 focus:ring-red-200'
                      : 'border-slate-300 bg-white focus:border-slate-900 focus:ring-slate-900/20'"
                    placeholder="0"
                    required
                    :disabled="!!activeSession"
                  />
                </div>

                <!-- Kas Besar Balance Info / Warning -->
                <div
                  v-if="kasBesarBalance !== null && !activeSession"
                  class="flex items-start gap-2 rounded-xl px-3 py-2.5 text-xs font-semibold transition-all"
                  :class="isModalExceedsKasBesar
                    ? 'bg-red-50 border border-red-200 text-red-700'
                    : 'bg-teal-50 border border-teal-200 text-teal-700'"
                >
                  <span class="text-sm shrink-0">{{ isModalExceedsKasBesar ? '🚫' : '🏦' }}</span>
                  <div>
                    <span class="font-bold">Saldo Kas Besar: {{ formatRp(kasBesarBalance) }}</span>
                    <span v-if="isModalExceedsKasBesar" class="block mt-0.5 text-red-600 font-extrabold">
                      Modal awal melebihi saldo! Kurangi nominal atau minta admin menambah Kas Besar.
                    </span>
                    <span v-else class="block mt-0.5 text-teal-600">
                      Modal awal akan diambil dari Kas Besar toko secara otomatis.
                    </span>
                  </div>
                </div>

                <p class="text-[10px] text-slate-400 font-medium leading-normal">
                  Masukkan nominal uang kertas/logam fisik yang ada di dalam laci kasir saat ini sebagai modal awal transaksi.
                </p>
              </div>

              <!-- Notes -->
              <div class="space-y-1.5">
                <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wide">Catatan Shift (Opsional)</label>
                <textarea
                  v-model="notes"
                  rows="2"
                  class="w-full rounded-2xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold focus:border-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900/20 transition-all"
                  placeholder="Catatan tambahan..."
                  :disabled="!!activeSession"
                ></textarea>
              </div>
            </div>
          </div>

          <!-- Form Buttons -->
          <div v-if="!checking && !openedSessionData" class="flex gap-3 pt-3 border-t border-slate-100 shrink-0">
            <button
              type="button"
              class="px-5 py-3 rounded-2xl border border-slate-200 text-sm font-bold bg-white text-slate-700 hover:bg-slate-50 transition-colors"
              @click="onCancel"
            >
              Batal
            </button>
            
            <button
              type="submit"
              class="flex-1 rounded-2xl py-3 text-sm font-extrabold text-white transition-all shadow-md flex items-center justify-center gap-1.5 disabled:opacity-50"
              :class="activeSession
                ? 'bg-indigo-600 hover:bg-indigo-700'
                : isModalExceedsKasBesar
                  ? 'bg-red-500 cursor-not-allowed'
                  : 'bg-slate-900 hover:bg-slate-800'"
              :disabled="loading || isModalExceedsKasBesar || (!activeSession && (startBalance === null || startBalance < 0))"
            >
              <span v-if="loading" class="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent"></span>
              <span v-else-if="activeSession">Masuk Halaman POS</span>
              <span v-else-if="isModalExceedsKasBesar">🚫 Saldo Kas Besar Tidak Cukup</span>
              <span v-else>Mulai Shift & Buka Sesi</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  </AppShell>
</template>
