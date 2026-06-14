import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

// Import Views
import AdminLayout from '../views/admin/AdminLayout.vue'
import AgencyDashboard from '@/views/admin/AgencyDashboard.vue'
import SuperAdminTenants from '../views/admin/SuperAdminTenants.vue'
import LeadsPage from '../views/admin/LeadsPage.vue'
import LeadDetails from '../views/admin/LeadDetails.vue'
import FormsPage from '../views/admin/FormsPage.vue'
import WebhooksPage from '../views/admin/WebhooksPage.vue'
import ApiKeysPage from '../views/admin/ApiKeysPage.vue'
import AiChatIndexPage from '../views/admin/ai-chat/AiChatIndex.vue'
import AiChatRoomPage from '../views/admin/ai-chat/AiChatRoom.vue'
import SuperAdminPlansAndModules from '../views/admin/PlansManager.vue'
import ExternalApiKeysPage from '../views/admin/ExternalApiKeysPage.vue'

import DashboardWrapper from '@/views/DashboardWrapper.vue' // Import the wrapper

import IntegrationPage from '../views/admin/IntegrationsPage.vue'
import AiAgentBuilderPage from '../views/admin/AiAgentBuilder.vue'

// Auth/Public Views
import LoginPage from '../views/auth/LoginPage.vue'
import PublicFormPage from '../views/public/PublicFormPage.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: LoginPage,
    },
    {
      path: '/admin',
      component: AdminLayout,
      meta: { requiresAuth: true },
      children: [
        {
          path: '',
          name: 'dashboard',
          component: DashboardWrapper, // Use the wrapper here!
        },
        {
          path: 'leads',
          name: 'leads',
          component: LeadsPage,
          meta: { module: 'leads' },
        },
        {
          path: 'leads/:id',
          name: 'lead-details',
          component: LeadDetails,
          meta: { module: 'leads' },
        },
        {
          path: 'forms',
          name: 'forms',
          component: FormsPage,
          meta: { module: 'forms' },
        },
        // AI Chat Routes
        {
          path: 'ai-chats',
          name: 'ai-chats',
          component: AiChatIndexPage,
          // You probably want to protect this too eventually:
          meta: { module: 'ai_chats' },
        },
        {
          path: 'ai-chats/:id',
          name: 'ai-chat-room',
          component: AiChatRoomPage,
          props: true,
          meta: { module: 'ai_chats' },
        },
        {
          path: 'webhooks',
          name: 'webhooks',
          component: WebhooksPage,
          meta: { module: 'webhooks' },
        },
        {
          path: 'api-keys',
          name: 'api-keys',
          component: ApiKeysPage,
          meta: { module: 'api_keys' },
        },
        {
          path: 'integrations',
          name: 'integrations',
          component: IntegrationPage,
          // meta: { module: 'integrations' },
        },
        {
          path: 'ai-agents',
          name: 'ai-agents',
          component: AiAgentBuilderPage,
          // meta: { module: 'ai_agents' },
        },

        {
          path: 'tenants',
          name: 'superadmin-tenants',
          component: SuperAdminTenants,
          meta: { role: 'super_admin' },
        },
        {
          path: 'plans-and-modules',
          name: 'superadmin-plans-and-modules',
          component: SuperAdminPlansAndModules,
          meta: { role: 'super_admin' },
        },
        {
          path: 'external-api-keys',
          name: 'superadmin-external-api-keys',
          component: ExternalApiKeysPage,
          meta: { role: 'super_admin' },
        },
      ],
    },
    {
      path: '/form/:uuid',
      name: 'public-form',
      component: PublicFormPage,
      meta: { public: true },
    },
    // Catch all redirect to dashboard
    { path: '/:pathMatch(.*)*', redirect: '/admin' },
  ],
})

// --- FIX: Async Guard ---
router.beforeEach(async (to, from, next) => {
  const authStore = useAuthStore()

  // 1. Wait for Bootstrap if we have a token but no data
  if (authStore.token && !authStore.isBootstrapped) {
    await authStore.bootstrap()
  }

  // 2. Check Authentication
  if (to.meta.requiresAuth && !authStore.token) {
    return next({ name: 'login', query: { redirect: to.fullPath } })
  }

  // 3. Module Access Check
  if (authStore.token && to.meta.module) {
    // Note: We check both underscore and dash versions to be safe against DB inconsistencies
    const mod = to.meta.module
    const enabled = authStore.enabledModules || []

    if (!enabled.includes(mod) && !enabled.includes(mod.replace('_', '-'))) {
      console.warn(`Access denied to module: ${mod}. Enabled:`, enabled)
      return next({ name: 'dashboard' })
    }
  }

  // 4. Role Access Check
  if (authStore.token && to.meta.role) {
    const hasRole = authStore.user?.roles?.some((r) => r.name === to.meta.role)
    if (!hasRole) {
      return next({ name: 'dashboard' })
    }
  }

  next()
})

export default router
