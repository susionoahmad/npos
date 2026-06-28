import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import pinia from '../pinia'
import LoginPage from '../views/LoginPage.vue'
import RegisterPage from '../views/RegisterPage.vue'
import SetupWizardPage from '../views/SetupWizardPage.vue'
import DashboardPage from '../views/DashboardPage.vue'
import PosPage from '../views/PosPage.vue'
import ProductsPage from '../views/ProductsPage.vue'
import CategoriesPage from '../views/CategoriesPage.vue'
import SuppliersPage from '../views/SuppliersPage.vue'
import OrdersPage from '../views/OrdersPage.vue'
import UsersPage from '../views/UsersPage.vue'
import SettingsPage from '../views/SettingsPage.vue'
import OpenSessionPage from '../views/OpenSessionPage.vue'
import CloseSessionPage from '../views/CloseSessionPage.vue'
import CashMutationsPage from '../views/CashMutationsPage.vue'
import CashMutationsReportPage from '../views/CashMutationsReportPage.vue'
import KasBesarPage from '../views/KasBesarPage.vue'
import ReportsPage from '../views/ReportsPage.vue'
import PurchasesPage from '../views/PurchasesPage.vue'
import ChangePasswordPage from '../views/ChangePasswordPage.vue'

/** Rute yang boleh diakses role cashier (selain /login) */
const CASHIER_PATHS = new Set(['/', '/pos', '/orders', '/session/open', '/session/close', '/session/mutations', '/reports/cash-mutations', '/change-password'])
// /reports is admin/owner only — not in cashier paths

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', component: LoginPage },
    { path: '/register', component: RegisterPage },
    { path: '/setup', component: SetupWizardPage },
    { path: '/', component: DashboardPage },
    { path: '/pos', component: PosPage },
    { path: '/products', component: ProductsPage },
    { path: '/categories', component: CategoriesPage },
    { path: '/suppliers', component: SuppliersPage },
    { path: '/orders', component: OrdersPage },
    { path: '/users', component: UsersPage },
    { path: '/settings', component: SettingsPage },
    { path: '/session/open', component: OpenSessionPage },
    { path: '/session/close', component: CloseSessionPage },
    { path: '/session/mutations', component: CashMutationsPage },
    { path: '/reports/cash-mutations', component: CashMutationsReportPage },
    { path: '/kas-besar', component: KasBesarPage },
    { path: '/purchases', component: PurchasesPage },
    { path: '/reports', component: ReportsPage },
    { path: '/change-password', component: ChangePasswordPage },
  ],
})

router.beforeEach(async (to) => {
  const token = localStorage.getItem('nessa_token')

  // 1. Allow unauthenticated access to login and register
  if (to.path === '/login' || to.path === '/register') {
    if (token) return '/'
    return true
  }

  // 2. Force login if token is missing
  if (!token) return '/login'

  // 3. Load user details
  const auth = useAuthStore(pinia)
  if (!auth.user) {
    try {
      await auth.fetchMe()
    } catch {
      localStorage.removeItem('nessa_token')
      return '/login'
    }
  }

  // 4. Force new owner to Setup Wizard
  const isOwnerWithoutTenant = auth.user?.role === 'owner' && !auth.user?.tenant_id
  if (isOwnerWithoutTenant) {
    if (to.path !== '/setup') {
      return '/setup'
    }
    return true
  }

  // 5. Prevent completed users from entering Setup Wizard
  if (to.path === '/setup') {
    return '/'
  }

  // 6. Cashier route restrictions
  if (auth.user?.role === 'cashier' && !CASHIER_PATHS.has(to.path)) {
    return '/pos'
  }

  return true
})

export default router
