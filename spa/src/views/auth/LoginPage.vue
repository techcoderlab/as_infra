<script setup>
  import { ref } from 'vue'
  import { useRouter } from 'vue-router'
  import { useAuthStore } from '../../stores/auth'
  
  const router = useRouter()
  const auth = useAuthStore()
  
  const email = ref('')
  const password = ref('')
  const loading = ref(false)
  const error = ref('')
  
  const submit = async () => {
    loading.value = true
    error.value = ''
    try {
      await auth.login({
        email: email.value,
        password: password.value,
      })
      router.push({ name: 'dashboard' }) // or 'dashboard' depending on your route name
    } catch (e) {
      error.value = e.response?.data?.message || 'Invalid credentials'
    } finally {
      loading.value = false
    }
  }
  </script>
  
  <template>
    <div class="min-h-screen flex items-center justify-center bg-slate-50 dark:bg-slate-950 transition-colors duration-300 relative overflow-hidden">
      
      <div class="absolute inset-0 z-0 opacity-40 dark:opacity-20 pointer-events-none">
          <div class="absolute -top-24 -left-24 w-96 h-96 bg-blue-500/20 rounded-full blur-3xl"></div>
          <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[800px] h-[800px] bg-slate-200/40 dark:bg-slate-800/20 rounded-full blur-3xl"></div>
      </div>
  
      <div class="w-full max-w-md bg-white dark:bg-slate-900 shadow-xl border border-slate-200 dark:border-slate-800 rounded-2xl p-8 z-10 transition-colors duration-300 relative">
        
        <div class="text-center mb-8">
          <div class="h-12 w-12 bg-slate-900 dark:bg-white text-white dark:text-slate-900 rounded-xl flex items-center justify-center mx-auto mb-4 shadow-lg">
             <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
          </div>
          <h1 class="text-2xl font-bold text-slate-900 dark:text-white tracking-tight">Welcome back</h1>
          <p class="text-sm text-slate-500 dark:text-slate-400 mt-2">Sign in to your agency dashboard</p>
        </div>
  
        <form @submit.prevent="submit" class="space-y-5">
          <div>
            <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5 ml-1">Email Address</label>
            <input
              v-model="email"
              type="email"
              class="block w-full rounded-lg border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-950 text-slate-900 dark:text-white px-4 py-2.5 text-sm focus:ring-2 focus:ring-slate-900 dark:focus:ring-white focus:border-transparent transition-all outline-none"
              placeholder="you@agency.com"
              required
            />
          </div>
  
          <div>
            <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5 ml-1">Password</label>
            <input
              v-model="password"
              type="password"
              class="block w-full rounded-lg border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-950 text-slate-900 dark:text-white px-4 py-2.5 text-sm focus:ring-2 focus:ring-slate-900 dark:focus:ring-white focus:border-transparent transition-all outline-none"
              placeholder="••••••••"
              required
            />
          </div>
  
          <div v-if="error" class="p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 text-sm font-medium border border-red-100 dark:border-red-900/30 flex items-center gap-2 animate-pulse">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            {{ error }}
          </div>
  
          <button
            type="submit"
            class="w-full bg-slate-900 dark:bg-white text-white dark:text-slate-900 font-bold py-2.5 rounded-lg hover:bg-slate-800 dark:hover:bg-slate-100 transition-all transform active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed shadow-lg shadow-slate-900/10"
            :disabled="loading"
          >
            {{ loading ? 'Signing in...' : 'Sign In' }}
          </button>
        </form>
        
        <div class="mt-6 text-center">
           <p class="text-xs text-slate-400">Powered by Agency SaaS</p>
        </div>
      </div>
    </div>
  </template>