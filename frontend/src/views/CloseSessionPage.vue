<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import AppShell from '../components/AppShell.vue'
import api from '../services/api'

const router = useRouter()
const auth = useAuthStore()

// Input refs
const expensesAmount = ref<number>(0)
const depositAmount = ref<number>(0)
const actualBalance = ref<number | null>(null)
const differenceReason = ref('')
const notes = ref('')

const loading = ref(true)
const closing = ref(false)
const error = ref('')
const success = ref('')

// Session data
const session = ref<any>(null)
const salesCash = ref(0)
const salesQris = ref(0)
const salesTransfer = ref(0)
const salesCard = ref(0)
const salesTotal = ref(0)
const returnsTotal = ref(0) // Fixed to 0 per system context

// Live Cash Mutations
const mutationsIn = ref(0)
const mutationsOut = ref(0)

// Closed session result state
const closedSession = ref<any>(null)

const cashierName = computed(() => session.value?.user?.name || auth.user?.name || '-')
const storeName = computed(() => session.value?.store?.name || auth.user?.store?.name || 'Toko Nessa POS')

const money = (n: number) =>
  new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(Math.round(n))

// Expected Cash in Drawer = Mutations In - Mutations Out - Expenses - Vault deposit
// Note: mutationsIn already includes start_balance (modal_awal) and cash sales (penjualan_tunai)
const expectedCash = computed(() => {
  if (!session.value) return 0
  return mutationsIn.value - mutationsOut.value - expensesAmount.value - depositAmount.value
})

// Cash discrepancy = Actual Cash Counted - Expected Drawer Cash
const discrepancy = computed(() => {
  if (actualBalance.value === null) return 0
  return actualBalance.value - expectedCash.value
})

const isDiscrepant = computed(() => {
  return actualBalance.value !== null && discrepancy.value !== 0
})

const otherMutationsIn = computed(() => {
  if (!session.value) return 0
  const start = Number(session.value.start_balance)
  const diff = mutationsIn.value - start - salesCash.value
  return diff > 0.01 ? diff : 0
})

async function fetchSessionSummary() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/cashier-sessions/active/summary')
    session.value = data.session
    salesCash.value = Number(data.sales_cash)
    salesQris.value = Number(data.sales_qris)
    salesTransfer.value = Number(data.sales_transfer)
    salesCard.value = Number(data.sales_card)
    salesTotal.value = Number(data.sales_total)
    returnsTotal.value = Number(data.returns_total)
    
    // Live Cash Mutations
    mutationsIn.value = Number(data.mutations_in || 0)
    mutationsOut.value = Number(data.mutations_out || 0)
    
    // Autofill actual balance with expected cash initially for ease of use
    actualBalance.value = Number(data.mutations_in || 0) - Number(data.mutations_out || 0)
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Tidak ada sesi kasir aktif yang dapat ditutup.'
    // If not found, redirect after 3 seconds
    setTimeout(() => {
      if (!session.value) router.push('/')
    }, 3000)
  } finally {
    loading.value = false
  }
}

async function closeSession() {
  if (actualBalance.value === null || actualBalance.value < 0) {
    error.value = 'Saldo kas fisik (uang aktual) wajib diisi.'
    return
  }

  if (isDiscrepant.value && !differenceReason.value.trim()) {
    error.value = 'Alasan selisih kas wajib diisi jika terdapat selisih saldo.'
    return
  }

  closing.value = true
  error.value = ''
  success.value = ''

  try {
    const { data } = await api.post('/cashier-sessions/close', {
      expenses_amount: expensesAmount.value,
      deposit_amount: depositAmount.value,
      actual_balance: actualBalance.value,
      difference_reason: isDiscrepant.value ? differenceReason.value.trim() : undefined,
      notes: notes.value.trim() || undefined,
    })

    success.value = data.message || 'Sesi kasir berhasil ditutup.'
    closedSession.value = data.session
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Gagal menutup sesi kasir.'
  } finally {
    closing.value = false
  }
}

function printShiftReport() {
  if (!closedSession.value) return

  const store = auth.user?.store || {}
  const isRawBt = store.print_method === 'rawbt'

  const formattedOpened = new Date(closedSession.value.opened_at).toLocaleString('id-ID', {
    dateStyle: 'medium',
    timeStyle: 'short'
  })
  const formattedClosed = new Date(closedSession.value.closed_at).toLocaleString('id-ID', {
    dateStyle: 'medium',
    timeStyle: 'short'
  })

  // Format line row helper
  const addRow = (label: string, val: number, prefix: string = '') => {
    const valStr = `${prefix}Rp ${money(val)}`
    const spaces = Math.max(1, 32 - label.length - valStr.length)
    return label + ' '.repeat(spaces) + valStr + '\n'
  }

  if (isRawBt) {
    const line = '--------------------------------'
    let text = ''
    text += `${storeName.value.toUpperCase()}\n`
    text += `LAPORAN SHIFT CLOSING KASIR\n`
    text += `${line}\n`
    text += `No Sesi: ${closedSession.value.session_number}\n`
    text += `Kasir  : ${cashierName.value}\n`
    text += `Shift  : ${closedSession.value.shift.toUpperCase()}\n`
    text += `Buka   : ${formattedOpened}\n`
    text += `Tutup  : ${formattedClosed}\n`
    text += `${line}\n`
    text += addRow('Modal Awal Shift', Number(closedSession.value.start_balance))
    text += addRow('Penjualan Tunai', salesCash.value, '(+)')
    if (otherMutationsIn.value > 0) {
      text += addRow('Mutasi Masuk (Lain)', otherMutationsIn.value, '(+)')
    }
    if (mutationsOut.value > 0) {
      text += addRow('Total Mutasi Keluar', mutationsOut.value, '(-)')
    }
    text += addRow('Pengeluaran Laci', Number(closedSession.value.expenses_amount), '(-)')
    text += addRow('Setoran Kas Besar', Number(closedSession.value.deposit_amount), '(-)')
    text += `${line}\n`
    text += addRow('Saldo Seharusnya', Number(closedSession.value.expected_balance))
    text += addRow('Saldo Aktual (Fisik)', Number(closedSession.value.end_balance))
    text += `${line}\n`
    
    const diff = Number(closedSession.value.difference_amount)
    const diffStr = diff === 0 ? '0' : (diff > 0 ? '+' : '') + money(diff)
    const diffLine = `Selisih Kas`
    const spaces = Math.max(1, 32 - diffLine.length - diffStr.length)
    text += diffLine + ' '.repeat(spaces) + diffStr + '\n'
    
    if (closedSession.value.difference_reason) {
      text += `Alasan: "${closedSession.value.difference_reason}"\n`
    }
    text += `${line}\n`
    text += `Rincian Non-Tunai (Informasi):\n`
    text += addRow('Omzet QRIS', salesQris.value)
    text += addRow('Omzet Transfer', salesTransfer.value)
    text += addRow('Omzet Kartu Debit', salesCard.value)
    text += addRow('Total Omzet Shift', salesTotal.value)
    text += `${line}\n\n`
    
    text += `   Kasir Shift,   Supervisor,\n\n\n`
    text += `   (__________)   (__________)\n`
    text += `   ${cashierName.value}\n\n\n\n`

    const intentUrl = `intent:#Intent;action=ru.a402d.rawbtprinter.action.PRINT;category=android.intent.category.DEFAULT;type=text/plain;S.text=${encodeURIComponent(text)};end;`
    window.location.href = intentUrl
    return
  }

  // HTML print content for IFrame
  const printContentHtml = `
    <div style="text-align: center; font-weight: bold; font-size: 14px; margin-bottom: 2px; text-transform: uppercase;">
      ${storeName.value}
    </div>
    <div style="text-align: center; font-size: 10px; margin-bottom: 4px;">LAPORAN SHIFT CLOSING KASIR</div>
    <div style="border-top: 1px dashed #000; margin: 6px 0;"></div>
    <div style="font-size: 10px; line-height: 1.3;">
      <div>No Sesi : ${closedSession.value.session_number}</div>
      <div>Kasir   : ${cashierName.value}</div>
      <div>Shift   : ${closedSession.value.shift.toUpperCase()}</div>
      <div>Buka    : ${formattedOpened}</div>
      <div>Tutup   : ${formattedClosed}</div>
    </div>
    <div style="border-top: 1px dashed #000; margin: 6px 0;"></div>
    <div style="font-size: 10px; line-height: 1.3;">
      <div style="display: flex; justify-content: space-between; margin-bottom: 2px;"><span>Modal Awal Shift</span><span>Rp ${money(Number(closedSession.value.start_balance))}</span></div>
      <div style="display: flex; justify-content: space-between; margin-bottom: 2px;"><span>(+) Penjualan Tunai</span><span>Rp ${money(salesCash.value)}</span></div>
      ${otherMutationsIn.value > 0 ? `<div style="display: flex; justify-content: space-between; margin-bottom: 2px;"><span>(+) Mutasi Masuk (Lainnya)</span><span>Rp ${money(otherMutationsIn.value)}</span></div>` : ''}
      ${mutationsOut.value > 0 ? `<div style="display: flex; justify-content: space-between; margin-bottom: 2px;"><span>(-) Total Mutasi Keluar</span><span>-Rp ${money(mutationsOut.value)}</span></div>` : ''}
      <div style="display: flex; justify-content: space-between; margin-bottom: 2px;"><span>(-) Pengeluaran Laci</span><span>-Rp ${money(Number(closedSession.value.expenses_amount))}</span></div>
      <div style="display: flex; justify-content: space-between; margin-bottom: 2px;"><span>(-) Setoran Kas Besar</span><span>-Rp ${money(Number(closedSession.value.deposit_amount))}</span></div>
      <div style="border-top: 1px dashed #000; margin: 4px 0;"></div>
      <div style="display: flex; justify-content: space-between; font-weight: bold; margin-bottom: 2px;"><span>Saldo Kas Seharusnya</span><span>Rp ${money(Number(closedSession.value.expected_balance))}</span></div>
      <div style="display: flex; justify-content: space-between; font-weight: bold; margin-bottom: 2px;"><span>Saldo Kas Aktual (Fisik)</span><span>Rp ${money(Number(closedSession.value.end_balance))}</span></div>
      <div style="border-top: 1px dashed #000; margin: 4px 0;"></div>
      <div style="display: flex; justify-content: space-between; font-weight: bold; margin-bottom: 2px;">
        <span>Selisih Kas</span>
        <span>${Number(closedSession.value.difference_amount) === 0 ? '0' : (Number(closedSession.value.difference_amount) > 0 ? '+' : '') + money(Number(closedSession.value.difference_amount))}</span>
      </div>
      ${closedSession.value.difference_reason ? `<div style="font-size: 9px; font-style: italic; margin-top: 4px; line-height: 1.2;">Alasan: "${closedSession.value.difference_reason}"</div>` : ''}
    </div>
    <div style="border-top: 1px dashed #000; margin: 6px 0;"></div>
    <div style="font-size: 10px; line-height: 1.3;">
      <div style="font-weight: bold; margin-bottom: 4px; text-transform: uppercase;">Rincian Non-Tunai (Informasi):</div>
      <div style="display: flex; justify-content: space-between; margin-bottom: 2px;"><span>Omzet QRIS</span><span>Rp ${money(salesQris.value)}</span></div>
      <div style="display: flex; justify-content: space-between; margin-bottom: 2px;"><span>Omzet Transfer</span><span>Rp ${money(salesTransfer.value)}</span></div>
      <div style="display: flex; justify-content: space-between; margin-bottom: 2px;"><span>Omzet Kartu Debit</span><span>Rp ${money(salesCard.value)}</span></div>
      <div style="display: flex; justify-content: space-between; font-weight: bold; margin-bottom: 2px;"><span>Total Omzet Shift</span><span>Rp ${money(salesTotal.value)}</span></div>
    </div>
    <div style="border-top: 1px dashed #000; margin: 6px 0;"></div>
    <div style="margin-top: 16px;">
      <div style="float: left; width: 45%; text-align: center; font-size: 9px;">
        <div>Kasir Shift,</div>
        <br/><br/><br/>
        <div>( __________________ )</div>
        <div style="font-weight: bold; margin-top: 4px;">${cashierName.value}</div>
      </div>
      <div style="float: right; width: 45%; text-align: center; font-size: 9px;">
        <div>Supervisor,</div>
        <br/><br/><br/>
        <div>( __________________ )</div>
        <div style="font-weight: bold; margin-top: 4px;">Tanda Tangan</div>
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
        <title>Laporan Shift Closing</title>
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

function goBack() {
  router.push('/')
}

onMounted(() => {
  fetchSessionSummary()
})
</script>

<template>
  <AppShell>
    <div class="p-2 sm:p-4 flex items-center justify-center">
      
      <!-- Outer Container -->
      <div class="w-full max-w-2xl bg-white rounded-3xl border border-slate-200 shadow-xl overflow-hidden flex flex-col">
        
        <!-- Top Banner Header -->
        <div class="bg-slate-900 text-white p-6 relative shrink-0">
          <div class="space-y-1">
            <span class="inline-flex rounded-full bg-slate-800 text-slate-300 px-3 py-1 text-[10px] font-extrabold uppercase tracking-widest">
              Shift Sesi Kasir
            </span>
            <h2 class="text-xl font-bold tracking-tight">
              {{ closedSession ? 'Laporan Closing Sesi Kasir' : 'Tutup Sesi Operasional' }}
            </h2>
            <p class="text-xs text-slate-400 font-medium">
              {{ closedSession ? 'Sesi kasir telah ditutup. Silakan cetak laporan shift ini.' : 'Hitung saldo kas fisik laci untuk mencocokkan pencatatan penjualan.' }}
            </p>
          </div>
          <span class="absolute top-6 right-6 text-4xl">🛑</span>
        </div>

        <!-- Main Body -->
        <div class="p-6 flex-1 overflow-y-auto max-h-[75vh]">
          
          <!-- Loading State -->
          <div v-if="loading" class="py-16 text-center text-slate-400 text-xs font-semibold">
            <div class="h-8 w-8 animate-spin rounded-full border-4 border-slate-900 border-t-transparent mx-auto mb-3"></div>
            Memuat data ringkasan sesi aktif...
          </div>

          <!-- No Active Session screen -->
          <div v-else-if="!session && error" class="py-12 text-center space-y-4">
            <div class="text-4xl">⚠️</div>
            <p class="font-extrabold text-slate-800 text-sm">{{ error }}</p>
            <p class="text-xs text-slate-400">Kembali ke Dashboard utama dalam beberapa detik...</p>
            <button type="button" @click="goBack" class="rounded-xl bg-slate-900 px-4 py-2 text-xs font-bold text-white hover:bg-slate-800">
              Kembali Sekarang
            </button>
          </div>

          <!-- Main Form & Content -->
          <div v-else class="space-y-6">
            <p v-if="error" class="rounded-xl bg-red-50 border border-red-200 px-3.5 py-2.5 text-xs font-bold text-red-800 leading-normal">{{ error }}</p>
            <p v-if="success" class="rounded-xl bg-emerald-50 border border-emerald-200 px-3.5 py-2.5 text-xs font-bold text-emerald-800 leading-normal">{{ success }}</p>

            <!-- 1. Session Information Block -->
            <div class="bg-slate-50 border border-slate-200/50 rounded-2xl p-4 text-xs text-slate-600 font-semibold space-y-2">
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <span class="block text-[10px] text-slate-400 uppercase tracking-wide">Nomor Sesi</span>
                  <span class="block text-slate-950 font-bold font-mono">{{ session.session_number }}</span>
                </div>
                <div>
                  <span class="block text-[10px] text-slate-400 uppercase tracking-wide">Nama Kasir</span>
                  <span class="block text-slate-950 font-bold">👤 {{ cashierName }}</span>
                </div>
                <div>
                  <span class="block text-[10px] text-slate-400 uppercase tracking-wide">Shift Operasional</span>
                  <span class="block text-slate-950 font-bold uppercase">{{ session.shift }}</span>
                </div>
                <div>
                  <span class="block text-[10px] text-slate-400 uppercase tracking-wide">Cabang Toko</span>
                  <span class="block text-slate-950 font-bold">🏪 {{ storeName }}</span>
                </div>
                <div class="col-span-2 pt-2 border-t border-slate-200/50">
                  <span class="block text-[10px] text-slate-400 uppercase tracking-wide">Waktu Buka Shift</span>
                  <span class="block text-slate-950">📅 {{ new Date(session.opened_at).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) }} WIB</span>
                </div>
              </div>
            </div>

            <!-- 2. Sales Summary Breakdown -->
            <div class="space-y-2.5">
              <h3 class="text-xs font-bold text-slate-700 uppercase tracking-wider px-1">Ringkasan Penjualan Shift</h3>
              <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                <!-- Tunai -->
                <div class="bg-emerald-50/50 border border-emerald-100 rounded-2xl p-3 text-center">
                  <span class="block text-[9px] font-bold text-emerald-700 uppercase tracking-wide">Uang Tunai</span>
                  <span class="block font-mono font-extrabold text-emerald-800 text-sm mt-1">Rp {{ money(salesCash) }}</span>
                </div>
                <!-- QRIS -->
                <div class="bg-purple-50/50 border border-purple-100 rounded-2xl p-3 text-center">
                  <span class="block text-[9px] font-bold text-purple-700 uppercase tracking-wide">QRIS</span>
                  <span class="block font-mono font-extrabold text-purple-800 text-sm mt-1">Rp {{ money(salesQris) }}</span>
                </div>
                <!-- Transfer -->
                <div class="bg-amber-50/50 border border-amber-100 rounded-2xl p-3 text-center">
                  <span class="block text-[9px] font-bold text-slate-700 uppercase tracking-wide">Transfer</span>
                  <span class="block font-mono font-extrabold text-amber-800 text-sm mt-1">Rp {{ money(salesTransfer) }}</span>
                </div>
                <!-- Kartu -->
                <div class="bg-blue-50/50 border border-blue-100 rounded-2xl p-3 text-center">
                  <span class="block text-[9px] font-bold text-blue-700 uppercase tracking-wide">Kartu Debit</span>
                  <span class="block font-mono font-extrabold text-blue-800 text-sm mt-1">Rp {{ money(salesCard) }}</span>
                </div>
              </div>
              <div class="grid grid-cols-2 gap-2">
                <div class="bg-slate-50 border border-slate-100 rounded-2xl p-2.5 text-center text-xs">
                  <span class="text-slate-400 font-bold">Total Retur:</span>
                  <span class="font-bold text-slate-800 font-mono ml-2">Rp {{ money(returnsTotal) }}</span>
                </div>
                <div class="bg-slate-900 border border-slate-900 rounded-2xl p-2.5 text-center text-xs text-white">
                  <span class="text-slate-300 font-bold">Total Omzet:</span>
                  <span class="font-extrabold font-mono ml-2">Rp {{ money(salesTotal) }}</span>
                </div>
              </div>
            </div>

            <!-- If session is NOT closed yet, show inputs form -->
            <div v-if="!closedSession" class="space-y-4 pt-2 border-t border-slate-100">

              <!-- 4. Cash Balances Calculation Block -->
              <div class="bg-slate-50 border border-slate-200 rounded-2xl p-4 space-y-3">
                <h4 class="text-xs font-extrabold text-slate-800 uppercase tracking-wide border-b border-slate-200 pb-2">
                  Perhitungan Laci Kas (Tunai)
                </h4>
                
                <div class="space-y-2 text-xs text-slate-600 font-medium">
                  <div class="flex justify-between">
                    <span>Modal Awal Kas</span>
                    <span class="font-mono text-slate-900 font-bold">Rp {{ money(Number(session.start_balance)) }}</span>
                  </div>
                  <div class="flex justify-between">
                    <span>(+) Penjualan Tunai</span>
                    <span class="font-mono text-slate-900 font-bold">Rp {{ money(salesCash) }}</span>
                  </div>
                  <div v-if="otherMutationsIn > 0" class="flex justify-between text-emerald-600">
                    <span>(+) Mutasi Kas Masuk (Lainnya)</span>
                    <span class="font-mono font-bold">+Rp {{ money(otherMutationsIn) }}</span>
                  </div>
                  <div v-if="mutationsOut > 0" class="flex justify-between text-red-600">
                    <span>(-) Mutasi Kas Keluar</span>
                    <span class="font-mono font-bold">-Rp {{ money(mutationsOut) }}</span>
                  </div>
                  <div v-if="expensesAmount > 0" class="flex justify-between text-red-600">
                    <span>(-) Pengeluaran Kasir</span>
                    <span class="font-mono font-bold">-Rp {{ money(expensesAmount) }}</span>
                  </div>
                  <div v-if="depositAmount > 0" class="flex justify-between text-red-600">
                    <span>(-) Setoran Kas Besar</span>
                    <span class="font-mono font-bold">-Rp {{ money(depositAmount) }}</span>
                  </div>
                  
                  <div class="border-t border-slate-200 my-1.5"></div>

                  <div class="flex justify-between text-sm font-extrabold text-slate-950 py-0.5">
                    <span>Saldo Laci Kas Seharusnya (Sistem)</span>
                    <span class="font-mono text-blue-600">Rp {{ money(expectedCash) }}</span>
                  </div>
                </div>
              </div>

              <!-- 5. Actual Balance Inputs & Discrepancies -->
              <div class="space-y-3.5 bg-slate-50 border border-slate-200 rounded-2xl p-4">
                <div>
                  <label class="mb-1 block text-xs font-extrabold text-slate-800 uppercase tracking-wide">Saldo Kas Fisik Aktual (Hitung Manual)</label>
                  <div class="relative rounded-2xl shadow-sm">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-500 font-extrabold text-sm font-mono">Rp</div>
                    <input
                      v-model.number="actualBalance"
                      type="number"
                      min="0"
                      step="any"
                      class="w-full rounded-2xl border border-slate-300 bg-white pl-10 pr-4 py-3 text-base font-extrabold font-mono text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900/20 text-right"
                      placeholder="Hitung jumlah uang fisik..."
                      required
                    />
                  </div>
                  <p class="text-[10px] text-slate-400 mt-1 leading-normal">Masukkan hasil perhitungan uang tunai fisik yang tersisa di laci kas saat ini.</p>
                </div>

                <!-- Discrepancy Display -->
                <div v-if="actualBalance !== null" class="flex items-center justify-between text-xs font-bold p-3 rounded-xl border" :class="discrepancy === 0 ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-red-50 border-red-200 text-red-800'">
                  <span>Selisih Kas:</span>
                  <span class="font-mono text-sm">
                    {{ discrepancy === 0 ? 'Cocok (Tidak ada selisih)' : (discrepancy > 0 ? '+' : '') + money(discrepancy) }}
                  </span>
                </div>

                <!-- Required Reason for Discrepancy -->
                <div v-if="isDiscrepant" class="space-y-1.5 animate-slide-up">
                  <label class="block text-xs font-extrabold text-red-800 uppercase tracking-wide">
                    Alasan Selisih Kas <span class="text-red-600">* Wajib Diisi</span>
                  </label>
                  <textarea
                    v-model="differenceReason"
                    rows="2"
                    class="w-full rounded-xl border border-red-300 bg-red-50/20 px-3 py-2 text-xs font-semibold focus:outline-none focus:ring-2 focus:ring-red-900/10 text-red-900"
                    placeholder="Jelaskan alasan terjadinya selisih saldo laci kas hari ini..."
                    required
                  ></textarea>
                </div>
              </div>

              <!-- Notes -->
              <div class="space-y-1.5">
                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider">Catatan Akhir Shift (Opsional)</label>
                <textarea
                  v-model="notes"
                  rows="2"
                  class="w-full rounded-2xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold focus:border-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900/20 transition-all"
                  placeholder="Catatan tambahan mengenai tutup shift..."
                ></textarea>
              </div>
            </div>

            <!-- If session is CLOSED (Success state showing finalized report) -->
            <div v-else class="space-y-5 animate-slide-up">
              <!-- Summary closing message -->
              <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-xs font-semibold text-emerald-800 text-center leading-relaxed">
                🎉 Sesi operasional kasir shift <span class="uppercase">{{ closedSession.shift }}</span> berhasil ditutup pada 
                {{ new Date(closedSession.closed_at).toLocaleTimeString('id-ID') }} WIB. Konteks shift terkunci dan tidak dapat menerima transaksi penjualan baru.
              </div>

              <!-- Virtual Closed Report details -->
              <div class="border border-slate-200 bg-slate-50/50 rounded-2xl p-5 shadow-inner space-y-3 text-xs text-slate-600 font-medium">
                <h4 class="text-center font-extrabold text-slate-900 text-sm uppercase">Laporan Closing Shift Kasir</h4>
                <div class="border-t border-dashed border-slate-300 my-2"></div>
                
                <div class="grid grid-cols-2 gap-y-2">
                  <div>Nomor Sesi:</div><div class="text-right font-bold text-slate-900 font-mono">{{ closedSession.session_number }}</div>
                  <div>Kasir:</div><div class="text-right text-slate-900 font-bold">👤 {{ cashierName }}</div>
                  <div>Shift:</div><div class="text-right text-slate-900 font-bold uppercase">{{ closedSession.shift }}</div>
                  <div>Waktu Buka:</div><div class="text-right text-slate-900 font-mono">{{ new Date(closedSession.opened_at).toLocaleString('id-ID') }}</div>
                  <div>Waktu Tutup:</div><div class="text-right text-slate-900 font-mono">{{ new Date(closedSession.closed_at).toLocaleString('id-ID') }}</div>
                </div>

                <div class="border-t border-dashed border-slate-300 my-2"></div>
                
                <div class="grid grid-cols-2 gap-y-2">
                  <div>Modal Awal Shift:</div><div class="text-right text-slate-900 font-mono">Rp {{ money(Number(closedSession.start_balance)) }}</div>
                  <div>(+) Total Penjualan Tunai:</div><div class="text-right text-slate-900 font-mono">Rp {{ money(salesCash) }}</div>
                  <div v-if="otherMutationsIn > 0">(+) Mutasi Kas Masuk (Lainnya):</div><div v-if="otherMutationsIn > 0" class="text-right text-slate-900 font-mono">Rp {{ money(otherMutationsIn) }}</div>
                  <div v-if="mutationsOut > 0">(-) Total Mutasi Kas Keluar:</div><div v-if="mutationsOut > 0" class="text-right text-red-600 font-mono">-Rp {{ money(mutationsOut) }}</div>
                  <div>(-) Total Pengeluaran Kasir:</div><div class="text-right text-red-600 font-mono">-Rp {{ money(Number(closedSession.expenses_amount)) }}</div>
                  <div>(-) Total Setoran Kas Besar:</div><div class="text-right text-red-600 font-mono">-Rp {{ money(Number(closedSession.deposit_amount)) }}</div>
                  <div class="font-bold text-slate-900">Saldo Kas Seharusnya:</div><div class="text-right font-bold text-slate-900 font-mono">Rp {{ money(Number(closedSession.expected_balance)) }}</div>
                  <div class="font-extrabold text-slate-950">Saldo Uang Fisik Aktual:</div><div class="text-right font-extrabold text-slate-950 font-mono text-blue-600">Rp {{ money(Number(closedSession.end_balance)) }}</div>
                </div>

                <div class="border-t border-dashed border-slate-300 my-2"></div>

                <div class="grid grid-cols-2 gap-y-2" :class="Number(closedSession.difference_amount) !== 0 ? 'text-red-700 font-bold' : 'text-emerald-700 font-bold'">
                  <div>Selisih Kas:</div>
                  <div class="text-right font-mono text-sm">
                    {{ Number(closedSession.difference_amount) === 0 ? 'Cocok (0)' : (Number(closedSession.difference_amount) > 0 ? '+' : '') + money(Number(closedSession.difference_amount)) }}
                  </div>
                </div>
                <div v-if="closedSession.difference_reason" class="bg-red-50 p-2.5 rounded-xl border border-red-100 text-[11px] text-red-800 leading-normal mt-2">
                  <span class="font-bold block mb-1">Alasan Selisih:</span>
                  "{{ closedSession.difference_reason }}"
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Action Footer Buttons -->
        <div v-if="!loading && session" class="px-6 py-4 border-t border-slate-100 bg-slate-50 flex gap-3 shrink-0">
          <!-- If not closed yet -->
          <template v-if="!closedSession">
            <button
              type="button"
              class="px-5 py-3 rounded-2xl border border-slate-200 text-sm font-bold bg-white text-slate-700 hover:bg-slate-50 transition-colors"
              @click="goBack"
            >
              Batal
            </button>
            
            <button
              type="button"
              class="flex-1 rounded-2xl bg-red-600 py-3 text-sm font-extrabold text-white hover:bg-red-700 transition-all shadow-md flex items-center justify-center gap-1.5"
              :disabled="closing || actualBalance === null || (isDiscrepant && !differenceReason.trim())"
              @click="closeSession"
            >
              <span v-if="closing" class="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent"></span>
              Tutup Sesi Shift
            </button>
          </template>
          
          <!-- If closed success state -->
          <template v-else>
            <button
              type="button"
              class="flex-1 rounded-2xl bg-slate-900 py-3 text-sm font-extrabold text-white hover:bg-slate-800 shadow-md flex items-center justify-center gap-1.5 transition-all"
              @click="printShiftReport"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
              </svg>
              Cetak Laporan Shift
            </button>
            
            <button
              type="button"
              class="px-6 py-3 rounded-2xl border border-slate-200 text-sm font-bold bg-white text-slate-700 hover:bg-slate-50 transition-colors"
              @click="goBack"
            >
              Dashboard
            </button>
          </template>
        </div>
      </div>
    </div>
  </AppShell>
</template>

<style scoped>
@keyframes slideUp {
  from {
    transform: translateY(10%);
    opacity: 0;
  }
  to {
    transform: translateY(0);
    opacity: 1;
  }
}
.animate-slide-up {
  animation: slideUp 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
</style>
