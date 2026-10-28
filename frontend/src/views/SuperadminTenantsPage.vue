<script setup lang="ts">
import { onMounted, ref } from 'vue'
import AppShell from '../components/AppShell.vue'
import api from '../services/api'

// Tenant list & Plans
const tenants = ref<any[]>([])
const plans = ref<any[]>([])
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
  plan: 'starter',
  billing_cycle: 'monthly',
})
const createLoading = ref(false)

// Modal Subscription Editing
const showEditModal = ref(false)
const selectedTenant = ref<any>(null)
const editForm = ref({
  plan: 'starter',
  billing_cycle: 'monthly',
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

const getPlanName = (planSlug: string) => {
  const p = plans.value.find((item) => item.slug === planSlug)
  return p ? p.name : (planSlug ? planSlug.toUpperCase() : 'Starter POS')
}

const fetchData = async () => {
  loading.value = true
  error.value = ''
  try {
    const [tenantsRes, plansRes] = await Promise.all([
      api.get('/superadmin/tenants'),
      api.get('/superadmin/plans'),
    ])

    tenants.value = tenantsRes.data ?? []
    plans.value = plansRes.data?.data ?? []

    if (detailTenant.value) {
      detailTenant.value = tenants.value.find((t: any) => t.id === detailTenant.value.id) || null
    }
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Gagal memuat data tenant.'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchData()
})

const openCreate = () => {
  createForm.value = {
    company_name: '',
    owner_name: '',
    owner_email: '',
    owner_password: '',
    store_name: '',
    plan: 'starter',
    billing_cycle: 'monthly',
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
    await fetchData()
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Gagal membuat tenant.'
  } finally {
    createLoading.value = false
  }
}

const openEdit = (tenant: any) => {
  selectedTenant.value = tenant
  editForm.value = {
    plan: tenant.plan || 'starter',
    billing_cycle: tenant.billing_cycle || 'monthly',
    subscription_status: tenant.subscription_status,
    trial_ends_at: tenant.trial_ends_at ? tenant.trial_ends_at.substring(0, 10) : '',
    subscription_ends_at: tenant.subscription_ends_at ? tenant.subscription_ends_at.substring(0, 10) : '',
    max_stores: tenant.max_stores || 1,
    max_users: tenant.max_users || 3,
  }
  showEditModal.value = true
}

// When plan dropdown changed in edit modal, auto-fill limits if user hasn't customized
const onPlanChangeInEdit = () => {
  const p = plans.value.find((item) => item.slug === editForm.value.plan)
  if (p) {
    editForm.value.max_stores = p.max_stores
    editForm.value.max_users = p.max_users
  }
}

const submitEdit = async () => {
  if (!selectedTenant.value) return

  editLoading.value = true
  error.value = ''
  success.value = ''

  try {
    await api.post(`/superadmin/tenants/${selectedTenant.value.id}/subscription`, {
      plan: editForm.value.plan,
      billing_cycle: editForm.value.billing_cycle,
      subscription_status: editForm.value.subscription_status,
      trial_ends_at: editForm.value.trial_ends_at ? editForm.value.trial_ends_at + ' 23:59:59' : null,
      subscription_ends_at: editForm.value.subscription_ends_at ? editForm.value.subscription_ends_at + ' 23:59:59' : null,
      max_stores: Number(editForm.value.max_stores),
      max_users: Number(editForm.value.max_users),
    })

    success.value = `Langganan tenant '${selectedTenant.value.name}' berhasil diperbarui.`
    showEditModal.value = false
    await fetchData()
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Gagal memperbarui langganan tenant.'
  } finally {
    editLoading.value = false
  }
}

const openDetail = (tenant: any) => {
  detailTenant.value = tenant
  showDetailModal.value = true
}

const toggleStoreLicense = async (store: any) => {
  toggleLicenseLoading.value = store.id
  error.value = ''
  success.value = ''
  try {
    const { data } = await api.post(`/superadmin/stores/${store.id}/toggle-license`)
    store.is_license_activated = data.store.is_license_activated
    success.value = `Lisensi cabang '${store.name}' berhasil diubah.`
    await fetchData()
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
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h2 class="text-2xl font-black tracking-tight text-slate-900">Kelola Tenant & Pelanggan SaaS</h2>
          <p class="text-sm text-slate-500">Monitor status paket langganan, masa aktif, lisensi cabang toko, dan pengguna.</p>
        </div>
        <button
          type="button"
          @click="openCreate"
          class="inline-flex items-center justify-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-xs font-bold text-white hover:bg-slate-800 transition-colors shadow-sm"
        >
          <span>+</span>
          <span>Tambah Tenant Baru</span>
        </button>
      </div>

      <!-- Messages -->
      <div v-if="error" class="bg-red-50 text-red-800 p-4 rounded-xl text-sm font-medium border border-red-200">
        {{ error }}
      </div>
      <div v-if="success" class="bg-emerald-50 text-emerald-800 p-4 rounded-xl text-sm font-medium border border-emerald-200">
        {{ success }}
      </div>

      <!-- Loading State -->
      <div v-if="loading" class="bg-white rounded-2xl border border-slate-100 p-12 flex justify-center items-center shadow-sm">
        <div class="h-8 w-8 animate-spin rounded-full border-4 border-slate-900 border-t-transparent"></div>
      </div>

      <!-- Empty State -->
      <div v-else-if="tenants.length === 0" class="bg-white rounded-2xl border border-slate-100 p-12 text-center shadow-sm">
        <p class="text-slate-500 text-sm font-medium">Belum ada tenant yang terdaftar dalam platform.</p>
      </div>

      <!-- Tenants Table -->
      <div v-else class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-left border-collapse text-xs">
            <thead>
              <tr class="border-b border-slate-100 bg-slate-50/75 text-slate-400 font-extrabold uppercase tracking-wider">
                <th class="py-3 px-4">Nama Perusahaan / Tenant</th>
                <th class="py-3 px-4">Paket & Siklus</th>
                <th class="py-3 px-4">Status & Masa Aktif</th>
                <th class="py-3 px-4">Cabang Toko</th>
                <th class="py-3 px-4">Pengguna</th>
                <th class="py-3 px-4 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 font-medium">
              <tr v-for="t in tenants" :key="t.id" class="hover:bg-slate-50/50 transition-colors">
                <!-- Tenant Name -->
                <td class="py-3.5 px-4">
                  <div class="font-extrabold text-slate-900 text-sm">{{ t.name }}</div>
                  <div class="text-[11px] text-slate-400">ID: #{{ t.id }} • {{ formatDate(t.created_at) }}</div>
                </td>

                <!-- Plan & Cycle -->
                <td class="py-3.5 px-4">
                  <div class="flex items-center gap-1.5 flex-wrap">
                    <span class="font-bold text-slate-800">{{ getPlanName(t.plan) }}</span>
                    <span
                      class="rounded-full px-2 py-0.5 text-[9px] font-black uppercase tracking-wider"
                      :class="t.billing_cycle === 'yearly' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700'"
                    >
                      {{ t.billing_cycle === 'yearly' ? 'Tahunan' : 'Bulanan' }}
                    </span>
                  </div>
                </td>

                <!-- Status & Expiry -->
                <td class="py-3.5 px-4 space-y-1">
                  <div>
                    <span
                      class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-wider"
                      :class="{
                        'bg-blue-100 text-blue-800': t.subscription_status === 'trial',
                        'bg-emerald-100 text-emerald-800': t.subscription_status === 'active',
                        'bg-rose-100 text-rose-800': t.subscription_status === 'expired',
                        'bg-slate-200 text-slate-700': t.subscription_status === 'cancelled',
                      }"
                    >
                      {{ t.subscription_status }}
                    </span>
                  </div>
                  <div class="text-[11px] text-slate-500">
                    <span v-if="t.subscription_status === 'trial'">
                      Trial s/d: <strong>{{ formatDate(t.trial_ends_at) }}</strong>
                    </span>
                    <span v-else-if="t.subscription_status === 'active'">
                      Berakhir: <strong>{{ formatDate(t.subscription_ends_at) }}</strong>
                    </span>
                    <span v-else class="text-rose-600 font-bold">
                      Kedaluwarsa
                    </span>
                  </div>
                </td>

                <!-- Cabang Toko -->
                <td class="py-3.5 px-4">
                  <div class="font-extrabold text-slate-900">
                    {{ t.stores_count || 0 }} / {{ t.max_stores }} Cabang
                  </div>
                  <div class="text-[10px] text-slate-400">
                    {{ t.stores?.filter((s: any) => s.is_license_activated).length || 0 }} Lisensi Aktif
                  </div>
                </td>

                <!-- Pengguna -->
                <td class="py-3.5 px-4">
                  <div class="font-extrabold text-slate-900">
                    {{ t.users_count || 0 }} / {{ t.max_users }} User
                  </div>
                </td>

                <!-- Actions -->
                <td class="py-3.5 px-4 text-right">
                  <div class="flex items-center justify-end gap-1.5">
                    <button
                      type="button"
                      @click="openDetail(t)"
                      class="rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-200 transition-colors"
                      title="Lihat rincian cabang dan user"
                    >
                      Detail
                    </button>
                    <button
                      type="button"
                      @click="openEdit(t)"
                      class="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-bold text-white hover:bg-slate-800 transition-colors shadow-sm"
                    >
                      Atur Paket
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- MODAL: DETAIL TENANT (STORES & USERS) -->
    <div v-if="showDetailModal" class="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl w-full max-w-2xl p-6 space-y-4 shadow-xl border border-slate-100 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-start pb-3 border-b border-slate-100">
          <div>
            <h3 class="text-lg font-black text-slate-900">Rincian Tenant: {{ detailTenant?.name }}</h3>
            <p class="text-xs text-slate-500 mt-0.5">
              Paket: <strong class="text-slate-800">{{ getPlanName(detailTenant?.plan) }}</strong> •
              Siklus: <strong class="text-slate-800">{{ detailTenant?.billing_cycle === 'yearly' ? 'Tahunan' : 'Bulanan' }}</strong> •
              Status: <span class="uppercase font-bold">{{ detailTenant?.subscription_status }}</span>
            </p>
          </div>
          <button type="button" @click="showDetailModal = false" class="text-slate-400 hover:text-slate-600 font-bold text-lg">✕</button>
        </div>

        <!-- Stores section -->
        <div class="space-y-3">
          <div class="flex items-center justify-between">
            <h4 class="text-xs font-extrabold uppercase tracking-wider text-slate-400">Daftar Cabang Toko</h4>
            <span class="text-xs font-bold text-slate-600">Total: {{ detailTenant?.stores?.length || 0 }} Cabang</span>
          </div>

          <div class="space-y-2">
            <div
              v-for="st in detailTenant?.stores"
              :key="st.id"
              class="border border-slate-100 bg-slate-50/60 rounded-xl p-3 flex flex-col sm:flex-row sm:items-center justify-between gap-3"
            >
              <div>
                <div class="flex items-center gap-2">
                  <span class="font-extrabold text-slate-900 text-sm">{{ st.name }}</span>
                  <span
                    class="rounded-full px-2 py-0.5 text-[9px] font-black uppercase tracking-wider"
                    :class="st.is_license_activated ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'"
                  >
                    {{ st.is_license_activated ? 'Lisensi Aktif' : 'Lisensi Nonaktif' }}
                  </span>
                </div>
                <div class="text-[11px] text-slate-500 mt-0.5">
                  ID: #{{ st.id }} • {{ st.users_count || 0 }} Pengguna terdaftar
                  <span v-if="st.users?.length"> ({{ countRoles(st.users) }})</span>
                </div>
              </div>

              <button
                type="button"
                @click="toggleStoreLicense(st)"
                :disabled="toggleLicenseLoading === st.id"
                class="rounded-lg px-3 py-1.5 text-xs font-bold transition-colors shrink-0"
                :class="st.is_license_activated ? 'bg-rose-50 text-rose-700 hover:bg-rose-100' : 'bg-emerald-50 text-emerald-800 hover:bg-emerald-100'"
              >
                {{ toggleLicenseLoading === st.id ? 'Memproses...' : (st.is_license_activated ? 'Nonaktifkan Lisensi' : 'Aktifkan Lisensi') }}
              </button>
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
      <div class="bg-white rounded-2xl w-full max-w-md p-6 space-y-4 shadow-xl border border-slate-100 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center">
          <h3 class="text-lg font-black text-slate-900">Tambah Tenant Baru</h3>
          <button type="button" @click="showCreateModal = false" class="text-slate-400 hover:text-slate-600 font-bold text-lg">✕</button>
        </div>
        <form @submit.prevent="submitCreate" class="space-y-4">
          <div class="space-y-1">
            <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Nama Perusahaan / Bisnis</label>
            <input
              v-model="createForm.company_name"
              type="text"
              required
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
              placeholder="Contoh: Nessa Group"
            />
          </div>
          <div class="space-y-1">
            <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Nama Toko Cabang Pertama</label>
            <input
              v-model="createForm.store_name"
              type="text"
              required
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
              placeholder="Contoh: Nessa Mart Kebayoran"
            />
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div class="space-y-1">
              <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Paket Langganan</label>
              <select
                v-model="createForm.plan"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
              >
                <option v-for="p in plans" :key="p.slug" :value="p.slug">
                  {{ p.name }}
                </option>
              </select>
            </div>
            <div class="space-y-1">
              <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Siklus</label>
              <select
                v-model="createForm.billing_cycle"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
              >
                <option value="monthly">Bulanan</option>
                <option value="yearly">Tahunan</option>
              </select>
            </div>
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
            <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Email Login Owner</label>
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
          <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
            <button
              type="button"
              @click="showCreateModal = false"
              class="rounded-xl border border-slate-200 px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50"
            >
              Batal
            </button>
            <button
              type="submit"
              :disabled="createLoading"
              class="rounded-xl bg-slate-900 px-5 py-2 text-xs font-bold text-white hover:bg-slate-800 disabled:opacity-50"
            >
              {{ createLoading ? 'Menyimpan...' : 'Simpan Tenant' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- MODAL: EDIT SUBSCRIPTION -->
    <div v-if="showEditModal" class="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl w-full max-w-md p-6 space-y-4 shadow-xl border border-slate-100 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center">
          <div>
            <h3 class="text-lg font-black text-slate-900">Atur Paket & Langganan</h3>
            <p class="text-xs text-slate-500 mt-0.5">Tenant: {{ selectedTenant?.name }}</p>
          </div>
          <button type="button" @click="showEditModal = false" class="text-slate-400 hover:text-slate-600 font-bold text-lg">✕</button>
        </div>

        <form @submit.prevent="submitEdit" class="space-y-4">
          <div class="grid grid-cols-2 gap-3">
            <div class="space-y-1">
              <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Pilih Paket</label>
              <select
                v-model="editForm.plan"
                @change="onPlanChangeInEdit"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
              >
                <option v-for="p in plans" :key="p.slug" :value="p.slug">
                  {{ p.name }}
                </option>
              </select>
            </div>
            <div class="space-y-1">
              <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Siklus Tagihan</label>
              <select
                v-model="editForm.billing_cycle"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
              >
                <option value="monthly">Bulanan</option>
                <option value="yearly">Tahunan</option>
              </select>
            </div>
          </div>

          <div class="space-y-1">
            <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Status Langganan</label>
            <select
              v-model="editForm.subscription_status"
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
            >
              <option value="trial">Trial (Uji Coba)</option>
              <option value="active">Active (Aktif Berlangganan)</option>
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

          <div class="grid grid-cols-2 gap-3">
            <div class="space-y-1">
              <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Batas Cabang Toko</label>
              <input
                v-model.number="editForm.max_stores"
                type="number"
                min="1"
                required
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
              />
            </div>
            <div class="space-y-1">
              <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-500">Batas Pengguna</label>
              <input
                v-model.number="editForm.max_users"
                type="number"
                min="1"
                required
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
              />
            </div>
          </div>

          <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
            <button
              type="button"
              @click="showEditModal = false"
              class="rounded-xl border border-slate-200 px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50"
            >
              Batal
            </button>
            <button
              type="submit"
              :disabled="editLoading"
              class="rounded-xl bg-slate-900 px-5 py-2 text-xs font-bold text-white hover:bg-slate-800 disabled:opacity-50"
            >
              {{ editLoading ? 'Menyimpan...' : 'Simpan Perubahan' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </AppShell>
</template>