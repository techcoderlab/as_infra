import { useAuthStore } from '@/stores/auth'

export const vCan = {
  mounted(el, binding) {
    const authStore = useAuthStore()
    const permission = binding.value

    if (!permission) {
      console.warn('v-can directive requires a permission name.')
      return
    }

    if (!authStore.permissions.includes(permission)) {
      // Remove the element from the DOM
      el.style.display = 'none'
    }
  },
  updated(el, binding) {
    const authStore = useAuthStore()
    const permission = binding.value

    if (!permission) {
      return
    }

    if (!authStore.permissions.includes(permission)) {
      el.style.display = 'none'
    } else {
      el.style.display = ''
    }
  },
}
