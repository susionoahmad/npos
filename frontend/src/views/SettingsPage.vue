<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import AppShell from '../components/AppShell.vue'
import api from '../services/api'
import { useAuthStore } from '../stores/auth'
import { pickErrorMessage, pickFieldErrors } from '../utils/laravelErrors'

type Store = {
  id: number
  name: string
  address: string | null
  phone: string | null
  currency: string
  is_license_activated: boolean
  license_activated_at: string | null
  receipt_footer: string | null
  print_method: string
}

const auth = useAuthStore()

const tabs = computed(() => {
  const list = []
  if (auth.user?.store_id && !auth.isOwner && !auth.isSuperAdmin) {
    list.push({ id: 'store', label: 'Profil Toko', desc: 'Detail cabang toko saat ini' })
  }
  if (auth.isOwner) {
    list.push({ id: 'tenant', label: 'Perusahaan & Cabang', desc: 'Kelola perusahaan dan cabang' })
  }
  if (auth.isSuperAdmin) {
    list.push({ id: 'superadmin', label: 'SaaS Platform', desc: 'Kelola tenant global & lisensi' })
  }
  return list
})

const activeTab = ref('')

// Store profile ref
const loading = ref(false)
const saving = ref(false)
const activating = ref(false)
const error = ref<string | null>(null)
const success = ref<string | null>(null)
const fieldErrors = ref<Record<string, string[]>>({})

const store = ref<Store | null>(null)
const form = ref({
  name: '',
  address: '',
  phone: '',
  currency: 'IDR',
  receipt_footer: '',
  print_method: 'browser',
})

// Tenant refs (Owner)
const tenant = ref<any>(null)
const tenantStores = ref<any[]>([])
const loadingTenant = ref(false)
const savingTenant = ref(false)
const tenantForm = ref({ name: '' })

// Add Store Branch (Owner)
const showAddStoreModal = ref(false)
const creatingStore = ref(false)
const newStoreForm = ref({
  name: '',
  address: '',
  phone: '',
  currency: 'IDR',
})

// Superadmin refs
const tenants = ref<any[]>([])
const loadingSuper = ref(false)
const showAddTenantModal = ref(false)
const creatingTenant = ref(false)
const newTenantForm = ref({
  company_name: '',
  owner_name: '',
  owner_email: '',
  owner_password: '',
  store_name: '',
})

// Load active store profile
async function fetchStore() {
  if (!auth.user?.store_id) return
  loading.value = true
  error.value = null
  success.value = null
  try {
    const { data } = await api.get<Store>('/store')
    store.value = data
    form.value = {
      name: data.name ?? '',
      address: data.address ?? '',
      phone: data.phone ?? '',
      currency: data.currency ?? 'IDR',
      receipt_footer: data.receipt_footer ?? '',
      print_method: data.print_method ?? 'browser',
    }
  } catch (e: any) {
    error.value = e?.response?.data?.message || e?.message || 'Gagal memuat data toko'
  } finally {
    loading.value = false
  }
}

// Save active store profile
async function save() {
  success.value = null
  error.value = null
  fieldErrors.value = {}
  const payload = {
    name: form.value.name.trim(),
    address: form.value.address.trim() || null,
    phone: form.value.phone.trim() || null,
    currency: form.value.currency.trim() || 'IDR',
    receipt_footer: form.value.receipt_footer.trim() || null,
    print_method: form.value.print_method || 'browser',
  }
  if (!payload.name || !payload.currency) return

  saving.value = true
  try {
    const { data } = await api.put<Store>('/settings/store', payload)
    store.value = data
    success.value = 'Pengaturan toko berhasil disimpan.'
    
    // Update active store in Auth state
    if (auth.user?.store) {
      auth.user.store.name = data.name
      auth.user.store.address = data.address
      auth.user.store.phone = data.phone
      auth.user.store.receipt_footer = data.receipt_footer
      auth.user.store.print_method = data.print_method
    }
  } catch (e: any) {
    fieldErrors.value = pickFieldErrors(e)
    error.value = pickErrorMessage(e, 'Gagal menyimpan pengaturan')
  } finally {
    saving.value = false
  }
}

// Activate license (Owner)
async function activateLicense(storeId?: number) {
  error.value = null
  success.value = null
  if (!confirm('Aktifkan lisensi toko ini sekarang?')) return
  activating.value = true
  try {
    const payload = storeId ? { store_id: storeId } : {}
    const { data } = await api.post<Store>('/store/activate-license', payload)
    if (!storeId || storeId === auth.user?.store_id) {
      store.value = data
    }
    success.value = 'Lisensi berhasil diaktifkan.'
    if (auth.isOwner) {
      fetchTenantStores()
    }
  } catch (e: any) {
    error.value = e?.response?.data?.message || e?.message || 'Gagal aktivasi lisensi'
  } finally {
    activating.value = false
  }
}

// Load Tenant & Stores details (Owner)
async function fetchTenantStores() {
  loadingTenant.value = true
  error.value = null
  try {
    if (auth.user?.tenant) {
      tenant.value = auth.user.tenant
      tenantForm.value.name = auth.user.tenant.name
    }
    const { data } = await api.get('/tenant/stores')
    tenantStores.value = data || []
    
    // Also fetch fresh billing details to sync max_stores/slots limit
    const billingRes = await api.get('/billing/overview')
    tenant.value = billingRes.data.tenant
    if (auth.user) {
      auth.user.tenant = billingRes.data.tenant
    }
  } catch (e: any) {
    error.value = 'Gagal memuat cabang toko dan perusahaan.'
  } finally {
    loadingTenant.value = false
  }
}

// Save Company Profile (Owner)
async function saveTenant() {
  success.value = null
  error.value = null
  if (!tenantForm.value.name.trim()) return
  savingTenant.value = true
  try {
    const { data } = await api.put('/tenant', { name: tenantForm.value.name.trim() })
    tenant.value = data.tenant
    success.value = 'Profil perusahaan berhasil diperbarui.'
    
    // Update Pinia Auth state
    if (auth.user?.tenant) {
      auth.user.tenant.name = data.tenant.name
    }
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Gagal memperbarui profil perusahaan'
  } finally {
    savingTenant.value = false
  }
}


// Create New Store Branch (Owner)
async function createStoreBranch() {
  success.value = null
  error.value = null
  if (!newStoreForm.value.name.trim()) return
  creatingStore.value = true
  try {
    await api.post('/tenant/stores', {
      name: newStoreForm.value.name.trim(),
      address: newStoreForm.value.address.trim() || null,
      phone: newStoreForm.value.phone.trim() || null,
      currency: newStoreForm.value.currency.trim() || 'IDR',
    })
    success.value = 'Cabang toko baru berhasil didaftarkan.'
    showAddStoreModal.value = false
    newStoreForm.value = { name: '', address: '', phone: '', currency: 'IDR' }
    fetchTenantStores()
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Gagal mendaftarkan cabang baru'
  } finally {
    creatingStore.value = false
  }
}

// Load all tenants (Superadmin)
async function fetchAllTenants() {
  loadingSuper.value = true
  error.value = null
  try {
    const { data } = await api.get('/superadmin/tenants')
    tenants.value = data || []
  } catch (e) {
    error.value = 'Gagal memuat daftar tenant SaaS.'
  } finally {
    loadingSuper.value = false
  }
}

// Toggle license of any store globally (Superadmin)
async function onToggleGlobalLicense(storeId: number) {
  try {
    await api.post(`/superadmin/stores/${storeId}/toggle-license`)
    success.value = 'Lisensi toko berhasil diperbarui.'
    fetchAllTenants()
  } catch (e: any) {
    alert(e?.response?.data?.message || 'Gagal memproses lisensi')
  }
}

// Create Tenant & Owner & Store (Superadmin)
async function createTenant() {
  success.value = null
  error.value = null
  const formVal = newTenantForm.value
  if (!formVal.company_name || !formVal.owner_name || !formVal.owner_email || !formVal.owner_password || !formVal.store_name) {
    error.value = 'Semua field wajib diisi.'
    return
  }

  creatingTenant.value = true
  try {
    await api.post('/superadmin/tenants', formVal)
    success.value = `Tenant "${formVal.company_name}" berhasil didaftarkan.`
    showAddTenantModal.value = false
    newTenantForm.value = {
      company_name: '',
      owner_name: '',
      owner_email: '',
      owner_password: '',
      store_name: '',
    }
    fetchAllTenants()
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Gagal membuat tenant baru'
  } finally {
    creatingTenant.value = false
  }
}

// State for editing store settings directly (Owner consolidation mode)
const editStoreModalOpen = ref(false)
const editingStoreModel = ref<any>(null)
const editForm = ref({
  name: '',
  phone: '',
  currency: 'IDR',
  print_method: 'browser',
  address: '',
  receipt_footer: '',
})

function openEditStore(s: any) {
  editingStoreModel.value = s
  editForm.value = {
    name: s.name ?? '',
    phone: s.phone ?? '',
    currency: s.currency ?? 'IDR',
    print_method: s.print_method ?? 'browser',
    address: s.address ?? '',
    receipt_footer: s.receipt_footer ?? '',
  }
  editStoreModalOpen.value = true
}

async function saveStoreEdit() {
  success.value = null
  error.value = null
  fieldErrors.value = {}
  const payload = {
    store_id: editingStoreModel.value.id,
    name: editForm.value.name.trim(),
    address: editForm.value.address.trim() || null,
    phone: editForm.value.phone.trim() || null,
    currency: editForm.value.currency.trim() || 'IDR',
    receipt_footer: editForm.value.receipt_footer.trim() || null,
    print_method: editForm.value.print_method || 'browser',
  }
  if (!payload.name || !payload.currency) return

  saving.value = true
  try {
    const { data } = await api.put('/settings/store', payload)
    success.value = `Pengaturan toko "${data.name}" berhasil disimpan.`
    editStoreModalOpen.value = false
    fetchTenantStores()
  } catch (e: any) {
    fieldErrors.value = pickFieldErrors(e)
    error.value = pickErrorMessage(e, 'Gagal menyimpan pengaturan')
  } finally {
    saving.value = false
  }
}

const selectTab = (tabId: string) => {
  activeTab.value = tabId
  success.value = null
  error.value = null
  if (tabId === 'store') {
    if (auth.user?.store_id) {
      fetchStore()
    } else if (auth.isOwner) {
      fetchTenantStores()
    }
  }
  if (tabId === 'tenant') fetchTenantStores()
  if (tabId === 'superadmin') fetchAllTenants()
}

watch(
  () => auth.user,
  (user) => {
    if (user && !activeTab.value) {
      const availableTabs = tabs.value
      if (availableTabs.length > 0) {
        activeTab.value = availableTabs[0].id
        selectTab(activeTab.value)
      }
    }
  },
  { immediate: true }
)

onMounted(() => {
  // Handled by the watcher on auth.user
})
</script>

<template>
  <AppShell>
    <div class="space-y-4">
      <!-- Title Section -->
      <div>
        <h2 class="text-2xl font-bold tracking-tight text-slate-900">Pengaturan</h2>
        <p class="text-xs text-slate-500 font-medium">Kelola profil toko, perusahaan multi-cabang, atau lisensi SaaS Anda</p>
      </div>

      <!-- Error & Success Alerts -->
      <div v-if="error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-xs font-semibold text-red-800 shadow-sm">
        {{ error }}
      </div>
      <div v-if="success" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-xs font-semibold text-emerald-800 shadow-sm">
        {{ success }}
      </div>

      <!-- Settings Layout wrapper -->
      <div class="flex flex-col lg:flex-row gap-4 items-start">
        
        <!-- Left: Tab Navigation Menu -->
        <div class="flex flex-row lg:flex-col gap-2 overflow-x-auto scrollbar-none pb-2 lg:pb-0 shrink-0 w-full lg:w-[260px]">
          <button
            v-for="t in tabs"
            :key="t.id"
            type="button"
            class="shrink-0 text-left px-4 py-3.5 rounded-2xl border transition-all flex-1 lg:flex-none"
            :class="activeTab === t.id ? 'bg-slate-900 border-slate-900 text-white shadow-sm font-bold' : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50 hover:text-slate-900'"
            @click="selectTab(t.id)"
          >
            <span class="block text-sm">{{ t.label }}</span>
            <span class="hidden lg:block text-[10px] opacity-70 font-medium mt-0.5 leading-tight">{{ t.desc }}</span>
          </button>
        </div>

        <!-- Right: Active Settings Tab Content -->
        <div class="flex-1 w-full space-y-4">
          
          <!-- TAB 1: PROFIL TOKO -->
          <div v-if="activeTab === 'store'">
            <!-- Case 1: Specific store active -->
            <div v-if="auth.user?.store_id" class="grid gap-4 md:grid-cols-3">
              <!-- Edit Form Card -->
              <div class="md:col-span-2 bg-white rounded-2xl border border-slate-200 p-5 shadow-sm space-y-4">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                  <div>
                    <h3 class="font-extrabold text-slate-900 text-sm">Profil Toko Aktif</h3>
                    <p class="text-[11px] text-slate-500 font-medium">Informasi cabang toko saat ini</p>
                  </div>
                  <button
                    class="rounded-xl border border-slate-200 px-3.5 py-1.5 text-xs font-bold text-slate-700 bg-slate-50 hover:bg-slate-100 disabled:opacity-50 transition-colors"
                    type="button"
                    :disabled="loading"
                    @click="fetchStore"
                  >
                    Refresh
                  </button>
                </div>

                <div v-if="loading" class="py-12 text-center text-slate-400 text-xs font-medium">
                  <div class="h-6 w-6 animate-spin rounded-full border-2 border-slate-900 border-t-transparent mx-auto mb-2"></div>
                  Memuat data toko...
                </div>
                
                <div v-else class="grid gap-3 sm:grid-cols-2">
                  <div class="sm:col-span-2">
                    <label class="mb-1 block text-xs font-bold text-slate-600 uppercase tracking-wide">Nama Toko</label>
                    <input
                      v-model="form.name"
                      class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold focus:border-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900/20 transition-all"
                      :class="fieldErrors.name ? 'border-red-300 bg-red-50/50' : ''"
                      maxlength="120"
                      placeholder="Contoh: Toko Cabang A"
                    />
                    <div v-if="fieldErrors.name?.length" class="mt-1 text-xs text-red-700 font-semibold">{{ fieldErrors.name[0] }}</div>
                  </div>

                  <div>
                    <label class="mb-1 block text-xs font-bold text-slate-600 uppercase tracking-wide">Telepon</label>
                    <input
                      v-model="form.phone"
                      class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold focus:border-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900/20 transition-all font-mono"
                      :class="fieldErrors.phone ? 'border-red-300 bg-red-50/50' : ''"
                      maxlength="32"
                      placeholder="Contoh: 0812345678"
                    />
                    <div v-if="fieldErrors.phone?.length" class="mt-1 text-xs text-red-700 font-semibold">{{ fieldErrors.phone[0] }}</div>
                  </div>

                  <div>
                    <label class="mb-1 block text-xs font-bold text-slate-600 uppercase tracking-wide">Mata Uang</label>
                    <input
                      v-model="form.currency"
                      class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold focus:border-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900/20 transition-all font-mono"
                      :class="fieldErrors.currency ? 'border-red-300 bg-red-50/50' : ''"
                      maxlength="8"
                      placeholder="IDR"
                    />
                    <div v-if="fieldErrors.currency?.length" class="mt-1 text-xs text-red-700 font-semibold">{{ fieldErrors.currency[0] }}</div>
                  </div>

                  <div class="sm:col-span-2">
                    <label class="mb-1 block text-xs font-bold text-slate-600 uppercase tracking-wide">Metode Cetak Struk</label>
                    <select
                      v-model="form.print_method"
                      class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold focus:border-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900/20 transition-all"
                      :class="fieldErrors.print_method ? 'border-red-300 bg-red-50/50' : ''"
                    >
                      <option value="browser">Browser (Standar / Kiosk)</option>
                      <option value="rawbt">Android RawBT App (PWA Builder)</option>
                    </select>
                    <div v-if="fieldErrors.print_method?.length" class="mt-1 text-xs text-red-700 font-semibold">{{ fieldErrors.print_method[0] }}</div>
                  </div>

                  <div class="sm:col-span-2">
                    <label class="mb-1 block text-xs font-bold text-slate-600 uppercase tracking-wide">Alamat</label>
                    <textarea
                      v-model="form.address"
                      rows="3"
                      class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold focus:border-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900/20 transition-all"
                      :class="fieldErrors.address ? 'border-red-300 bg-red-50/50' : ''"
                      placeholder="Alamat lengkap cabang toko..."
                    ></textarea>
                    <div v-if="fieldErrors.address?.length" class="mt-1 text-xs text-red-700 font-semibold">{{ fieldErrors.address[0] }}</div>
                  </div>

                  <div class="sm:col-span-2">
                    <label class="mb-1 block text-xs font-bold text-slate-600 uppercase tracking-wide">Footer Struk POS</label>
                    <textarea
                      v-model="form.receipt_footer"
                      rows="2"
                      class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold focus:border-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900/20 transition-all"
                      :class="fieldErrors.receipt_footer ? 'border-red-300 bg-red-50/50' : ''"
                      placeholder="Teks footer di bagian bawah struk, misal: Terima kasih atas kunjungan Anda!"
                    ></textarea>
                    <div v-if="fieldErrors.receipt_footer?.length" class="mt-1 text-xs text-red-700 font-semibold">{{ fieldErrors.receipt_footer[0] }}</div>
                  </div>
                </div>

                <!-- Form Action -->
                <div v-if="!loading" class="flex justify-end pt-2">
                  <button
                    class="rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-60 transition-colors shadow-sm"
                    type="button"
                    :disabled="saving || !form.name.trim() || !form.currency.trim()"
                    @click="save"
                  >
                    {{ saving ? 'Menyimpan...' : 'Simpan Pengaturan' }}
                  </button>
                </div>
              </div>

              <!-- Licensing Card Info -->
              <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm space-y-4 self-start">
                <div>
                  <h3 class="font-extrabold text-slate-900 text-sm">Status Lisensi</h3>
                  <p class="text-[11px] text-slate-500 font-medium">Informasi masa aktif lisensi cabang</p>
                </div>

                <div class="space-y-3 text-xs text-slate-600 font-medium">
                  <div class="flex items-center justify-between border-b border-slate-100 py-2.5">
                    <span>Status Cabang</span>
                    <span
                      class="inline-flex rounded-full px-2.5 py-0.5 text-[10px] font-extrabold uppercase tracking-wide border"
                      :class="store?.is_license_activated ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-50 text-slate-600 border-slate-200'"
                    >
                      {{ store?.is_license_activated ? 'Aktif' : 'Non-aktif' }}
                    </span>
                  </div>
                  <div class="flex items-center justify-between border-b border-slate-100 py-2.5">
                    <span>Tanggal Aktivasi</span>
                    <span class="text-slate-900 font-semibold font-mono">{{ store?.license_activated_at ? new Date(store.license_activated_at).toLocaleDateString('id-ID') : '-' }}</span>
                  </div>
                </div>

                <!-- License activation button only visible to tenant Owner roles -->
                <button
                  v-if="auth.isOwner"
                  class="w-full rounded-xl px-4 py-2.5 text-xs font-bold text-white transition-all shadow-sm"
                  :class="store?.is_license_activated ? 'bg-slate-100 text-slate-400 cursor-default border border-slate-200 shadow-none' : 'bg-emerald-600 hover:bg-emerald-700'"
                  type="button"
                  :disabled="activating || !!store?.is_license_activated"
                  @click="activateLicense()"
                >
                  {{ store?.is_license_activated ? 'Lisensi Toko Aktif' : activating ? 'Mengaktifkan...' : 'Aktifkan Lisensi Toko' }}
                </button>
                <div v-else class="text-[10px] text-slate-400 font-semibold bg-slate-50 p-2 rounded-lg text-center leading-normal">
                  Hanya pemilik akun (Owner) yang dapat melakukan aktivasi lisensi.
                </div>
              </div>
            </div>

            <!-- Case 2: Consolidation mode (active store_id is null, owner can see all stores and edit them directly) -->
            <div v-else-if="auth.isOwner" class="space-y-4">
              <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm space-y-4">
                <div class="border-b border-slate-100 pb-3 flex items-center justify-between">
                  <div>
                    <h3 class="font-extrabold text-slate-900 text-sm">Pengaturan Semua Cabang Toko</h3>
                    <p class="text-[11px] text-slate-500 font-medium">Ubah pengaturan masing-masing cabang secara langsung tanpa berpindah toko.</p>
                  </div>
                  <button
                    class="rounded-xl border border-slate-200 px-3.5 py-1.5 text-xs font-bold text-slate-700 bg-slate-50 hover:bg-slate-100 disabled:opacity-50 transition-colors"
                    type="button"
                    :disabled="loadingTenant"
                    @click="fetchTenantStores"
                  >
                    Refresh
                  </button>
                </div>

                <!-- Loading Indicator -->
                <div v-if="loadingTenant" class="py-12 text-center text-slate-400 text-xs font-medium">
                  <div class="h-6 w-6 animate-spin rounded-full border-2 border-slate-900 border-t-transparent mx-auto mb-2"></div>
                  Memuat cabang toko...
                </div>

                <!-- Stores Grid list -->
                <div v-else class="grid gap-3 sm:grid-cols-2">
                  <div
                    v-for="s in tenantStores"
                    :key="s.id"
                    class="border border-slate-200 rounded-2xl p-4 flex flex-col justify-between gap-3 shadow-sm bg-slate-50/50 hover:border-slate-300 transition-colors"
                  >
                    <div>
                      <div class="flex items-start justify-between gap-2">
                        <span class="font-extrabold text-slate-950 text-sm leading-snug">{{ s.name }}</span>
                        <span
                          class="inline-flex rounded-full px-2 py-0.5 text-[9px] font-extrabold uppercase border"
                          :class="s.is_license_activated ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-100 text-slate-600 border-slate-200'"
                        >
                          {{ s.is_license_activated ? 'Lisensi Aktif' : 'Lisensi Mati' }}
                        </span>
                      </div>
                      <div class="text-[11px] text-slate-500 mt-2 space-y-1 font-medium">
                        <p v-if="s.phone">📞 Telp: <span class="font-semibold text-slate-800 font-mono">{{ s.phone }}</span></p>
                        <p class="line-clamp-2">📍 Alamat: <span class="font-semibold text-slate-800">{{ s.address || '-' }}</span></p>
                        <p>💰 Mata Uang: <span class="font-semibold text-slate-800 font-mono">{{ s.currency || 'IDR' }}</span></p>
                        <p>🖨️ Printer: <span class="font-semibold text-slate-800">{{ s.print_method === 'rawbt' ? 'RawBT' : 'Browser' }}</span></p>
                      </div>
                    </div>

                    <div class="border-t border-slate-200/50 pt-3">
                      <button
                        type="button"
                        class="w-full text-center py-1.5 rounded-xl border border-slate-200 bg-white text-xs font-bold text-slate-700 hover:bg-slate-50 transition-all shadow-sm flex items-center justify-center gap-1.5"
                        @click="openEditStore(s)"
                      >
                        ⚙️ Edit Pengaturan
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- TAB 2: PERUSAHAAN & CABANG (Owner only) -->
          <div v-if="activeTab === 'tenant' && auth.isOwner" class="space-y-4">
            
            <!-- Limit & Subscription Notice Banner -->
            <div class="bg-slate-900 text-white rounded-2xl p-5 shadow-sm space-y-3 relative overflow-hidden">
              <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="space-y-0.5">
                  <h3 class="font-black text-sm">Status Lisensi Cabang Toko</h3>
                  <p class="text-[11px] text-slate-300">Dikelola berdasarkan slot aktif pada menu tagihan.</p>
                </div>
                <span class="text-xs bg-slate-800 text-white font-mono font-bold px-3 py-1 rounded-full border border-slate-700">
                  Terpakai: {{ tenantStores.filter(s => s.is_license_activated).length }} / {{ tenant?.max_stores || 1 }} Slot Cabang
                </span>
              </div>
              <p class="text-[11px] text-slate-400 leading-relaxed">
                Setiap cabang toko membutuhkan 1 slot lisensi aktif. Anda dapat menambah slot baru atau memperpanjang masa aktif slot di menu
                <router-link to="/billing" class="text-emerald-400 font-bold hover:underline font-bold">💳 Tagihan & Langganan</router-link>.
              </p>
            </div>

            <!-- Company Profile form -->
            <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm space-y-4">
              <div>
                <h3 class="font-extrabold text-slate-900 text-sm">Profil Perusahaan (Tenant)</h3>
                <p class="text-[11px] text-slate-500 font-medium">Ubah nama perusahaan/grup usaha Anda</p>
              </div>

              <div class="flex gap-2">
                <input
                  v-model="tenantForm.name"
                  class="flex-1 rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold focus:border-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900/20 transition-all"
                  placeholder="Nama Perusahaan / Persekutuan"
                />
                <button
                  type="button"
                  class="rounded-xl bg-slate-900 px-5 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-60 transition-colors shadow-sm"
                  :disabled="savingTenant || !tenantForm.name.trim()"
                  @click="saveTenant"
                >
                  {{ savingTenant ? 'Menyimpan...' : 'Simpan' }}
                </button>
              </div>
            </div>

            <!-- Stores Branch management -->
            <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm space-y-4">
              <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <div>
                  <h3 class="font-extrabold text-slate-900 text-sm">Kelola Cabang Toko</h3>
                  <p class="text-[11px] text-slate-500 font-medium">Daftar outlet/toko multi-cabang milik perusahaan Anda</p>
                </div>
                <button
                  type="button"
                  class="rounded-xl bg-slate-900 px-4 py-2 text-xs font-bold text-white hover:bg-slate-800 shadow-sm transition-all flex items-center gap-1 disabled:opacity-40"
                  :disabled="tenantStores.length >= (tenant?.max_stores || 1)"
                  @click="showAddStoreModal = true"
                >
                  <span>+</span> Tambah Toko
                </button>
              </div>

              <!-- Loading Indicator -->
              <div v-if="loadingTenant" class="py-12 text-center text-slate-400 text-xs font-medium">
                Memuat cabang toko...
              </div>

              <!-- Stores Grid list -->
              <div v-else class="grid gap-3 sm:grid-cols-2">
                <div
                  v-for="s in tenantStores"
                  :key="s.id"
                  class="border rounded-2xl p-4 flex flex-col justify-between gap-3 shadow-sm bg-slate-50/50"
                  :class="auth.user?.store_id === s.id ? 'border-slate-950 bg-white ring-2 ring-slate-900/5' : 'border-slate-200'"
                >
                  <div>
                    <div class="flex items-start justify-between gap-2">
                      <span class="font-extrabold text-slate-950 text-sm leading-snug">{{ s.name }}</span>
                      <span
                        class="inline-flex rounded-full px-2 py-0.5 text-[9px] font-extrabold uppercase border"
                        :class="s.is_license_activated ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-100 text-slate-600 border-slate-200'"
                      >
                        {{ s.is_license_activated ? 'Lisensi Aktif' : 'Lisensi Mati' }}
                      </span>
                    </div>
                    <div class="text-[11px] text-slate-500 mt-2 space-y-1">
                      <p v-if="s.phone">📞 Telp: {{ s.phone }}</p>
                      <p class="line-clamp-2">📍 {{ s.address || 'Alamat belum diatur' }}</p>
                      
                      <!-- License status for owner -->
                      <div class="border-t border-dashed border-slate-200 mt-2 pt-2 text-[10px] space-y-0.5 font-medium">
                        <p class="font-bold text-slate-700">Status Lisensi:</p>
                        <p v-if="s.is_license_activated" class="text-emerald-600 font-semibold flex items-center gap-1">
                          🟢 Aktif (Sejak {{ new Date(s.license_activated_at).toLocaleDateString('id-ID') }})
                        </p>
                        <p v-else class="text-slate-500 font-semibold flex items-center gap-1">
                          ⚪ Mati / Belum Diaktifkan
                        </p>
                      </div>
                    </div>
                  </div>

                  <div class="border-t border-slate-200/50 pt-3 space-y-2">
                    <span v-if="auth.user?.store_id === s.id" class="text-xs font-bold text-emerald-600 flex items-center gap-1.5 py-1 justify-center">
                      🏪 Toko Aktif Saat Ini
                    </span>
                    <div class="flex gap-2">
                      <button
                        type="button"
                        class="flex-1 text-center py-1.5 rounded-xl border border-slate-200 bg-white text-xs font-bold text-slate-700 hover:bg-slate-50 transition-all shadow-sm flex items-center justify-center gap-1.5"
                        @click="openEditStore(s)"
                      >
                        ⚙️ Edit Pengaturan
                      </button>
                      <button
                        v-if="!s.is_license_activated"
                        type="button"
                        class="flex-1 text-center py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-xs font-bold text-white transition-all shadow-sm flex items-center justify-center gap-1.5"
                        :disabled="activating"
                        @click="activateLicense(s.id)"
                      >
                        🔓 Aktifkan Lisensi
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- TAB 3: SUPERADMIN PLATFORM (Superadmin only) -->
          <div v-if="activeTab === 'superadmin' && auth.isSuperAdmin" class="space-y-4">
            <!-- SaaS Stats & Tenants -->
            <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm space-y-4">
              <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <div>
                  <h3 class="font-extrabold text-slate-900 text-sm">Kelola Tenant SaaS</h3>
                  <p class="text-[11px] text-slate-500 font-medium">Daftar perusahaan/tenant terdaftar pada sistem POS</p>
                </div>
                <button
                  type="button"
                  class="rounded-xl bg-slate-900 px-4 py-2 text-xs font-bold text-white hover:bg-slate-800 shadow-sm transition-all flex items-center gap-1"
                  @click="showAddTenantModal = true"
                >
                  <span>+</span> Tambah Tenant Baru
                </button>
              </div>

              <!-- Loading Indicator -->
              <div v-if="loadingSuper" class="py-12 text-center text-slate-400 text-xs font-medium">
                Memuat daftar tenant SaaS...
              </div>

              <!-- Tenants Table List -->
              <div v-else class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                  <thead>
                    <tr class="border-b border-slate-200 text-slate-500 font-bold uppercase tracking-wider">
                      <th class="py-3 px-3">ID</th>
                      <th class="py-3 px-3">Nama Perusahaan</th>
                      <th class="py-3 px-3">Toko</th>
                      <th class="py-3 px-3">User</th>
                      <th class="py-3 px-3">Terdaftar Sejak</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="t in tenants" :key="t.id" class="border-b border-slate-100 hover:bg-slate-50/50 font-semibold text-slate-800">
                      <td class="py-3 px-3 font-mono text-[10px] text-slate-400">#{{ t.id }}</td>
                      <td class="py-3 px-3 font-bold text-slate-950">{{ t.name }}</td>
                      <td class="py-3 px-3 font-mono">{{ t.stores_count }} toko</td>
                      <td class="py-3 px-3 font-mono">{{ t.users_count }} user</td>
                      <td class="py-3 px-3 text-slate-500">{{ new Date(t.created_at).toLocaleDateString('id-ID') }}</td>
                    </tr>
                    <tr v-if="!tenants.length">
                      <td colspan="5" class="py-10 text-center text-slate-400 font-semibold">Tidak ada tenant ditemukan.</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- Licensing Global Management -->
            <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm space-y-4">
              <div>
                <h3 class="font-extrabold text-slate-900 text-sm">Lisensi Cabang Global</h3>
                <p class="text-[11px] text-slate-500 font-medium">Nonaktifkan atau aktifkan lisensi toko manapun secara langsung</p>
              </div>

              <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                  <thead>
                    <tr class="border-b border-slate-200 text-slate-500 font-bold uppercase tracking-wider">
                      <th class="py-3 px-3">Nama Toko</th>
                      <th class="py-3 px-3">Tenant Perusahaan</th>
                      <th class="py-3 px-3">Mata Uang</th>
                      <th class="py-3 px-3">Lisensi</th>
                      <th class="py-3 px-3 text-right">Aksi</th>
                    </tr>
                  </thead>
                  <tbody>
                    <template v-for="t in tenants" :key="'ten-st-'+t.id">
                      <tr v-for="s in t.stores" :key="'st-'+s.id" class="border-b border-slate-100 hover:bg-slate-50/50 font-semibold text-slate-800">
                        <td class="py-3 px-3 font-bold text-slate-950">{{ s.name }}</td>
                        <td class="py-3 px-3 text-slate-500">{{ t.name }}</td>
                        <td class="py-3 px-3 font-mono text-slate-600">{{ s.currency }}</td>
                        <td class="py-3 px-3">
                          <span
                            class="inline-flex rounded-full px-2 py-0.5 text-[9px] font-extrabold uppercase border"
                            :class="s.is_license_activated ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-100 text-slate-600 border-slate-200'"
                          >
                            {{ s.is_license_activated ? 'Aktif' : 'Mati' }}
                          </span>
                        </td>
                        <td class="py-3 px-3 text-right">
                          <button
                            type="button"
                            class="px-3 py-1 rounded-xl text-[10px] font-bold border transition-colors shadow-sm"
                            :class="s.is_license_activated ? 'bg-red-50 text-red-700 border-red-200 hover:bg-red-100' : 'bg-emerald-50 text-emerald-700 border-emerald-200 hover:bg-emerald-100'"
                            @click="onToggleGlobalLicense(s.id)"
                          >
                            {{ s.is_license_activated ? 'Nonaktifkan' : 'Aktifkan' }}
                          </button>
                        </td>
                      </tr>
                    </template>
                    <tr v-if="!tenants.some(t => t.stores?.length)">
                      <td colspan="5" class="py-10 text-center text-slate-400 font-semibold">Tidak ada toko cabang yang terdaftar.</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- MODAL: ADD STORE BRANCH (Owner only) -->
    <Teleport to="body">
      <div v-if="showAddStoreModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showAddStoreModal = false">
        <div class="w-full max-w-md bg-white rounded-2xl shadow-xl p-5 space-y-4">
          <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <h3 class="font-extrabold text-slate-900 text-sm">Tambah Toko Cabang</h3>
            <button type="button" class="text-slate-400 hover:text-slate-600 text-sm font-bold" @click="showAddStoreModal = false">✕</button>
          </div>

          <div class="space-y-3">
            <div>
              <label class="mb-1 block text-xs font-bold text-slate-600 uppercase tracking-wide">Nama Cabang</label>
              <input
                v-model="newStoreForm.name"
                class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold focus:border-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900/20 transition-all"
                placeholder="Contoh: Nessa Bakery Cabang Depok"
              />
            </div>
            <div>
              <label class="mb-1 block text-xs font-bold text-slate-600 uppercase tracking-wide">Telepon</label>
              <input
                v-model="newStoreForm.phone"
                class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold focus:border-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900/20 transition-all font-mono"
                placeholder="0812345xxxxx"
              />
            </div>
            <div>
              <label class="mb-1 block text-xs font-bold text-slate-600 uppercase tracking-wide">Mata Uang</label>
              <input
                v-model="newStoreForm.currency"
                class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold focus:border-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900/20 transition-all font-mono"
                placeholder="IDR"
              />
            </div>
            <div>
              <label class="mb-1 block text-xs font-bold text-slate-600 uppercase tracking-wide">Alamat</label>
              <textarea
                v-model="newStoreForm.address"
                rows="2"
                class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold focus:border-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900/20 transition-all"
                placeholder="Alamat lengkap outlet..."
              ></textarea>
            </div>
          </div>

          <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
            <button
              type="button"
              class="px-4 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-600 hover:bg-slate-50"
              @click="showAddStoreModal = false"
            >
              Batal
            </button>
            <button
              type="button"
              class="px-5 py-2.5 rounded-xl bg-slate-900 text-xs font-bold text-white hover:bg-slate-800 disabled:opacity-50"
              :disabled="creatingStore || !newStoreForm.name.trim()"
              @click="createStoreBranch"
            >
              {{ creatingStore ? 'Menyimpan...' : 'Daftarkan Toko' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- MODAL: ADD TENANT (Superadmin only) -->
    <Teleport to="body">
      <div v-if="showAddTenantModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showAddTenantModal = false">
        <div class="w-full max-w-md bg-white rounded-2xl shadow-xl p-5 space-y-4">
          <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <h3 class="font-extrabold text-slate-900 text-sm">Daftarkan Tenant & Perusahaan Baru</h3>
            <button type="button" class="text-slate-400 hover:text-slate-600 text-sm font-bold" @click="showAddTenantModal = false">✕</button>
          </div>

          <div class="space-y-3">
            <div class="border-b border-slate-100 pb-2">
              <span class="text-xs font-bold text-slate-400 uppercase tracking-wide">1. Profil Tenant</span>
            </div>
            <div>
              <label class="mb-1 block text-xs font-bold text-slate-600 uppercase tracking-wide">Nama Perusahaan (Tenant)</label>
              <input
                v-model="newTenantForm.company_name"
                class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold focus:border-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900/20 transition-all"
                placeholder="Contoh: CV Nessa Abadi Sejahtera"
              />
            </div>
            
            <div class="border-b border-slate-100 pt-2 pb-2">
              <span class="text-xs font-bold text-slate-400 uppercase tracking-wide">2. Akun Pemilik (Owner)</span>
            </div>
            <div>
              <label class="mb-1 block text-xs font-bold text-slate-600 uppercase tracking-wide">Nama Owner</label>
              <input
                v-model="newTenantForm.owner_name"
                class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold focus:border-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900/20 transition-all"
                placeholder="Nama penanggung jawab"
              />
            </div>
            <div>
              <label class="mb-1 block text-xs font-bold text-slate-600 uppercase tracking-wide">Email Owner</label>
              <input
                v-model="newTenantForm.owner_email"
                type="email"
                class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold focus:border-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900/20 transition-all font-mono"
                placeholder="owner@email.com"
              />
            </div>
            <div>
              <label class="mb-1 block text-xs font-bold text-slate-600 uppercase tracking-wide">Password Owner</label>
              <input
                v-model="newTenantForm.owner_password"
                type="password"
                class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold focus:border-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900/20 transition-all"
                placeholder="Minimal 6 karakter"
              />
            </div>

            <div class="border-b border-slate-100 pt-2 pb-2">
              <span class="text-xs font-bold text-slate-400 uppercase tracking-wide">3. Toko Utama</span>
            </div>
            <div>
              <label class="mb-1 block text-xs font-bold text-slate-600 uppercase tracking-wide">Nama Toko Utama</label>
              <input
                v-model="newTenantForm.store_name"
                class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold focus:border-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900/20 transition-all"
                placeholder="Contoh: Nessa Bakery Cabang Ke-1"
              />
            </div>
          </div>

          <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
            <button
              type="button"
              class="px-4 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-600 hover:bg-slate-50"
              @click="showAddTenantModal = false"
            >
              Batal
            </button>
            <button
              type="button"
              class="px-5 py-2.5 rounded-xl bg-slate-900 text-xs font-bold text-white hover:bg-slate-800 disabled:opacity-50"
              :disabled="creatingTenant || !newTenantForm.company_name || !newTenantForm.owner_name || !newTenantForm.owner_email || !newTenantForm.owner_password || !newTenantForm.store_name"
              @click="createTenant"
            >
              {{ creatingTenant ? 'Mendaftarkan...' : 'Daftarkan Tenant' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- MODAL EDIT PENGATURAN TOKO (Owner consolidation mode) -->
    <Teleport to="body">
      <div v-if="editStoreModalOpen && editingStoreModel" class="fixed inset-0 z-50 grid place-items-center bg-slate-900/60 p-4 backdrop-blur-sm">
        <div class="w-full max-w-xl rounded-2xl bg-white p-5 shadow-xl space-y-4 border border-slate-100">
          <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <div>
              <h3 class="font-extrabold text-slate-900 text-sm">Edit Pengaturan Toko: {{ editingStoreModel.name }}</h3>
              <p class="text-[11px] text-slate-500 font-medium">Ubah profil dan konfigurasi cabang toko ini</p>
            </div>
            <button
              type="button"
              class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-50 hover:text-slate-700 transition-colors"
              @click="editStoreModalOpen = false"
            >
              ✕
            </button>
          </div>

          <div class="grid gap-3 sm:grid-cols-2">
            <div class="sm:col-span-2">
              <label class="mb-1 block text-xs font-bold text-slate-600 uppercase tracking-wide">Nama Toko</label>
              <input
                v-model="editForm.name"
                class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold focus:border-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900/20 transition-all"
                :class="fieldErrors.name ? 'border-red-300 bg-red-50/50' : ''"
                maxlength="120"
                placeholder="Contoh: Toko Cabang A"
              />
              <div v-if="fieldErrors.name?.length" class="mt-1 text-xs text-red-700 font-semibold">{{ fieldErrors.name[0] }}</div>
            </div>

            <div>
              <label class="mb-1 block text-xs font-bold text-slate-600 uppercase tracking-wide">Telepon</label>
              <input
                v-model="editForm.phone"
                class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold focus:border-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900/20 transition-all font-mono"
                :class="fieldErrors.phone ? 'border-red-300 bg-red-50/50' : ''"
                maxlength="32"
                placeholder="Contoh: 0812345678"
              />
              <div v-if="fieldErrors.phone?.length" class="mt-1 text-xs text-red-700 font-semibold">{{ fieldErrors.phone[0] }}</div>
            </div>

            <div>
              <label class="mb-1 block text-xs font-bold text-slate-600 uppercase tracking-wide">Mata Uang</label>
              <input
                v-model="editForm.currency"
                class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold focus:border-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900/20 transition-all font-mono"
                :class="fieldErrors.currency ? 'border-red-300 bg-red-50/50' : ''"
                maxlength="8"
                placeholder="IDR"
              />
              <div v-if="fieldErrors.currency?.length" class="mt-1 text-xs text-red-700 font-semibold">{{ fieldErrors.currency[0] }}</div>
            </div>

            <div class="sm:col-span-2">
              <label class="mb-1 block text-xs font-bold text-slate-600 uppercase tracking-wide">Metode Cetak Struk</label>
              <select
                v-model="editForm.print_method"
                class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold focus:border-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900/20 transition-all"
                :class="fieldErrors.print_method ? 'border-red-300 bg-red-50/50' : ''"
              >
                <option value="browser">Browser (Standar / Kiosk)</option>
                <option value="rawbt">Android RawBT App (PWA Builder)</option>
              </select>
              <div v-if="fieldErrors.print_method?.length" class="mt-1 text-xs text-red-700 font-semibold">{{ fieldErrors.print_method[0] }}</div>
            </div>

            <div class="sm:col-span-2">
              <label class="mb-1 block text-xs font-bold text-slate-600 uppercase tracking-wide">Alamat</label>
              <textarea
                v-model="editForm.address"
                rows="3"
                class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold focus:border-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900/20 transition-all"
                :class="fieldErrors.address ? 'border-red-300 bg-red-50/50' : ''"
                placeholder="Alamat lengkap cabang toko..."
              ></textarea>
              <div v-if="fieldErrors.address?.length" class="mt-1 text-xs text-red-700 font-semibold">{{ fieldErrors.address[0] }}</div>
            </div>

            <div class="sm:col-span-2">
              <label class="mb-1 block text-xs font-bold text-slate-600 uppercase tracking-wide">Footer Struk POS</label>
              <textarea
                v-model="editForm.receipt_footer"
                rows="2"
                class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold focus:border-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900/20 transition-all"
                :class="fieldErrors.receipt_footer ? 'border-red-300 bg-red-50/50' : ''"
                placeholder="Teks footer di bagian bawah struk, misal: Terima kasih atas kunjungan Anda!"
              ></textarea>
              <div v-if="fieldErrors.receipt_footer?.length" class="mt-1 text-xs text-red-700 font-semibold">{{ fieldErrors.receipt_footer[0] }}</div>
            </div>
          </div>

          <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
            <button
              type="button"
              class="px-4 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-600 hover:bg-slate-50 transition-colors"
              @click="editStoreModalOpen = false"
            >
              Batal
            </button>
            <button
              type="button"
              class="px-5 py-2.5 rounded-xl bg-slate-900 text-xs font-bold text-white hover:bg-slate-800 disabled:opacity-50 transition-colors shadow-sm"
              :disabled="saving || !editForm.name.trim() || !editForm.currency.trim()"
              @click="saveStoreEdit"
            >
              {{ saving ? 'Menyimpan...' : 'Simpan' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </AppShell>
</template>

<style scoped>
.scrollbar-none::-webkit-scrollbar {
  display: none;
}
.scrollbar-none {
  -ms-overflow-style: none;
  scrollbar-width: none;
}
</style>
