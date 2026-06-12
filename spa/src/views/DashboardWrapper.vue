<script setup>
import { useAuthStore } from '@/stores/auth'
import AgencyDashboard from './admin/AgencyDashboard.vue' // Adjust path if needed
import AdminDashboard from './admin/AdminDashboard.vue' // The new file created above

const authStore = useAuthStore()

// Helper to check if user is super admin
// Assumes your auth store has user.role or user.is_super_admin
const isSuperAdmin = authStore.user?.role === 'super_admin'
</script>

<template>
  <AdminDashboard v-if="isSuperAdmin" />
  <AgencyDashboard v-else />
</template>
