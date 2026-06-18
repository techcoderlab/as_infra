<script setup>
import { onMounted, ref, reactive, computed } from 'vue'
import api from '../../utils/request'

const users = ref([])
const tenants = ref([])
const loading = ref(false)
const saving = ref(false)

const showModal = ref(false)
const isEditing = ref(false)
const editingId = ref(null)

const defaultForm = () => ({
  name: '',
  email: '',
  password: '',
  global_role: 'staff',
  tenant_id: null,
  tenant_role: 'staff',
  is_active: true, // For soft deletes
})
const form = reactive(defaultForm())
const errors = reactive({ name: '', email: '', password: '', tenant_id: '' })

// Search and filters
const search = ref('')
const selectedTenantFilter = ref('')

const fetchUsers = async () => {
  loading.value = true
  try {
    const params = {}
    if (search.value) params.search = search.value
    if (selectedTenantFilter.value) params.tenant_id = selectedTenantFilter.value

    const { data } = await api.get('/admin/users', { params })
    users.value = data.data || data // fallback if paginated vs plain array
  } catch (e) {
    console.error(e)
  } finally {
    loading.value = false
  }
}

const fetchTenants = async () => {
  try {
    const { data } = await api.get('/tenants')
    tenants.value = data.tenants || data
  } catch (e) {
    console.error('Failed to load tenants', e)
  }
}

const openCreate = () => {
  isEditing.value = false
  editingId.value = null
  Object.assign(form, defaultForm())
  clearErrors()
  showModal.value = true
}

const openEdit = (user) => {
  isEditing.value = true
  editingId.value = user.id
  
  // Find global role (super_admin, agency_owner, staff)
  const globalRole = user.roles && user.roles.length > 0 ? user.roles[0].name : 'staff'
  
  // Find primary tenant
  let primaryTenant = null
  let tenantRole = 'staff'
  if (user.tenants && user.tenants.length > 0) {
    primaryTenant = user.tenants.find(t => t.pivot?.is_primary) || user.tenants[0]
    tenantRole = primaryTenant.pivot?.role || 'staff'
  }

  Object.assign(form, {
    name: user.name || '',
    email: user.email || '',
    password: '', // Never pre-fill
    global_role: globalRole,
    tenant_id: primaryTenant ? primaryTenant.id : null,
    tenant_role: tenantRole,
    is_active: !user.deleted_at,
  })
  clearErrors()
  showModal.value = true
}

const clearErrors = () => {
  Object.keys(errors).forEach(k => errors[k] = '')
}

const validateForm = () => {
  let isValid = true
  clearErrors()

  if (!form.name) { errors.name = 'Name is required'; isValid = false }
  if (!form.email) { errors.email = 'Email is required'; isValid = false }
  if (!isEditing.value && !form.password) { errors.password = 'Password is required for new users'; isValid = false }

  return isValid
}

const generatePassword = () => {
  const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*'
  let pass = ''
  for (let i = 0; i < 16; i++) {
    pass += chars.charAt(Math.floor(Math.random() * chars.length))
  }
  form.password = pass
}

const saveUser = async () => {
  if (!validateForm()) return
  saving.value = true

  const payload = { ...form }
  if (isEditing.value && !payload.password) {
    delete payload.password // Don't send empty password if not changing
  }

  try {
    if (isEditing.value) {
      await api.put(`/admin/users/${editingId.value}`, payload)
      // Also update tenant logic if tenant changed
      if (form.tenant_id) {
         await api.post(`/admin/users/${editingId.value}/assign-tenant`, {
            tenant_id: form.tenant_id,
            role: form.tenant_role,
            is_primary: true
         }).catch(e => {
            if(e.response?.status !== 409) {
                // If it's already assigned (409), we might need to just update the role
                api.put(`/admin/users/${editingId.value}/tenants/${form.tenant_id}/role`, {
                    role: form.tenant_role,
                    is_primary: true
                })
            }
         })
      }
    } else {
      await api.post('/admin/users', payload)
    }
    showModal.value = false
    await fetchUsers()
  } catch (e) {
    const msg = e.response?.data?.message || 'Failed to save user.'
    alert(msg)
  } finally {
    saving.value = false
  }
}

const deleteUser = async (user) => {
  if (!confirm(`Are you sure you want to suspend user ${user.name}?`)) return
  try {
    await api.delete(`/admin/users/${user.id}`)
    await fetchUsers()
  } catch (e) {
    alert(e.response?.data?.message || 'Failed to delete user.')
  }
}

const formatRole = (role) => {
    if(!role) return 'None'
    return role.split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ')
}

onMounted(() => {
  fetchUsers()
  fetchTenants()
})
</script>

<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="page-header">
      <div>
        <h2 class="page-title">User Management</h2>
        <p class="page-subtitle">Manage all users, roles, and tenant access.</p>
      </div>
      <button @click="openCreate" class="btn-primary">+ Add User</button>
    </div>

    <!-- Filters -->
    <div class="flex gap-4 mb-4">
        <input 
            v-model="search" 
            @input="fetchUsers"
            type="text" 
            placeholder="Search name or email..." 
            class="form-input max-w-xs"
        />
        <select v-model="selectedTenantFilter" @change="fetchUsers" class="form-select max-w-xs">
            <option value="">All Tenants</option>
            <option v-for="t in tenants" :key="t.id" :value="t.id">{{ t.name }}</option>
        </select>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="text-center py-12 text-slate-500">Loading...</div>

    <!-- Table -->
    <div v-else class="card overflow-hidden">
        <table class="w-full text-left text-sm text-slate-600 dark:text-slate-400">
            <thead class="bg-slate-50 dark:bg-slate-900/50 text-xs uppercase font-medium text-slate-500 dark:text-slate-400">
                <tr>
                    <th class="px-6 py-4">User</th>
                    <th class="px-6 py-4">Global Role</th>
                    <th class="px-6 py-4">Tenant Assignments</th>
                    <th class="px-6 py-4 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                <tr v-if="users.length === 0">
                    <td colspan="4" class="px-6 py-12 text-center text-slate-500">No users found.</td>
                </tr>
                <tr v-for="user in users" :key="user.id" class="hover:bg-slate-50 dark:hover:bg-slate-900/30 transition-colors" :class="{'opacity-50': user.deleted_at}">
                    <td class="px-6 py-4">
                        <div class="font-bold text-slate-900 dark:text-white">{{ user.name }}</div>
                        <div class="text-xs text-slate-500">{{ user.email }}</div>
                        <div v-if="user.deleted_at" class="text-xs text-red-500 font-bold mt-1">Suspended</div>
                    </td>
                    <td class="px-6 py-4">
                        <span class="badge badge-purple" v-if="user.roles && user.roles.length > 0">
                            {{ formatRole(user.roles[0].name) }}
                        </span>
                        <span class="text-xs text-slate-400" v-else>None</span>
                    </td>
                    <td class="px-6 py-4">
                        <div class="flex flex-wrap gap-2">
                            <span v-for="t in user.tenants" :key="t.id" class="badge" :class="t.pivot.is_primary ? 'badge-blue' : 'badge-slate'">
                                {{ t.name }} <span class="opacity-60 ml-1">({{ formatRole(t.pivot.role) }})</span>
                            </span>
                            <span v-if="!user.tenants || user.tenants.length === 0" class="text-xs text-slate-400">No Tenants</span>
                        </div>
                    </td>
                    <td class="px-6 py-4 text-right">
                        <button @click="openEdit(user)" class="text-blue-500 hover:text-blue-700 font-medium text-sm mr-4">Edit</button>
                        <button v-if="!user.deleted_at" @click="deleteUser(user)" class="text-red-500 hover:text-red-700 font-medium text-sm">Suspend</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Create / Edit Modal -->
    <Transition name="modal">
      <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" @click="showModal = false"></div>
        <div class="relative w-full max-w-lg bg-white dark:bg-slate-950 shadow-2xl rounded-xl border border-slate-200 dark:border-slate-800 flex flex-col max-h-[90vh]">
          <!-- Header -->
          <div class="p-6 border-b border-slate-100 dark:border-slate-800">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">
              {{ isEditing ? 'Edit User' : 'New User' }}
            </h3>
          </div>

          <!-- Body -->
          <div class="p-6 overflow-y-auto space-y-4">
            
            <div class="form-group">
              <label class="form-label">Full Name</label>
              <input v-model="form.name" class="form-input" placeholder="e.g. John Doe" :class="{'border-red-500': errors.name}" />
              <span v-if="errors.name" class="text-xs text-red-500">{{ errors.name }}</span>
            </div>

            <div class="form-group">
              <label class="form-label">Email Address</label>
              <input v-model="form.email" type="email" class="form-input" placeholder="john@example.com" :class="{'border-red-500': errors.email}" />
              <span v-if="errors.email" class="text-xs text-red-500">{{ errors.email }}</span>
            </div>

            <div class="form-group">
              <div class="flex justify-between items-center mb-1">
                 <label class="form-label mb-0">Password <span v-if="isEditing" class="text-xs text-slate-400 font-normal ml-1">(leave blank to keep)</span></label>
                 <button @click="generatePassword" class="text-xs text-blue-500 hover:text-blue-600 font-medium bg-blue-50 dark:bg-blue-900/20 px-2 py-1 rounded">Generate</button>
              </div>
              <input v-model="form.password" type="text" class="form-input" placeholder="Strong password" :class="{'border-red-500': errors.password}" />
              <span v-if="errors.password" class="text-xs text-red-500">{{ errors.password }}</span>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="form-group">
                <label class="form-label">Global System Role</label>
                <select v-model="form.global_role" class="form-select">
                    <option value="staff">Staff</option>
                    <option value="agency_owner">Agency Owner</option>
                    <option value="super_admin">Super Admin</option>
                </select>
                </div>
                
                <div v-if="isEditing" class="form-group">
                    <label class="form-label">Account Status</label>
                    <select v-model="form.is_active" class="form-select" :class="!form.is_active ? 'text-red-500 font-bold' : 'text-green-500 font-bold'">
                        <option :value="true">Active</option>
                        <option :value="false">Suspended</option>
                    </select>
                </div>
            </div>

            <hr class="border-slate-100 dark:border-slate-800 my-4" />
            <h4 class="font-bold text-sm text-slate-800 dark:text-slate-200">Primary Tenant Assignment</h4>

            <div class="form-group">
              <label class="form-label">Assign to Tenant</label>
              <select v-model="form.tenant_id" class="form-select">
                <option :value="null">No Tenant (Global User)</option>
                <option v-for="t in tenants" :key="t.id" :value="t.id">{{ t.name }}</option>
              </select>
            </div>

            <div class="form-group" v-if="form.tenant_id">
              <label class="form-label">Tenant Role</label>
              <select v-model="form.tenant_role" class="form-select">
                <option value="staff">Staff</option>
                <option value="agency_owner">Agency Owner</option>
              </select>
            </div>

          </div>

          <!-- Footer -->
          <div class="flex justify-end gap-3 p-6 border-t border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-900/50 rounded-b-xl">
            <button @click="showModal = false" class="btn-secondary">Cancel</button>
            <button @click="saveUser" class="btn-primary" :disabled="saving">
              {{ saving ? 'Saving...' : isEditing ? 'Update User' : 'Create User' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.2s ease;
}
.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}
</style>
