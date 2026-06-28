<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../services/api'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const stores = ref<any[]>([])
const isSidebarOpen = ref(false)
const activeSession = ref<any>(null)

onMounted(async () => {
  if (auth.user?.store_id) {
    try {
      const { data } = await api.get('/cashier-sessions/active')
      activeSession.value = data && Object.keys(data).length > 0 ? data : null
    } catch (e) {
      console.error('Gagal memuat sesi kasir aktif', e)
    }
  }

  if (auth.isOwner) {
    try {
      const { data } = await api.get('/tenant/stores')
      stores.value = data || []
    } catch (e) {
      console.error('Gagal memuat toko tenant', e)
    }
  }
})

async function onStoreChange(event: Event) {
  const target = event.target as HTMLSelectElement
  const storeId = target.value ? Number(target.value) : null
  try {
    await auth.switchStore(storeId as any)
    window.location.reload()
  } catch (e) {
    alert('Gagal mengganti toko')
  }
}

async function onLogout() {
  try {
    await auth.logout()
  } catch {
    localStorage.removeItem('nessa_token')
    auth.$reset()
  }
  await router.push('/login')
}

type NavItem = { to: string; label: string }
type NavGroup = { title: string; icon: string; items: NavItem[] }

const navGroups = computed<NavGroup[]>(() => {
  const operasional: NavItem[] = [
    { to: '/', label: '📊 Dashboard' },
    { to: '/pos', label: '🛒 POS / Kasir' },
    { to: '/orders', label: '📜 Riwayat Transaksi' },
  ]

  if (activeSession.value) {
    operasional.push({ to: '/session/mutations', label: '💸 Mutasi Kas Laci' })
    operasional.push({ to: '/session/close', label: '🔴 Tutup Shift' })
  } else if (auth.user?.store_id) {
    operasional.push({ to: '/session/open', label: '🟢 Buka Shift' })
  }

  const groups: NavGroup[] = [
    { title: 'Operasional Kasir', icon: '🛒', items: operasional }
  ]

  if (!auth.isCashier) {
    groups.push({
      title: 'Keuangan & Laporan',
      icon: '💰',
      items: [
        { to: '/reports', label: '📊 Laporan Lengkap' },
        { to: '/reports/cash-mutations', label: '📈 Mutasi Kas Laci' },
        { to: '/kas-besar', label: '🏦 Kas Besar Toko' },
      ]
    })

    groups.push({
      title: 'Manajemen Data',
      icon: '📦',
      items: [
        { to: '/products', label: '📦 Produk Barang' },
        { to: '/categories', label: '🏷️ Kategori Produk' },
        { to: '/suppliers', label: '🤝 Supplier / Pemasok' },
        { to: '/purchases', label: '🛒 Pembelian Barang' },
        { to: '/users', label: '👥 Pengguna Sistem' },
        { to: '/settings', label: '⚙️ Pengaturan Toko' },
      ]
    })
  } else {
    groups.push({
      title: 'Laporan',
      icon: '📈',
      items: [
        { to: '/reports/cash-mutations', label: '📈 Laporan Mutasi Laci' }
      ]
    })
  }

  groups.push({
    title: 'Akun Saya',
    icon: '👤',
    items: [
      { to: '/change-password', label: '🔑 Ganti Password' }
    ]
  })

  return groups
})

/** Read collapsed groups from localStorage so state persists across page navigation. */
function loadCollapsed(): Set<string> {
  try {
    const raw = localStorage.getItem('nessa_sidebar_collapsed')
    return raw ? new Set<string>(JSON.parse(raw)) : new Set<string>()
  } catch {
    return new Set<string>()
  }
}

function saveCollapsed(s: Set<string>) {
  localStorage.setItem('nessa_sidebar_collapsed', JSON.stringify([...s]))
}

const collapsedGroups = ref<Set<string>>(loadCollapsed())

/** A group is open unless it's in the collapsed set. */
function isGroupOpen(group: NavGroup): boolean {
  return !collapsedGroups.value.has(group.title)
}

function toggleGroup(group: NavGroup) {
  const s = new Set(collapsedGroups.value)
  if (s.has(group.title)) {
    s.delete(group.title)
  } else {
    s.add(group.title)
  }
  collapsedGroups.value = s
  saveCollapsed(s)
}

/**
 * When the route changes, auto-expand the group that contains the new active route.
 * Other collapsed groups stay collapsed (user preference is respected).
 */
watch(
  () => route.path,
  (newPath) => {
    const activeGroup = navGroups.value.find(g =>
      g.items.some(item => item.to === newPath)
    )
    if (activeGroup && collapsedGroups.value.has(activeGroup.title)) {
      const s = new Set(collapsedGroups.value)
      s.delete(activeGroup.title)
      collapsedGroups.value = s
      saveCollapsed(s)
    }
  },
  { immediate: true }
)

type BottomItem = { to: string; label: string; icon: string }

const mobileBottomItems = computed<BottomItem[]>(() => {
  if (auth.isCashier) {
    const shiftPath = activeSession.value ? '/session/close' : '/session/open'
    const shiftLabel = activeSession.value ? 'Tutup Sesi' : 'Buka Sesi'
    const shiftIcon = activeSession.value ? '🔴' : '🟢'

    return [
      { to: '/', label: 'Dashboard', icon: '📊' },
      { to: '/pos', label: 'POS Kasir', icon: '🛒' },
      { to: '/orders', label: 'Transaksi', icon: '📜' },
      { to: '/reports/cash-mutations', label: 'Mutasi Laci', icon: '💸' },
      { to: shiftPath, label: shiftLabel, icon: shiftIcon },
    ]
  } else {
    return [
      { to: '/', label: 'Dashboard', icon: '📊' },
      { to: '/reports', label: 'Laporan', icon: '📈' },
      { to: '/kas-besar', label: 'Kas Besar', icon: '🏦' },
      { to: '/products', label: 'Produk', icon: '📦' },
      { to: '/settings', label: 'Pengaturan', icon: '⚙️' },
    ]
  }
})
</script>

<template>
  <div class="min-h-screen flex flex-col lg:grid lg:grid-cols-[250px_1fr]">
    <!-- Mobile Header -->
    <header class="lg:hidden bg-slate-900 text-white px-4 py-3 flex items-center justify-between shadow-md border-b border-slate-800 shrink-0 sticky top-0 z-40">
      <div class="flex items-center gap-3">
        <button
          type="button"
          @click="isSidebarOpen = true"
          class="p-1 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-500"
        >
          <span class="text-xl">☰</span>
        </button>
        <span class="font-bold text-base tracking-tight">Nessa POS</span>
      </div>
      <span v-if="auth.user" class="text-xs font-semibold text-emerald-400 max-w-[150px] truncate">
        🏪 {{ auth.user.store?.name ?? 'Semua Toko (Konsolidasi)' }}
      </span>
    </header>

    <!-- Mobile Sidebar Backdrop -->
    <div
      v-if="isSidebarOpen"
      @click="isSidebarOpen = false"
      class="fixed inset-0 z-40 bg-black/50 lg:hidden"
    ></div>

    <!-- Sidebar Aside -->
    <aside
      class="fixed inset-y-0 left-0 z-50 w-64 bg-slate-950 p-5 text-white transform -translate-x-full transition-transform duration-200 ease-in-out lg:translate-x-0 lg:static lg:w-full lg:h-screen lg:flex lg:flex-col overflow-y-auto border-r border-slate-800/80 scrollbar-none"
      :class="isSidebarOpen ? 'translate-x-0' : '-translate-x-full'"
    >
      <div class="flex justify-between items-center mb-6 lg:mb-5">
        <h1 class="whitespace-nowrap text-xl font-black tracking-tight text-white flex items-center gap-2">
          <span>💫</span> Nessa POS
        </h1>
        <button
          type="button"
          @click="isSidebarOpen = false"
          class="lg:hidden p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800"
        >
          ✕
        </button>
      </div>
      
      <!-- Store selector for multi-store owners -->
      <div v-if="auth.isOwner && stores.length > 1" class="mb-6 bg-slate-900 p-3 rounded-xl border border-slate-800 shadow-inner">
        <label class="block text-[10px] font-extrabold uppercase tracking-wider text-slate-500 mb-1.5">Pilih Toko</label>
        <select
          :value="auth.user?.store_id ?? ''"
          @change="onStoreChange"
          class="w-full rounded-lg border border-slate-700 bg-slate-950 px-2.5 py-1.5 text-xs text-white focus:outline-none focus:ring-1 focus:ring-slate-500"
        >
          <option value="">Semua Toko (Konsolidasi)</option>
          <option v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</option>
        </select>
      </div>
      <div v-else-if="auth.user?.store" class="mb-6 text-[11px] text-slate-300 font-semibold flex items-center gap-1.5 bg-slate-900/60 px-3 py-2.5 rounded-xl border border-slate-800/50">
        <span>Store:</span> <span class="truncate font-bold text-emerald-400">{{ auth.user.store.name }}</span>
      </div>

      <nav class="space-y-1">
        <div v-for="group in navGroups" :key="group.title">
          <!-- Group Header (Collapsible Toggle) -->
          <button
            type="button"
            @click="toggleGroup(group)"
            class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-left transition-all hover:bg-slate-900/50 group"
            :class="isGroupOpen(group) ? 'text-white' : 'text-slate-400'"
          >
            <span class="flex items-center gap-2 text-[11px] font-extrabold uppercase tracking-widest">
              <span class="text-sm">{{ group.icon }}</span>
              {{ group.title }}
            </span>
            <span
              class="text-slate-500 text-xs transition-transform duration-200"
              :class="isGroupOpen(group) ? 'rotate-180 text-emerald-400' : 'rotate-0'"
            >▾</span>
          </button>

          <!-- Group Items (Collapsible Body) -->
          <div
            class="overflow-hidden transition-all duration-200 ease-in-out"
            :class="isGroupOpen(group) ? 'max-h-96 opacity-100' : 'max-h-0 opacity-0'"
          >
            <div class="space-y-0.5 pl-2 pb-1 pt-0.5">
              <RouterLink
                v-for="item in group.items"
                :key="item.to"
                v-slot="{ href, navigate, isActive }"
                :to="item.to"
                custom
              >
                <a
                  :href="href"
                  class="flex items-center rounded-xl px-3 py-2 text-[12.5px] font-semibold text-slate-400 hover:text-white hover:bg-slate-900/50 transition-all"
                  :class="isActive ? 'bg-emerald-500/10 text-emerald-400 font-bold border-l-[3px] border-emerald-500 pl-2.5 rounded-l-none rounded-r-xl' : ''"
                  @click.prevent="navigate(); isSidebarOpen = false"
                >
                  {{ item.label }}
                </a>
              </RouterLink>
            </div>
          </div>
        </div>
      </nav>
      <p v-if="auth.isCashier" class="mt-8 border-t border-slate-800 pt-5 text-xs text-slate-500 font-medium">
        Mode kasir: hanya beranda, kasir, dan riwayat transaksi.
      </p>
    </aside>

    <!-- Main Content Area -->
    <div class="min-w-0 flex-1 flex flex-col lg:h-screen lg:overflow-y-auto">
      <!-- Top Bar on Desktop -->
      <div class="hidden lg:flex items-center justify-between bg-white border-b border-slate-200 px-6 py-3 shrink-0">
        <div class="text-sm font-medium text-slate-600">
          Selamat datang, <span class="text-slate-900 font-semibold">{{ auth.user?.name || 'User' }}</span> ({{ auth.user?.role || '-' }})
        </div>
        <div class="flex items-center gap-3">
          <RouterLink to="/change-password" class="rounded-lg border border-slate-200 px-3.5 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-50 transition-colors">
            🔑 Ganti Password
          </RouterLink>
          <button class="rounded-lg bg-red-500 px-3.5 py-1.5 text-xs font-bold text-white hover:bg-red-600 transition-colors" type="button" @click="onLogout">
            Keluar
          </button>
        </div>
      </div>

      <!-- Main container -->
      <main class="p-4 sm:p-6 pb-20 sm:pb-24 lg:pb-6 flex-1 bg-slate-50">
        <!-- Mobile welcome info (top of page content on mobile) -->
        <div class="lg:hidden mb-4 flex items-center justify-between rounded-lg bg-white p-3 border border-slate-200 shadow-sm text-xs">
          <span>{{ auth.user?.name || 'User' }} — {{ auth.user?.role || '-' }}</span>
          <div class="flex items-center gap-3">
            <RouterLink to="/change-password" class="text-slate-600 font-bold hover:underline">
              Ganti Password
            </RouterLink>
            <button class="text-red-500 font-bold hover:underline" type="button" @click="onLogout">
              Keluar
            </button>
          </div>
        </div>
        <slot />
      </main>
    </div>

    <!-- Mobile Bottom Navigation Bar -->
    <div class="fixed bottom-0 left-0 right-0 z-40 bg-white border-t border-slate-200 lg:hidden px-2 py-1.5 flex items-center justify-around shadow-[0_-2px_10px_rgba(0,0,0,0.05)] safe-bottom">
      <RouterLink
        v-for="item in mobileBottomItems"
        :key="item.to"
        :to="item.to"
        v-slot="{ href, navigate, isActive }"
        custom
      >
        <a
          :href="href"
          @click="navigate"
          class="flex flex-col items-center justify-center flex-1 py-1 px-1 text-center transition-colors"
          :class="isActive ? 'text-emerald-500 font-bold' : 'text-slate-400 hover:text-slate-600'"
        >
          <span class="text-lg leading-none">{{ item.icon }}</span>
          <span class="text-[9px] font-extrabold mt-1 tracking-tighter leading-none whitespace-nowrap">{{ item.label }}</span>
        </a>
      </RouterLink>
    </div>
  </div>
</template>
