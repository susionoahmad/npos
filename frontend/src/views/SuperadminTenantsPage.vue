<script setup lang="ts">
import { onMounted, ref } from 'vue'
import AppShell from '../components/AppShell.vue'
import api from '../services/api'

// Tenant list
const tenants = ref<any[]>([])
const loading = ref(true)
const error = ref('')
const success = ref('')

// Modal Tenant Creation
const showCreateModal = ref(false)
const createForm = ref({
  company_name: '',
  owner_name: '',
  owner_email: '',
  owner_password: '',
  store_name: '',
})
const createLoading = ref(false)

// Modal Subscription Editing
const showEditModal = ref(false)
const selectedTenant = ref<any>(null)
const editForm = ref({
  subscription_status: 'trial',
  trial_ends_at: '',
  subscription_ends_at: '',
  max_stores: 1,
  max_users: 3,
})
const editLoading = ref(false)

// Modal Branches & Users Details
const showDetailModal = ref(false)
const detailTenant = ref<any>(null)
const toggleLicenseLoading = ref<number | null>(null)

const formatDate = (d: string | null) => {
  if (!d) return '-'
  return new Date(d).toLocaleDateString('id-ID', { year: 'numeric', month: 'short', day: 'numeric' })
}

const countRoles = (users: any[]) => {
  if (!users || !users.length) return 'Belum ada user'
  const counts: Record<string, number> = {}
  users.forEach((u: any) => {
    counts[u.role] = (counts[u.role] || 0) + 1
  })
  return Object.entries(counts)
    .map(([role, count]) => `${count} ${role}`)
    .join(', ')
}

const fetchTenants = async () => {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/superadmin/tenants')
    tenants.value = data ?? []
    if (detailTenant.value) {
      detailTenant.value = tenants.value.find((t: any) => t.id === detailTenant.value.id) || null
    }
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Gagal memuat daftar tenant.'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchTenants()
})

const openCreate = () => {
  createForm.value = {
    company_name: '',
    owner_name: '',
    owner_email: '',
    owner_password: '',
    store_name: '',
  }
  showCreateModal.value = true
}

const submitCreate = async () => {
  createLoading.value = true
  error.value = ''
  success.value = ''
  try {
    await api.post('/superadmin/tenants', createForm.value)
    success.value = 'Tenant baru berhasil dibuat.'
    showCreateModal.value = false
    await fetchTenants()
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Gagal membuat tenant.'
  } finally {
    createLoading.value = false
  }
}

const openEdit = (tenant: any) => {
  selectedTenant.value = tenant
  editForm.value = {
    subscription_status: tenant.subscription_status,
    trial_ends_at: tenant.trial_ends_at ? tenant.trial_ends_at.split(' ')[0] : '',
    subscription_ends_at: tenant.subscription_ends_at ? tenant.subscription_ends_at.split(' ')[0] : '',
    max_stores: tenant.max_stores || 1,
    max_users: tenant.max_users || 3,
  }
  showEditModal.value = true
}

const submitEdit = async () => {
  if (!selectedTenant.value) return
  editLoading.value = true
  error.value = ''
  success.value = ''
  try {
    await api.post(`/superadmin/tenants/${selectedTenant.value.id}/subscription`, editForm.value)
    success.value = 'Data berlangganan tenant berhasil diperbarui.'
    showEditModal.value = false
    await fetchTenants()
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Gagal memperbarui data berlangganan.'
  } finally {
    editLoading.value = false
  }
}

const openBranchesDetail = (tenant: any) => {
  detailTenant.value = tenant
  showDetailModal.value = true
}

const toggleStoreLicense = async (store: any) => {
  toggleLicenseLoading.value = store.id
  error.value = ''
  success.value = ''
  try {
    const res = await api.post(`/superadmin/stores/${store.id}/toggle-license`)
    success.value = res.data?.message || 'Lisensi cabang berhasil diubah.'
    await fetchTenants()
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Gagal mengubah status lisensi cabang.'
  } finally {
    toggleLicenseLoading.value = null
  }
}
</script>

<template>
  <AppShell>
    <div class="space-y-6">
      <!-- Title & Actions -->
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 class="text-2xl font-black tracking-tight text-slate-900">Kelola Tenant</h2>
          <p class="text-sm text-slate-500">Daftar perusahaan ritel pengguna Nessa POS beserta lisensi cabang dan pengguna mereka.</p>
        </div>
        <button
          type="button"
          @click="openCreate"
          class="rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800 transition-colors shadow-sm"
        >
          ➕ Tambah Tenant
        </button>
      </div>

      <!-- Messages -->
      <div v-if="error" class="bg-red-50 text-red-800 p-3 rounded-lg text-sm font-medium border border-red-200">
        {{ error }}
      </div>
      <div v-if="success" class="bg-emerald-50 text-emerald-800 p-3 rounded-lg text-sm font-medium border border-emerald-200">
        {{ success }}
      </div>

      <!-- Tenant Table -->
      <div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
        <div v-if="loading" class="flex justify-center items-center py-20">
          <div class="h-8 w-8 animate-spin rounded-full border-4 border-slate-900 border-t-transparent"></div>
        </div>
        <div v-else-if="!tenants.length" class="text-center py-12 text-slate-400 text-sm">
          Belum ada tenant terdaftar.
        </div>
        <div v-else class="overflow-x-auto">
          <table class="w-full border-collapse text-left text-sm">
            <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-500 border-b border-slate-100">
              <tr>
                <th class="px-6 py-4">Nama Perusahaan</th>
                <th class="px-6 py-4">Status</th>
                <th class="px-6 py-4">Selesai Trial</th>
                <th class="px-6 py-4">Selesai Langganan</th>
                <th class="px-6 py-4 min-w-[280px]">Cabang & User per Cabang</th>
                <th class="px-6 py-4">Total User</th>
                <th class="px-6 py-4 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="t in tenants" :key="t.id" class="hover:bg-slate-50/50 transition-colors">
                <td class="px-6 py-4 font-bold text-slate-900">
                  <div>{{ t.name }}</div>
                  <div class="text-[11px] text-slate-400 font-mono mt-0.5">ID: #{{ t.id }}</div>
                </td>
                <td class="px-6 py-4">
                  <span
                    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-bold uppercase tracking-wider"
                    :class="{
                      'bg-emerald-50 text-emerald-700 border border-emerald-100': t.subscription_status === 'active',
                      'bg-blue-50 text-blue-700 border border-blue-100': t.subscription_status === 'trial',
                      'bg-rose-50 text-rose-700 border border-rose-100': t.subscription_status === 'expired',
                      'bg-slate-100 text-slate-600': t.subscription_status === 'cancelled',
                    }"
                  >
                    {{ t.subscription_status }}
                  </span>
                </td>
                <td class="px-6 py-4 font-mono text-xs text-slate-600">
                  {{ formatDate(t.trial_ends_at) }}
                </td>
                <td class="px-6 py-4 font-mono text-xs text-slate-600">
                  {{ formatDate(t.subscription_ends_at) }}
                </td>
                
                <!-- Cabang Toko & User Tiap Cabang -->
                <td class="px-6 py-4">
                  <div class="space-y-1.5">
                    <div class="text-xs font-black text-slate-900 flex items-center justify-between">
                      <span>🏪 {{ t.stores_count }} / {{ t.max_stores }} Cabang</span>
                      <span class="text-[10px] text-slate-500 font-semibold">
                        ({{ t.stores?.filter((s: any) => s.is_license_activated).length || 0 }} Lisensi Aktif)
                      </span>
                    </div>

                    <!-- Mini list cabang -->
                    <div class="flex flex-col gap-1 max-w-[280px]">
                      <div
                        v-for="s in t.stores"
                        :key="s.id"
                        class="text-[11px] rounded-lg px-2.5 py-1.5 border flex items-center justify-between gap-1 transition-colors"
                        :class="s.is_license_activated ? 'bg-slate-50 border-slate-200' : 'bg-rose-50/60 border-rose-200'"
                      >
                        <div class="truncate font-semibold text-slate-800 flex items-center gap-1.5" :title="s.name">
                          <span class="h-1.5 w-1.5 rounded-full shrink-0" :class="s.is_license_activated ? 'bg-emerald-500' : 'bg-rose-400'"></span>
                          <span class="truncate">{{ s.name }}</span>
                        </div>
                        <div class="flex items-center gap-1 shrink-0">
                          <span
                            class="text-[9px] font-bold px-1.5 py-0.5 rounded border"
                            :class="s.is_license_activated ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200'"
                          >
                            {{ s.is_license_activated ? 'Aktif' : 'Mati' }}
                          </span>
                          <span class="text-[10px] font-bold text-slate-700 bg-white border border-slate-200 px-1.5 py-0.5 rounded">
                            👥 {{ s.users_count ?? (s.users?.length || 0) }} user
                          </span>
                        </div>
                      </div>
                      <div v-if="!t.stores?.length" class="text-[11px] text-slate-400 italic">
                        Belum ada cabang toko
                      </div>
                    </div>
                  </div>
                </td>

                <!-- Total User -->
                <td class="px-6 py-4">
                  <div class="space-y-1">
                    <div class="text-xs font-black text-slate-900">
                      👤 {{ t.users_count }} / {{ t.max_users }} User
                    </div>
                    <div class="text-[10px] text-slate-500 font-medium">
                      {{ countRoles(t.users) }}
                    </div>
                  </div>
                </td>

                <!-- Aksi -->
                <td class="px-6 py-4 text-right space-x-1.5 whitespace-nowrap">
                  <button
                    type="button"
                    @click="openBranchesDetail(t)"
                    class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-indigo-600 hover:bg-indigo-50 hover:border-indigo-300 transition-colors shadow-sm"
                  >
                    🔍 Detail Cabang ({{ t.stores?.length || 0 }})
                  </button>
                  <button
                    type="button"
                    @click="openEdit(t)"
                    class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-50 transition-colors shadow-sm"
                  >
                    ⚙️ Atur Langganan
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- MODAL: DETAIL CABANG & USER (KOMPREHENSIF) -->
    <div v-if="showDetailModal && detailTenant" class="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl w-full max-w-2xl p-6 space-y-5 shadow-2xl border border-slate-100 max-h-[90vh] overflow-y-auto animate-in fade-in zoom-in-95 duration-150">
        <!-- Header -->
        <div class="flex justify-between items-start border-b border-slate-100 pb-3">
          <div>
            <div class="flex items-center gap-2">
              <h3 class="text-lg font-black text-slate-900">{{ detailTenant.name }}</h3>
              <span
                class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider"
                :class="{
                  'bg-emerald-50 text-emerald-700 border border-emerald-100': detailTenant.subscription_status === 'active',
                  'bg-blue-50 text-blue-700 border border-blue-100': detailTenant.subscription_status === 'trial',
                  'bg-rose-50 text-rose-700 border border-rose-100': detailTenant.subscription_status === 'expired',
                  'bg-slate-100 text-slate-600': detailTenant.subscription_status === 'cancelled',
                }"
              >
                {{ detailTenant.subscription_status }}
              </span>
            </div>
            <p class="text-xs text-slate-500 mt-0.5">Rincian cabang toko, status lisensi, dan penempatan staf pengguna.</p>
          </div>
          <button type="button" @click="showDetailModal = false" class="text-slate-400 hover:text-slate-600 font-bold text-lg">✕</button>
        </div>

        <!-- Ringkasan Kuota Tenant -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
          <div class="p-3 rounded-xl bg-slate-50 border border-slate-200/80">
            <div class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Total Cabang</div>
            <div class="text-base font-black text-slate-900 mt-0.5">{{ detailTenant.stores_count }} / {{ detailTenant.max_stores }}</div>
          </div>
          <div class="p-3 rounded-xl bg-slate-50 border border-slate-200/80">
            <div class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Total Pengguna</div>
            <div class="text-base font-black text-slate-900 mt-0.5">{{ detailTenant.users_count }} / {{ detailTenant.max_users }}</div>
          </div>
          <div class="p-3 rounded-xl bg-slate-50 border border-slate-200/80">
            <div class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Trial Berakhir</div>
            <div class="text-xs font-bold font-mono text-slate-800 mt-1">{{ formatDate(detailTenant.trial_ends_at) }}</div>
          </div>
          <div class="p-3 rounded-xl bg-slate-50 border border-slate-200/80">
            <div class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Langganan Selesai</div>
            <div class="text-xs font-bold font-mono text-slate-800 mt-1">{{ formatDate(detailTenant.subscription_ends_at) }}</div>
          </div>
        </div>

        <!-- Daftar Cabang & Penggunanya -->
        <div class="space-y-4">
          <h4 class="text-xs font-extrabold uppercase tracking-wider text-slate-500">
            Daftar Cabang Toko ({{ detailTenant.stores?.length || 0 }})
          </h4>

          <div v-if="!detailTenant.stores?.length" class="p-6 text-center text-slate-400 text-xs bg-slate-50 rounded-xl border border-dashed">
            Belum ada cabang toko yang dibuat untuk tenant ini.
          </div>

          <div
            v-for="store in detailTenant.stores"
            :key="store.id"
            class="rounded-xl border border-slate-200 p-4 space-y-3 bg-white shadow-sm"
          >
            <!-- Store Header -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-slate-100 pb-3">
              <div>
                <div class="flex items-center gap-2">
                  <span class="text-base font-black text-slate-900">🏪 {{ store.name }}</span>
                  <span
                    class="inline-flex rounded-full px-2 py-0.5 text-[9px] font-extrabold uppercase border"
                    :class="store.is_license_activated ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200'"
                  >
                    Lisensi: {{ store.is_license_activated ? 'Aktif' : 'Non-aktif' }}
                  </span>
                </div>
                <div class="text-[11px] text-slate-500 mt-0.5">
                  {{ store.address ? '📍 ' + store.address : 'Alamat belum diatur' }}
                  <span v-if="store.phone"> • 📞 {{ store.phone }}</span>
                </div>
              </div>

              <!-- Action: Toggle License Superadmin -->
              <div class="flex items-center gap-2">
                <button
                  type="button"
                  :disabled="toggleLicenseLoading === store.id"
                  @click="toggleStoreLicense(store)"
                  class="rounded-lg px-2.5 py-1 text-[11px] font-bold border transition-colors shadow-sm disabled:opacity-50"
                  :class="store.is_license_activated
                    ? 'bg-rose-50 text-rose-700 border-rose-200 hover:bg-rose-100'
                    : 'bg-emerald-50 text-emerald-700 border-emerald-200 hover:bg-emerald-100'"
                >
                  {{ toggleLicenseLoading === store.id ? 'Memproses...' : store.is_license_activated ? 'Nonaktifkan Lisensi' : 'Aktifkan Lisensi' }}
                </button>
              </div>
            </div>

            <!-- List of Users assigned to this Store -->
            <div>
              <div class="text-[11px] font-bold uppercase text-slate-500 mb-2 flex items-center justify-between">
                <span>Staf Terdaftar di Cabang Ini ({{ store.users?.length || 0 }} Orang)</span>
              </div>

              <div v-if="!store.users || !store.users.length" class="text-[11px] text-slate-400 italic py-1">
                Belum ada staf kasir / admin yang ditugaskan di cabang ini.
              </div>

              <div v-else class="space-y-1.5">
                <div
                  v-for="user in store.users"
                  :key="user.id"
                  class="flex items-center justify-between p-2 rounded-lg bg-slate-50 border border-slate-200/80 text-xs"
                >
                  <div class="flex items-center gap-2 truncate">
                    <span class="font-bold text-slate-800">{{ user.name }}</span>
                    <span class="text-slate-400 font-mono text-[11px] truncate">({{ user.email }})</span>
                  </div>
                  <div class="flex items-center gap-1.5 shrink-0">
                    <span
                      class="px-2 py-0.5 rounded text-[10px] font-extrabold uppercase border"
                      :class="{
                        'bg-purple-50 text-purple-700 border-purple-200': user.role === 'owner',
                        'bg-blue-50 text-blue-700 border-blue-200': user.role === 'admin',
                        'bg-slate-100 text-slate-700 border-slate-200': user.role === 'cashier',
                      }"
                    >
                      {{ user.role }}
                    </span>
                    <span
                      class="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase"
                      :class="user.is_active ? 'text-emerald-700 bg-emerald-50' : 'text-slate-500 bg-slate-200'"
                    >
                      {{ user.is_active ? 'Aktif' : 'Non-aktif' }}
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Footer -->
        <div class="flex justify-end pt-3 border-t border-slate-100">
          <button
            type="button"
            @click="showDetailModal = false"
            class="rounded-xl border border-slate-200 bg-slate-100 px-5 py-2 text-xs font-bold text-slate-700 hover:bg-slate-200 transition-colors"
          >
            Tutup
          </button>
        </div>
      </div>
    </div>

    <!-- MODAL: CREATE TENANT -->
    <div v-if="showCreateModal" class="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl w-full max-w-md p-6 space-y-4 shadow-xl border border-slate-100 animate-in fade-in zoom-in-95 duration-150">
        <div class="flex justify-between items-center">
          <h3 class="text-lg font-black text-slate-900">Tambah Tenant Baru</h3>
          <button type="button" @click="showCreateModal = false" class="text-slate-400 hover:text-slate-600 font-bold">✕</button>
        </div>
        <form @submit.prevent="submitCreate" class="space-y-4">
          <div class="space-y-1">
            <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Nama Perusahaan</label>
            <input
              v-model="createForm.company_name"
              type="text"
              required
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
              placeholder="Contoh: Nessa Group"
            />
          </div>
          <div class="space-y-1">
            <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Nama Toko Pertama</label>
            <input
              v-model="createForm.store_name"
              type="text"
              required
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
              placeholder="Contoh: Nessa Mart Kebayoran"
            />
          </div>
          <div class="space-y-1">
            <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Nama Owner (Pemilik)</label>
            <input
              v-model="createForm.owner_name"
              type="text"
              required
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
              placeholder="Contoh: Ahmad Owner"
            />
          </div>
          <div class="space-y-1">
            <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Email Owner</label>
            <input
              v-model="createForm.owner_email"
              type="email"
              required
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
              placeholder="Contoh: ahmad@nessagroup.com"
            />
          </div>
          <div class="space-y-1">
            <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Password Login Owner</label>
            <input
              v-model="createForm.owner_password"
              type="password"
              required
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
              placeholder="Minimal 6 karakter"
            />
          </div>
          <div class="flex justify-end gap-2 pt-2 border-t">
            <button
              type="button"
              @click="showCreateModal = false"
              class="rounded-xl border px-4 py-2 text-sm font-semibold hover:bg-slate-50"
            >
              Batal
            </button>
            <button
              type="submit"
              :disabled="createLoading"
              class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-50"
            >
              {{ createLoading ? 'Menyimpan...' : 'Simpan' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- MODAL: EDIT SUBSCRIPTION -->
    <div v-if="showEditModal" class="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl w-full max-w-md p-6 space-y-4 shadow-xl border border-slate-100 animate-in fade-in zoom-in-95 duration-150">
        <div class="flex justify-between items-center">
          <div>
            <h3 class="text-lg font-black text-slate-900">Atur Langganan Tenant</h3>
            <p class="text-xs text-slate-500 mt-0.5">Tenant: {{ selectedTenant?.name }}</p>
          </div>
          <button type="button" @click="showEditModal = false" class="text-slate-400 hover:text-slate-600 font-bold">✕</button>
        </div>
        <form @submit.prevent="submitEdit" class="space-y-4">
          <div class="space-y-1">
            <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Status Langganan</label>
            <select
              v-model="editForm.subscription_status"
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
            >
              <option value="trial">Trial (Uji Coba)</option>
              <option value="active">Active (Aktif Langganan)</option>
              <option value="expired">Expired (Kedaluwarsa)</option>
              <option value="cancelled">Cancelled (Dibatalkan)</option>
            </select>
          </div>
          <div class="space-y-1">
            <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Masa Trial Berakhir</label>
            <input
              v-model="editForm.trial_ends_at"
              type="date"
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
            />
          </div>
          <div class="space-y-1">
            <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Masa Langganan Berakhir</label>
            <input
              v-model="editForm.subscription_ends_at"
              type="date"
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
            />
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div class="space-y-1">
              <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Limit Toko</label>
              <input
                v-model="editForm.max_stores"
                type="number"
                min="1"
                required
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
              />
            </div>
            <div class="space-y-1">
              <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Limit User</label>
              <input
                v-model="editForm.max_users"
                type="number"
                min="1"
                required
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
              />
            </div>
          </div>
          <div class="flex justify-end gap-2 pt-2 border-t">
            <button
              type="button"
              @click="showEditModal = false"
              class="rounded-xl border px-4 py-2 text-sm font-semibold hover:bg-slate-50"
            >
              Batal
            </button>
            <button
              type="submit"
              :disabled="editLoading"
              class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-50"
            >
              {{ editLoading ? 'Menyimpan...' : 'Simpan Perubahan' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </AppShell>
</template>