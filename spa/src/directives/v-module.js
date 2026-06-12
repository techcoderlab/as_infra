import { useAuthStore } from '@/stores/auth'

export const vModule = {
  mounted(el, binding) {
    const authStore = useAuthStore()
    const module = binding.value

    if (!module) {
      console.warn('v-module directive requires a module name.')
      return
    }

    if (!authStore.enabledModules.includes(module)) {
      // Remove the element from the DOM
      el.style.display = 'none'
    }
  },
  // You might also want to handle updates if the modules can change dynamically
  updated(el, binding) {
    const authStore = useAuthStore()
    const module = binding.value

    if (!module) {
      return
    }

    if (!authStore.enabledModules.includes(module)) {
      el.style.display = 'none'
    } else {
      el.style.display = ''
    }
  },
}
