<script setup>
import { ref, onMounted } from 'vue'
import request from '@/utils/request'

// --- State ---
const plans = ref([])
const modules = ref([])
const loading = ref(false)
const showModal = ref(false)
const saving = ref(false)

// Form State
const isEditing = ref(false)
const form = ref({
  id: null,
  name: '',
  slug: '',
  price: 0,
  // This will hold the configuration for every module
  module_configs: {},
})

// --- Actions ---

const fetchData = async () => {
  loading.value = true
  try {
    const { data } = await request.get('/plans-data') // Ensure this endpoint returns { plans: [], modules: [] }
    plans.value = data.plans
    modules.value = data.modules
  } catch (e) {
    console.error('Failed to load plans', e)
  } finally {
    loading.value = false
  }
}

const openCreate = () => {
  isEditing.value = false
  form.value = {
    id: null,
    name: '',
    slug: '',
    price: 0,
    module_configs: {},
  }

  // Initialize all modules as disabled with default limit
  modules.value.forEach((m) => {
    form.value.module_configs[m.id] = { enabled: false, limit: -1 }
  })

  showModal.value = true
}

const openEdit = (plan) => {
  isEditing.value = true

  // Clone plan data
  form.value = {
    id: plan.id,
    name: plan.name,
    slug: plan.slug,
    price: plan.price,
    module_configs: {},
  }

  // Pre-fill module configs based on what the plan already has
  // 1. Default all to disabled
  modules.value.forEach((m) => {
    form.value.module_configs[m.id] = { enabled: false, limit: -1 }
  })

  // 2. Overwrite with existing pivot data
  // Assumes plan.modules contains pivot data: { limit: 10, ... }
  if (plan.modules) {
    plan.modules.forEach((m) => {
      form.value.module_configs[m.id] = {
        enabled: true,
        limit: m.pivot.limit, // Accessing pivot data
      }
    })
  }

  showModal.value = true
}

const savePlan = async () => {
  saving.value = true
  try {
    // Transform module_configs into the array format expected by the backend
    // backend expects: modules: [{ id: 1, limit: 10 }, ...]
    const modulesPayload = Object.entries(form.value.module_configs)
      .filter(([_, config]) => config.enabled)
      .map(([id, config]) => ({
        id: parseInt(id),
        limit: parseInt(config.limit),
      }))

    const payload = {
      name: form.value.name,
      slug: form.value.slug,
      price: form.value.price,
      modules: modulesPayload,
    }

    if (isEditing.value) {
      await request.put(`/plans/${form.value.id}`, payload) // Ensure PUT route exists
    } else {
      await request.post('/plans', payload)
    }

    await fetchData()
    showModal.value = false
  } catch (e) {
    alert('Failed to save plan: ' + (e.response?.data?.message || e.message))
  } finally {
    saving.value = false
  }
}

const deletePlan = async (id) => {
  if (!confirm(`Delete plan with id ${id}? This cannot be undone.`)) return

  try {
    await request.delete(`/api/admin/plans/${id}`)
    fetchData()
  } catch (e) {
    // This will show: "Cannot delete this plan because..."
    alert(e.response?.data?.message || `Failed to delete plan with id ${id}`)
  }
}

onMounted(fetchData)
</script>

<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <h2 class="text-xl font-semibold text-slate-900 dark:text-white">Plans & Modules</h2>
      <button
        class="px-3 py-2 text-sm rounded-lg bg-slate-900 text-white hover:bg-slate-800 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-100 transition-colors font-medium"
        @click="openCreate"
      >
        + Create Plan
      </button>
    </div>

    <div v-if="loading" class="text-slate-500 text-center py-10">Loading...</div>

    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      <div
        v-for="plan in plans"
        :key="plan.id"
        class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden flex flex-col shadow-lg hover:shadow-md transition-shadow"
      >
        <div class="p-6 border-b border-slate-100 dark:border-slate-800">
          <div class="flex justify-between items-start mb-2">
            <h3 class="font-bold text-lg text-slate-900 dark:text-white">{{ plan.name }}</h3>
            <span
              class="text-emerald-600 dark:text-emerald-400 font-bold bg-emerald-50 dark:bg-emerald-900/20 px-2 py-1 rounded text-xs"
            >
              ${{ plan.price }}
            </span>
          </div>
          <p class="text-xs text-slate-500 font-mono">{{ plan.slug }}</p>
        </div>

        <div class="p-6 flex-1 bg-slate-50/50 dark:bg-slate-900/50">
          <p class="text-xs font-bold text-slate-400 uppercase mb-3">Enabled Modules</p>
          <ul class="space-y-2">
            <li v-for="mod in plan.modules" :key="mod.id" class="flex justify-between text-sm">
              <span class="text-slate-700 dark:text-slate-300">{{ mod.name }}</span>
              <span class="text-slate-500 text-xs">
                Limit: {{ mod.pivot?.limit === -1 ? 'Unlimited' : mod.pivot?.limit }}
              </span>
            </li>
            <li v-if="!plan.modules?.length" class="text-slate-400 text-xs italic">
              No modules assigned
            </li>
          </ul>
        </div>

        <div class="p-4 border-t border-slate-100 dark:border-slate-800 flex justify-end gap-2">
          <button
            @click="openEdit(plan)"
            class="text-blue-600 hover:text-blue-800 text-xs font-bold uppercase px-3 py-1"
          >
            Edit
          </button>
          <button
            @click="deletePlan(plan.id)"
            class="text-red-600 hover:text-red-800 text-xs font-bold uppercase px-3 py-1"
          >
            Delete
          </button>
        </div>
      </div>
    </div>

    <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div
        class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm"
        @click="showModal = false"
      ></div>

      <div
        class="relative w-full max-w-4xl bg-white dark:bg-slate-950 shadow-2xl rounded-xl border border-slate-200 dark:border-slate-800 flex flex-col max-h-[90vh]"
      >
        <div class="p-6 border-b border-slate-100 dark:border-slate-800">
          <h3 class="text-lg font-bold text-slate-900 dark:text-white">
            {{ isEditing ? 'Edit Plan' : 'Create New Plan' }}
          </h3>
        </div>

        <div class="p-6 overflow-y-auto space-y-8">
          <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div>
              <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Plan Name</label>
              <input
                v-model="form.name"
                type="text"
                placeholder="e.g. Pro Plan"
                class="w-full border rounded-lg px-3 py-2 text-sm dark:bg-slate-900 dark:border-slate-700 dark:text-white outline-none focus:ring-2 focus:ring-slate-500"
              />
            </div>
            <div>
              <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Slug</label>
              <input
                v-model="form.slug"
                type="text"
                placeholder="e.g. pro_monthly"
                class="w-full border rounded-lg px-3 py-2 text-sm dark:bg-slate-900 dark:border-slate-700 dark:text-white outline-none focus:ring-2 focus:ring-slate-500"
              />
            </div>
            <div>
              <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Price ($)</label>
              <input
                v-model="form.price"
                type="number"
                placeholder="0.00"
                class="w-full border rounded-lg px-3 py-2 text-sm dark:bg-slate-900 dark:border-slate-700 dark:text-white outline-none focus:ring-2 focus:ring-slate-500"
              />
            </div>
          </div>

          <div
            class="bg-slate-50 dark:bg-slate-900 p-6 rounded-lg border border-slate-100 dark:border-slate-800"
          >
            <h4 class="text-sm font-bold text-slate-900 dark:text-white mb-4 uppercase">
              Module Allocation & Limits
            </h4>

            <div class="space-y-3">
              <div class="grid grid-cols-12 gap-4 text-xs font-bold text-slate-400 uppercase px-2">
                <div class="col-span-1">Enable</div>
                <div class="col-span-5">Module Name</div>
                <div class="col-span-6">Usage Limit (-1 for Unlimited)</div>
              </div>

              <div
                v-for="mod in modules"
                :key="mod.id"
                class="grid grid-cols-12 gap-4 items-center bg-white dark:bg-slate-950 p-3 rounded border border-slate-200 dark:border-slate-800"
              >
                <div class="col-span-1 flex justify-center">
                  <input
                    type="checkbox"
                    v-model="form.module_configs[mod.id].enabled"
                    class="w-4 h-4 rounded text-blue-600 focus:ring-blue-500 border-gray-300"
                  />
                </div>

                <div class="col-span-5 text-sm font-medium text-slate-700 dark:text-slate-200">
                  {{ mod.name }}
                  <span class="block text-xs font-normal text-slate-400">{{ mod.slug }}</span>
                </div>

                <div class="col-span-6">
                  <input
                    type="number"
                    v-model="form.module_configs[mod.id].limit"
                    :disabled="!form.module_configs[mod.id].enabled"
                    class="w-full border rounded px-3 py-1.5 text-sm dark:bg-slate-900 dark:border-slate-700 dark:text-white disabled:opacity-50 disabled:bg-slate-100 dark:disabled:bg-slate-800"
                    placeholder="-1"
                  />
                </div>
              </div>
            </div>
          </div>
        </div>

        <div
          class="flex justify-end gap-3 p-6 border-t border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-900/50 rounded-b-xl"
        >
          <button
            class="px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 rounded-lg dark:text-slate-300 dark:hover:bg-slate-800 transition-colors"
            @click="showModal = false"
          >
            Cancel
          </button>
          <button
            class="px-4 py-2 text-sm font-medium text-white bg-slate-900 hover:bg-slate-800 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-100 rounded-lg shadow-lg transition-colors"
            :disabled="saving"
            @click="savePlan"
          >
            {{ saving ? 'Saving...' : isEditing ? 'Update Plan' : 'Create Plan' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
