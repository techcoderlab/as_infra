<script setup>
import { ref, watch, computed } from 'vue'
import api from '../utils/request'

const props = defineProps({
  form: {
    type: Object,
    default: null,
  },
})

const emit = defineEmits(['saved', 'cancel'])

// State
const name = ref(props.form?.name || '')
const schemaText = ref(JSON.stringify(props.form?.schema || [], null, 2)) // Default to empty array
const fieldsNeededText = ref(JSON.stringify(props.form?.fields_needed || {}, null, 2))
const isActive = ref(props.form?.is_active ?? true)
const formSource = ref(props.form?.form_source || 'system')
const publicFormUrl = ref(props.form?.form_public_url || '')
const refFormId = ref(props.form?.ref_form_id || '')
const error = ref('')
const loading = ref(false)

// Computed
const isSystemForm = computed(() => formSource.value === 'system')

const parsedSchema = computed(() => {
  try {
    return JSON.parse(schemaText.value)
  } catch {
    return null
  }
})

const parsedFieldsNeeded = computed(() => {
  try {
    const val = JSON.parse(fieldsNeededText.value)
    // Must be an object or null, not array
    if (val && typeof val === 'object' && !Array.isArray(val)) {
      return val
    }
    // Allow empty/null
    if (!val) return null
    return undefined // Error state
  } catch {
    return undefined // Error state
  }
})

// Watchers

// 1. Initialize from props
watch(
  () => props.form,
  (form) => {
    name.value = form?.name || ''
    schemaText.value = JSON.stringify(form?.schema || [], null, 2)
    isActive.value = form?.is_active ?? true
    formSource.value = form?.form_source || 'system'
    refFormId.value = form?.ref_form_id || ''
    publicFormUrl.value = form?.form_public_url || ''
    fieldsNeededText.value = JSON.stringify(form?.fields_needed || {}, null, 2)
  },
)

// 2. Enforce: If Source is System, URL must be empty
watch(formSource, (newSource) => {
  if (newSource === 'system') {
    publicFormUrl.value = ''
  }
})

// 3. Enforce: If User enters URL, Source must not be System
watch(publicFormUrl, (newUrl) => {
  if (newUrl && newUrl.length > 0 && formSource.value === 'system') {
    // Auto-switch to tally as default external if user starts typing URL
    formSource.value = 'tally'
  }
})

// Actions
const save = async () => {
  error.value = ''

  // 1. Common Validation
  if (!name.value.trim()) {
    error.value = 'Form name is required'
    return
  }

  let finalSchema = []

  // 2. Specific Validation based on Source
  if (isSystemForm.value) {
    // System Form: Must have valid JSON schema
    if (!parsedSchema.value) {
      error.value = 'Invalid JSON Schema'
      return
    }
    finalSchema = parsedSchema.value
  } else {
    // External Form: Must have URL
    if (!publicFormUrl.value.trim()) {
      error.value = 'Public Form URL is required for external sources'
      return
    }
    if (!refFormId.value.trim()) {
      error.value = 'Reference Form ID is required for external sources'
      return
    }

    // FIX: Send an empty Object '{}' instead of Array '[]'.
    // Laravel validation 'required' fails on [], but passes on {}.
    finalSchema = null
  }

  loading.value = true
  try {
    const payload = {
      name: name.value,
      schema: finalSchema,
      is_active: isActive.value,
      form_source: formSource.value,
      ref_form_id: refFormId.value,
      // Send empty string if system form to ensure DB clears the old URL
      form_public_url: isSystemForm.value ? '' : publicFormUrl.value,
      fields_needed: parsedFieldsNeeded.value,
    }

    if (props.form?.id) {
      await api.put(`/forms/${props.form.id}`, payload)
    } else {
      await api.post('/forms', payload)
    }

    emit('saved')
  } catch (e) {
    error.value = e.response?.data?.message || 'Failed to save form'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <div class="flex items-center gap-3">
          <button
            @click="emit('cancel')"
            class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors"
          >
            <svg
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
              stroke-width="2"
              stroke="currentColor"
              class="w-5 h-5"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"
              />
            </svg>
          </button>
          <h2 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
            {{ form ? 'Edit Form' : 'Create Form' }}
          </h2>
        </div>
        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1 ml-8">
          Configure your form schema and integration settings.
        </p>
      </div>

      <div class="flex items-center gap-3">
        <span v-if="error" class="text-sm font-medium text-red-600 mr-2">{{ error }}</span>
        <button
          @click="emit('cancel')"
          class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-medium dark:text-black bg-white border hover:bg-slate-50 transition-colors"
        >
          Cancel
        </button>
        <button
          @click="save"
          :disabled="loading"
          class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-medium bg-slate-100 dark:bg-slate-800 text-slate-900 dark:text-slate-100 hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
        >
          <span v-if="loading" class="mr-2">
            <svg
              class="animate-spin h-4 w-4 text-white"
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
            >
              <circle
                class="opacity-25"
                cx="12"
                cy="12"
                r="10"
                stroke="currentColor"
                stroke-width="4"
              ></circle>
              <path
                class="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
              ></path>
            </svg>
          </span>
          {{ loading ? 'Saving...' : 'Save Changes' }}
        </button>
      </div>
    </div>

    <!-- Configuration (Full Width) -->
    <div
      class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 shadow-lg overflow-hidden"
    >
      <div
        class="p-4 border-b border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-900/50 font-semibold text-slate-700 dark:text-slate-300"
      >
        Configuration
      </div>

      <div class="p-6 grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="md:col-span-3">
          <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Form Name</label>
          <input
            v-model="name"
            type="text"
            class="w-full border border-slate-300 dark:border-slate-700 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white dark:bg-slate-900 text-slate-900 dark:text-white placeholder-slate-400 transition-shadow"
            placeholder="e.g. Contact Us"
          />
        </div>

        <div>
          <label class="block text-xs font-bold text-slate-500 uppercase mb-2"> Form Source </label>
          <select
            v-model="formSource"
            class="w-full border border-slate-300 dark:border-slate-700 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white dark:bg-slate-900 text-slate-900 dark:text-white transition-shadow"
          >
            <option value="system">System (Built-in)</option>
            <option value="tally">Tally.so</option>
            <option value="typeform">Typeform</option>
            <option value="wordpressform">WordPress</option>
          </select>
        </div>

        <div>
          <label class="block text-xs font-bold text-slate-500 uppercase mb-2">
            {{ formSource !== 'system' ? formSource.toUpperCase() : 'Reference' }} Form Id
            <span class="text-[10px] font-normal text-slate-400 normal-case ml-1"
              >(Required if not System)</span
            >
          </label>
          <input
            v-model="refFormId"
            type="text"
            :disabled="isSystemForm && !refFormId"
            :placeholder="
              isSystemForm ? 'Switch source to enter form ID' : 'Enter reference form id here'
            "
            class="w-full border border-slate-300 dark:border-slate-700 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white dark:bg-slate-900 text-slate-900 dark:text-white placeholder-slate-400 transition-shadow disabled:bg-slate-100 disabled:dark:bg-slate-800 disabled:cursor-not-allowed"
          />
        </div>
        <div>
          <label class="block text-xs font-bold text-slate-500 uppercase mb-2">
            Form Url
            <span class="text-[10px] font-normal text-slate-400 normal-case ml-1"
              >(Required if not System)</span
            >
          </label>
          <input
            v-model="publicFormUrl"
            type="url"
            :disabled="isSystemForm && !publicFormUrl"
            :placeholder="isSystemForm ? 'Switch source to enter URL' : 'https://tally.so/r/.....'"
            class="w-full border border-slate-300 dark:border-slate-700 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white dark:bg-slate-900 text-slate-900 dark:text-white placeholder-slate-400 transition-shadow disabled:bg-slate-100 disabled:dark:bg-slate-800 disabled:cursor-not-allowed"
          />
        </div>

        <div class="md:col-span-2 pt-2">
          <label class="inline-flex items-center cursor-pointer">
            <input type="checkbox" v-model="isActive" class="sr-only peer" />
            <div
              class="relative w-11 h-6 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 rounded-full peer dark:bg-slate-700 peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-blue-600"
            ></div>
            <span class="ms-3 text-sm font-medium text-slate-700 dark:text-slate-300"
              >Form is Active</span
            >
          </label>
        </div>

        <div
          v-if="!isSystemForm"
          class="md:col-span-3 pt-4 border-t border-slate-200 dark:border-slate-800"
        >
          <label class="block text-xs font-bold text-slate-500 uppercase mb-2">
            Field Mapping (Optional)
            <span
              class="ml-2 text-[10px] px-2 py-0.5 rounded-full uppercase tracking-wider font-bold"
              :class="
                parsedFieldsNeeded !== undefined
                  ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'
                  : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
              "
            >
              {{ parsedFieldsNeeded !== undefined ? 'Valid JSON Object' : 'Invalid JSON' }}
            </span>
          </label>
          <div class="text-[11px] text-slate-400 mb-2">
            Map incoming fields to readable labels. If provided, only these keys will be accepted.
            Format: <code>{"key": "Label"}</code>
          </div>
          <textarea
            v-model="fieldsNeededText"
            rows="15"
            class="w-full font-mono text-xs border border-slate-300 dark:border-slate-700 rounded-lg p-2.5 focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white dark:bg-slate-900 text-slate-900 dark:text-white placeholder-slate-400 transition-shadow"
            placeholder='{
  "full_name": "Name",
  "email": "Email Address"
}'
          ></textarea>
        </div>
      </div>
    </div>

    <!-- Main Content Grid: Schema & Preview -->
    <div v-if="isSystemForm" class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <!-- Left Column: Schema Definition -->
      <div
        class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 shadow-lg overflow-hidden flex flex-col h-[600px]"
      >
        <div
          class="p-4 border-b border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-900/50 font-semibold text-slate-700 dark:text-slate-300 flex justify-between items-center"
        >
          <span>Schema Definition</span>
          <span
            class="text-[10px] px-2 py-0.5 rounded-full uppercase tracking-wider font-bold"
            :class="
              parsedSchema
                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'
                : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
            "
          >
            {{ parsedSchema ? 'Valid JSON' : 'Invalid Syntax' }}
          </span>
        </div>
        <div class="flex-1 relative">
          <textarea
            v-model="schemaText"
            class="absolute inset-0 w-full h-full p-4 font-mono text-xs leading-relaxed text-slate-600 dark:text-slate-300 bg-slate-50 dark:bg-slate-900 focus:ring-0 border-0 outline-none resize-none"
            spellcheck="false"
            placeholder='[{"type": "text", "name": "field", "label": "Label"}]'
          ></textarea>
        </div>
      </div>

      <!-- Right Column: Live Preview -->
      <div
        class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 shadow-lg overflow-hidden h-[600px] flex flex-col"
      >
        <div
          class="p-4 border-b border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-900/50 flex justify-between items-center shrink-0"
        >
          <span class="font-semibold text-slate-700 dark:text-slate-300">Live Preview</span>
          <div class="flex gap-1.5">
            <div class="w-2.5 h-2.5 rounded-full bg-slate-200 dark:bg-slate-700"></div>
            <div class="w-2.5 h-2.5 rounded-full bg-slate-200 dark:bg-slate-700"></div>
          </div>
        </div>

        <div class="flex-1 overflow-y-auto p-8 bg-slate-100 dark:bg-slate-900">
          <div
            class="max-w-md mx-auto bg-white dark:bg-slate-950 rounded-xl shadow-lg border border-slate-200 dark:border-slate-800 overflow-hidden"
          >
            <div class="p-8 space-y-6">
              <div class="text-center space-y-2 mb-8">
                <div
                  class="h-12 w-12 bg-blue-50 text-blue-600 rounded-xl mx-auto flex items-center justify-center mb-4"
                >
                  <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      stroke-width="2"
                      d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                    />
                  </svg>
                </div>
                <h3 class="text-xl font-bold text-slate-900 dark:text-white">
                  {{ name || 'Untitled Form' }}
                </h3>
                <p class="text-xs text-slate-500">Preview of how your form will appear to users.</p>
              </div>

              <div
                v-if="!parsedSchema"
                class="p-4 text-center text-sm text-red-600 bg-red-50 dark:bg-red-900/20 rounded-lg border border-red-100 dark:border-red-800"
              >
                Fix the JSON schema to see the preview.
              </div>

              <div
                v-else-if="parsedSchema.length === 0"
                class="p-8 text-center text-sm text-slate-400 border-2 border-dashed border-slate-200 dark:border-slate-800 rounded-lg"
              >
                Add fields to the schema definition.
              </div>

              <form v-else class="space-y-5" @submit.prevent>
                <div v-for="(field, idx) in parsedSchema" :key="idx" class="space-y-1.5">
                  <label class="block text-sm font-medium text-slate-700 dark:text-slate-300">
                    {{ field.label || field.name }}
                    <span v-if="field.required" class="text-red-500">*</span>
                  </label>

                  <input
                    v-if="['text', 'email', 'number', 'tel', 'url'].includes(field.type || 'text')"
                    :type="field.type || 'text'"
                    :placeholder="field.placeholder"
                    class="block w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2 text-sm shadow-lg focus:border-blue-500 focus:ring-1 focus:ring-blue-500 dark:text-white disabled:opacity-60 disabled:cursor-not-allowed"
                    disabled
                  />

                  <textarea
                    v-else-if="field.type === 'textarea'"
                    :placeholder="field.placeholder"
                    class="block w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2 text-sm shadow-lg focus:border-blue-500 focus:ring-1 focus:ring-blue-500 dark:text-white disabled:opacity-60 disabled:cursor-not-allowed resize-none"
                    rows="3"
                    disabled
                  ></textarea>

                  <select
                    v-else-if="field.type === 'select'"
                    class="block w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2 text-sm shadow-lg focus:border-blue-500 focus:ring-1 focus:ring-blue-500 dark:text-white disabled:opacity-60 disabled:cursor-not-allowed"
                    disabled
                  >
                    <option v-if="field.placeholder" value="">{{ field.placeholder }}</option>
                    <option v-for="opt in field.options" :key="opt.value" :value="opt.value">
                      {{ opt.label }}
                    </option>
                  </select>

                  <div v-else-if="field.type === 'checkbox-group'" class="space-y-2 pt-1">
                    <div
                      v-for="opt in field.options"
                      :key="opt.value"
                      class="flex items-center gap-2"
                    >
                      <input
                        type="checkbox"
                        disabled
                        class="rounded border-slate-300 text-blue-600 dark:border-slate-700 bg-white dark:bg-slate-900 disabled:opacity-60"
                      />
                      <span class="text-sm text-slate-600 dark:text-slate-400">{{
                        opt.label
                      }}</span>
                    </div>
                  </div>

                  <div v-else-if="field.type === 'radio-group'" class="space-y-2 pt-1">
                    <div
                      v-for="opt in field.options"
                      :key="opt.value"
                      class="flex items-center gap-2"
                    >
                      <input
                        type="radio"
                        disabled
                        class="border-slate-300 text-blue-600 dark:border-slate-700 bg-white dark:bg-slate-900 disabled:opacity-60"
                      />
                      <span class="text-sm text-slate-600 dark:text-slate-400">{{
                        opt.label
                      }}</span>
                    </div>
                  </div>

                  <div
                    v-else
                    class="text-xs text-red-500 bg-red-50 p-2 rounded border border-red-100"
                  >
                    Unsupported field type: <strong>{{ field.type }}</strong>
                  </div>
                </div>

                <div class="pt-4">
                  <button
                    disabled
                    class="w-full flex justify-center py-2 px-4 border border-transparent rounded-lg shadow-lg text-sm font-medium text-white bg-blue-600 opacity-50 cursor-not-allowed"
                  >
                    Submit Form
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
