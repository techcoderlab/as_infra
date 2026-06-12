import { ref, watchEffect } from 'vue';

const isDark = ref(false);

export function useTheme() {
  // 1. Initialize Theme
  const initTheme = () => {
    const cachedTheme = localStorage.getItem('theme');
    
    if (cachedTheme === 'dark' || (!cachedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
      isDark.value = true;
      document.documentElement.classList.add('dark');
    } else {
      isDark.value = false;
      document.documentElement.classList.remove('dark');
    }
  };

  // 2. Toggle Function
  const toggleTheme = () => {
    isDark.value = !isDark.value;
    
    if (isDark.value) {
      document.documentElement.classList.add('dark');
      localStorage.setItem('theme', 'dark');
    } else {
      document.documentElement.classList.remove('dark');
      localStorage.setItem('theme', 'light');
    }
  };

  return {
    isDark,
    initTheme,
    toggleTheme
  };
}