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

const formatDate = (d: string | null) => {
  if (!d) return '-'
  return new Date(d).toLocaleDateString('id-ID', { year: 'numeric', month: 'short', day: 'numeric' })
}

const fetchTenants = async () => {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/superadmin/tenants')
    tenants.value = data ?? []
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
</script>

<template>
  <AppShell>
    <div class="space-y-6">
      <!-- Title & Actions -->
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 class="text-2xl font-black tracking-tight text-slate-900">Kelola Tenant</h2>
          <p class="text-sm text-slate-500">Daftar perusahaan ritel pengguna Nessa POS beserta lisensi mereka.</p>
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
      <div v-if="error" class="bg-red-50 text-red-800 p-3 rounded-lg text-sm font-medium">
        {{ error }}
      </div>
      <div v-if="success" class="bg-emerald-50 text-emerald-800 p-3 rounded-lg text-sm font-medium">
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
                <th class="px-6 py-4">Limit Toko</th>
                <th class="px-6 py-4">Limit User</th>
                <th class="px-6 py-4 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="t in tenants" :key="t.id" class="hover:bg-slate-50/50 transition-colors">
                <td class="px-6 py-4 font-bold text-slate-900">
                  {{ t.name }}
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
                <td class="px-6 py-4 text-slate-700 font-semibold">
                  {{ t.stores_count }} / {{ t.max_stores }}
                </td>
                <td class="px-6 py-4 text-slate-700 font-semibold">
                  {{ t.users_count }} / {{ t.max_users }}
                </td>
                <td class="px-6 py-4 text-right">
                  <button
                    type="button"
                    @click="openEdit(t)"
                    class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-50 transition-colors"
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

    <!-- MODAL: CREATE TENANT -->
    <div v-if="showCreateModal" class="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl w-full max-w-md p-6 space-y-4 shadow-xl border border-slate-100 animate-in fade-in zoom-in-95 duration-150">
        <div class="flex justify-between items-center">
          <h3 class="text-lg font-black text-slate-900">Tambah Tenant Baru</h3>
          <button type="button" @click="showCreateModal = false" class="text-slate-400 hover:text-slate-600">✕</button>
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
          <button type="button" @click="showEditModal = false" class="text-slate-400 hover:text-slate-600">✕</button>
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
