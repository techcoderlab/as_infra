<script setup>
import { onMounted, ref } from 'vue'
import { Menu, MenuButton, MenuItems, MenuItem } from '@headlessui/vue'
import api from '../../utils/request'
import FormBuilder from '../../components/FormBuilder.vue'
import CopyButton from '../../components/ui/CopyButton.vue'

const forms = ref([])
const loading = ref(false)
const showBuilder = ref(false)
const editingForm = ref(null)
const showPayload = ref(false)
const payloadForm = ref(null)
const fetchForms = async () => {
  loading.value = true
  try {
    const { data } = await api.get('/forms')
    forms.value = data
    // console.log(data)
  } catch (e) {
    console.error(e)
  } finally {
    loading.value = false
  }
}
const editForm = (f) => {
  editingForm.value = f
  showBuilder.value = true
}
const newForm = () => {
  editingForm.value = null
  showBuilder.value = true
}
const deleteForm = async (f) => {
  if (!confirm(`Delete ${f.name}?`)) return
  await api.delete(`/forms/${f.id}`)
  fetchForms()
}
const viewPayload = (f) => {
  const ex = {}
  ;(f.schema || []).forEach(
    (x) => (ex[x.name || 'field'] = x.type === 'checkbox-group' || x.multiple ? [] : ''),
  )
  payloadForm.value = { name: f.name, example: ex }
  showPayload.value = true
}
const getPublicLink = (form) => {
  // return `${window.location.origin}/form/${form.id}`
  return form.form_source == 'system'
    ? `${window.location.origin}/form/${form.id}`
    : form.form_public_url
}
const handleSaved = () => {
  showBuilder.value = false
  fetchForms()
}
onMounted(fetchForms)
</script>

<template>
  <div>
    <div v-if="!showBuilder" class="space-y-6">
      <div class="page-header">
        <div>
          <h2 class="page-title">Forms</h2>
          <p class="page-subtitle">Manage your data collection endpoints.</p>
        </div>
        <button @click="newForm" class="btn-primary">+ Add Form</button>
      </div>

      <div v-if="loading" class="text-center py-12 text-slate-500">Loading...</div>

      <div v-else class="table-container">
        <table class="table">
          <thead>
            <tr>
              <th>Details</th>
              <th>Status</th>
              <th>Webhooks</th>
              <th class="text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="form in forms" :key="form.id">
              <td>
                <div class="font-bold text-slate-900 dark:text-white">
                  {{ form.name }}
                  <span class="text-red-500">
                    {{
                      form.form_source !== 'system' ? '(Built using ' + form.form_source + ')' : ''
                    }}</span
                  >
                </div>
                <div class="flex items-center gap-2 mt-1">
                  <span class="text-xs font-mono text-slate-500">{{ form.id }}</span
                  ><CopyButton :text="form.id" />
                </div>
              </td>
              <td>
                <span :class="['badge', form.is_active ? 'badge-green' : 'badge-slate']">{{
                  form.is_active ? 'Active' : 'Inactive'
                }}</span>
              </td>
              <td>
                <!-- ({{ form.webhooks.filter( wh => wh.is_active === true).length ?? '0' }} Active) -->
                <div v-if="form.webhooks" class="flex gap-2 items-center">
                  <span class="text-xs font-mono text-slate-500 truncate max-w-[200px]"
                    >{{ form.webhooks.length ?? '0' }} Linked</span
                  >
                </div>
                <span v-else class="text-xs text-slate-400 italic">0 Linked</span>
              </td>
              <td>
                <div class="flex justify-end">
                  <a
                    :href="getPublicLink(form)"
                    target="_blank"
                    class="p-2 text-slate-400 hover:text-blue-600 transition-colors"
                    title="View Live Form"
                  >
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"
                      />
                    </svg>
                  </a>
                  <Menu as="div" class="relative inline-block text-left">
                    <MenuButton class="btn-icon"
                      ><svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
                        <path
                          d="M12 13a1 1 0 100-2 1 1 0 000 2zm0-5a1 1 0 100-2 1 1 0 000 2zm0 10a1 1 0 100-2 1 1 0 000 2z"
                        /></svg
                    ></MenuButton>
                    <MenuItems
                      class="fixed right-10 mt-2 w-48 rounded-lg bg-white dark:bg-slate-900 shadow-lg border border-slate-100 dark:border-slate-800 z-100 focus:outline-none"
                    >
                      <MenuItem v-slot="{ active }"
                        ><button
                          @click="viewPayload(form)"
                          :class="[
                            active ? 'bg-slate-50 dark:bg-slate-800' : '',
                            'block w-full text-left px-4 py-2 text-sm text-slate-700 dark:text-slate-300',
                          ]"
                        >
                          View Payload
                        </button></MenuItem
                      >
                      <MenuItem v-slot="{ active }"
                        ><button
                          @click="editForm(form)"
                          :class="[
                            active ? 'bg-slate-50 dark:bg-slate-800' : '',
                            'block w-full text-left px-4 py-2 text-sm text-slate-700 dark:text-slate-300',
                          ]"
                        >
                          Edit
                        </button></MenuItem
                      >
                      <MenuItem v-slot="{ active }"
                        ><button
                          @click="deleteForm(form)"
                          :class="[
                            active ? 'bg-red-50 dark:bg-red-900/20' : '',
                            'block w-full text-left px-4 py-2 text-sm text-red-600',
                          ]"
                        >
                          Delete
                        </button></MenuItem
                      >
                    </MenuItems>
                  </Menu>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div
        v-if="showPayload"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm"
      >
        <div class="card max-w-lg w-full">
          <div class="card-header"><span class="card-title">Payload Preview</span></div>
          <div class="card-body">
            <pre
              class="bg-slate-50 dark:bg-slate-950 p-4 rounded text-xs font-mono overflow-auto max-h-[60vh] text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-800"
              >{{ JSON.stringify(payloadForm.example, null, 2) }}</pre
            >
            <div class="mt-4 flex justify-end">
              <button @click="showPayload = false" class="btn-secondary">Close</button>
            </div>
          </div>
        </div>
      </div>
    </div>
    <FormBuilder v-else :form="editingForm" @saved="handleSaved" @cancel="showBuilder = false" />
  </div>
</template>
