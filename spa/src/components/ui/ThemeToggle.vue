<script setup>
    import { useTheme } from '@/composables/useTheme';
    
    const { isDark, toggleTheme } = useTheme();
    </script>
    
    <template>
      <button
        @click="toggleTheme"
        class="relative inline-flex items-center justify-center p-2 rounded-lg transition-colors duration-200
               text-slate-500 hover:text-slate-900 hover:bg-slate-100 
               dark:text-slate-400 dark:hover:text-white dark:hover:bg-slate-800
               focus:outline-none focus:ring-2 focus:ring-slate-200 dark:focus:ring-slate-700"
        aria-label="Toggle Theme"
        title="Toggle Theme"
      >
        <svg 
            v-if="isDark" 
            xmlns="http://www.w3.org/2000/svg" 
            class="w-5 h-5 transition-transform duration-300 hover:rotate-90" 
            viewBox="0 0 24 24" 
            fill="none" 
            stroke="currentColor" 
            stroke-width="2" 
            stroke-linecap="round" 
            stroke-linejoin="round"
        >
          <circle cx="12" cy="12" r="5"></circle>
          <line x1="12" y1="1" x2="12" y2="3"></line>
          <line x1="12" y1="21" x2="12" y2="23"></line>
          <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
          <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
          <line x1="1" y1="12" x2="3" y2="12"></line>
          <line x1="21" y1="12" x2="23" y2="12"></line>
          <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
          <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
        </svg>
    
        <svg 
            v-else 
            xmlns="http://www.w3.org/2000/svg" 
            class="w-5 h-5 transition-transform duration-300 hover:-rotate-12" 
            viewBox="0 0 24 24" 
            fill="none" 
            stroke="currentColor" 
            stroke-width="2" 
            stroke-linecap="round" 
            stroke-linejoin="round"
        >
          <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
        </svg>
      </button>
    </template>