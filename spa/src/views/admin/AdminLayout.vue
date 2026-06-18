<script setup>
import { RouterView, RouterLink, useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '../../stores/auth'
import { onMounted, computed } from 'vue'
// import { useApiCache } from '../../composables/useApiCache'
// import api from '../../utils/request'
import ThemeToggle from '@/components/ui/ThemeToggle.vue' // Import the new component

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
// const { fetchDataWithCache } = useApiCache()

const logout = async () => {
  await auth.logout()
  router.push('/login')
}

const navigation = computed(() => {
  const baseNav = [
    {
      label: 'Dashboard',
      route: '/admin',
      icon: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
    },
    // {
    //   label: 'Integrations',
    //   route: '/admin/integrations',
    //   icon: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
    // },
    // {
    //   label: 'Ai Agents',
    //   route: '/admin/ai-agents',
    //   icon: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
    // },
  ]
  if (auth.userRoles?.includes('super_admin')) {
    baseNav.push({
      label: 'Tenants',
      route: '/admin/tenants',
      icon: 'M21 13.25V18a2 2 0 01-2 2H5a2 2 0 01-2-2v-4.75M7 10V4a1 1 0 011-1h8a1 1 0 011 1v6M3 10h18a2 2 0 012 2v1a2 2 0 01-2 2H3a2 2 0 01-2-2v-1a2 2 0 012-2z',
    })
    baseNav.push({
      label: 'Plans & Modules',
      route: '/admin/plans-and-modules',
      icon: 'M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z',
    })
    baseNav.push({
      label: 'Users',
      route: '/admin/users',
      icon: 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z',
    })
  }
  // Directly use moduleNav from the bootstrap payload
  return [...baseNav, ...auth.moduleNav]
})

onMounted(async () => {
  // If data is missing (e.g., direct page load), trigger bootstrap
  if (!auth.user) {
    await auth.bootstrap()
  }
})

// console.log(navigation.value)

// const fetchModules = async () => {
//   // Use existing cache logic
//   const data = await fetchDataWithCache('tenant_modules_cache', 600000, () =>
//     api.get('/tenants/modules'),
//   )
//   if (data) {
//     const mergedArray = [...navigation.value, ...data]
//     navigation.value = mergedArray
//     //  console.log(mergedArray)
//   }
// }
</script>

<template>
  <div class="min-h-screen flex transition-colors duration-300">
    <div
      class="fixed inset-y-0 left-0 z-50 w-64 border-r border-slate-200 dark:border-slate-800 flex flex-col justify-between transition-colors duration-300 bg-slate-200/20 dark:bg-slate-900/20"
    >
      <div>
        <div class="flex h-16 items-center px-6 border-b border-slate-200 dark:border-slate-800">
          <h1 class="text-xl font-bold text-slate-900 dark:text-white tracking-tight">
            Agency SaaS
          </h1>
        </div>

        <nav class="space-y-1 px-3 py-4 h-full">
          <RouterLink
            v-for="item in navigation"
            :key="item.label"
            :to="item.route"
            :class="[
              route.path === item.route ||
              (item.route !== '/admin' && route.path.startsWith(item.route))
                ? 'bg-slate-200 text-slate-900 dark:bg-slate-900 dark:text-white'
                : 'text-slate-600 hover:bg-slate-200/50 dark:text-slate-400 dark:hover:bg-slate-900 dark:hover:text-slate-200',
              'group flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-all duration-200',
            ]"
          >
            <svg
              class="mr-3 h-5 w-5 flex-shrink-0 transition-colors"
              :class="[
                route.path === item.route ||
                (item.route !== '/admin' && route.path.startsWith(item.route))
                  ? 'text-indigo-600 dark:text-indigo-400'
                  : 'text-slate-400 group-hover:text-slate-500 dark:text-slate-500 dark:group-hover:text-slate-300',
              ]"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              aria-hidden="true"
            >
              <path
                v-for="(pathD, index) in Array.isArray(item.icon) ? item.icon : [item.icon]"
                :key="index"
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                :d="pathD"
              />
            </svg>
            {{ item.label }}
          </RouterLink>
        </nav>
      </div>

      <div class="border-t border-slate-200 dark:border-slate-800 p-4 space-y-3">
        <div class="flex items-center justify-between px-2">
          <div class="flex flex-col">
            <span class="text-sm font-medium text-slate-700 dark:text-slate-200">User Account</span>
            <span
              class="text-xs text-slate-500 dark:text-slate-500 truncate max-w-[120px]"
              :title="auth.user?.email"
            >
              {{ auth.user?.email }}
            </span>
          </div>

          <ThemeToggle />
        </div>

        <button
          @click="logout"
          class="w-full flex items-center justify-center px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/10 dark:hover:text-red-300 rounded-lg transition-colors duration-200"
        >
          <svg class="mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"
            />
          </svg>
          Logout
        </button>
      </div>
    </div>

    <div class="pl-64 w-full">
      <main class="py-8 px-8">
        <RouterView />
      </main>
    </div>
  </div>
</template>
