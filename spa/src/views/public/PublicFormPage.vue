<script setup>
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { z } from 'zod'
import api from '../../utils/request'
import PublicFormRenderer from '../../components/PublicFormRenderer.vue'

const route = useRoute()

const loading = ref(true)
const submitting = ref(false)
const form = ref(null)
const values = ref({})
const successMessage = ref('')
const errorMessage = ref('')
const fieldErrors = ref({})
const showEmbed = ref(false)
const renderer = ref(null)

const fetchForm = async () => {
  // 1. Set the anchor time at the very start of script execution
  window.formLoadTime = Date.now()

  loading.value = true
  errorMessage.value = ''
  try {
    const { data } = await api.get(`/public/form/${route.params.uuid}`)
    form.value = data
    values.value = {}
  } catch (e) {
    // Handle 404 specifically
    if (e.response && e.response.status === 404) {
      errorMessage.value = '404'
    } else {
      errorMessage.value = e.response?.data?.message || 'Unable to load form.'
    }
  } finally {
    loading.value = false
  }
}

const submit = async () => {
  submitting.value = true
  successMessage.value = ''
  errorMessage.value = ''
  fieldErrors.value = {}

  // 🔥 Trigger renderer internal validation FIRST
  const isRendererValid = renderer.value?.runValidation()

  // If there are any client-side validation errors, stop now
  if (isRendererValid === false) {
    submitting.value = false
    window.scrollTo({ top: 0, behavior: 'smooth' })
    return
  }

  if (form.value?.schema) {
    try {
      const shape = {}
      ;(form.value.schema || []).forEach((field) => {
        if (!field.name) return
        let schema = z.any()

        if (['text', 'textarea'].includes(field.type)) schema = z.string()
        else if (field.type === 'email') schema = z.string().email()
        else if (field.type === 'number' || field.type === 'range') schema = z.coerce.number()
        else if (field.type === 'select' && field.multiple) schema = z.array(z.string())
        else if (field.type === 'checkbox-group') schema = z.array(z.string())
        else schema = z.any()

        if (field.required) {
          if (field.type === 'checkbox-group' || (field.type === 'select' && field.multiple)) {
            schema = schema.min(1, 'At least one selection is required.')
          } else {
            schema = schema.refine((val) => val !== null && val !== undefined && val !== '', {
              message: 'This field is required.',
            })
          }
        } else {
          schema = schema.optional()
        }
        shape[field.name] = schema
      })

      const validator = z.object(shape)
      validator.parse(values.value)
    } catch (e) {
      if (e instanceof z.ZodError) {
        const errors = {}
        e.issues.forEach((issue) => {
          errors[issue.path[0]] = issue.message
        })
        fieldErrors.value = errors
        submitting.value = false
        window.scrollTo({ top: 0, behavior: 'smooth' })
        return
      }
    }
  }

  try {
    // i want to add ms_since_load to form data
    values.value.ms_since_load = Math.floor(Date.now() - window.formLoadTime)

    await api.post(`/public/form/${route.params.uuid}/submit`, values.value)
    successMessage.value = 'Thank you! Your submission has been received.'
    values.value = {}
    window.scrollTo({ top: 0, behavior: 'smooth' })
  } catch (e) {
    errorMessage.value = e.response?.data?.message || 'Submission failed. Please try again.'
  } finally {
    submitting.value = false
  }
}

const copyEmbedCode = () => {
  const code = `<iframe src="${window.location.href}" width="100%" height="600px" frameborder="0" style="border:0; width:100%; overflow:hidden;"></iframe>`
  navigator.clipboard.writeText(code)
  alert('Embed code copied!')
}

onMounted(fetchForm)
</script>

<template>
  <div
    class="min-h-screen bg-slate-50 flex flex-col items-center py-12 px-4 sm:px-6 lg:px-8 font-sans text-slate-900"
  >
    <div v-if="loading" class="flex flex-col items-center justify-center pt-20 space-y-4">
      <div
        class="h-10 w-10 border-4 border-blue-600 border-t-transparent rounded-full animate-spin"
      ></div>
      <p class="text-slate-500 font-medium animate-pulse">Loading form...</p>
    </div>

    <div
      v-else-if="errorMessage === '404' || !form"
      class="max-w-md w-full bg-white rounded-2xl shadow-lg border border-slate-100 p-8 text-center"
    >
      <div
        class="h-16 w-16 bg-red-50 text-red-500 rounded-full flex items-center justify-center mx-auto mb-4"
      >
        <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
          />
        </svg>
      </div>
      <h2 class="text-2xl font-bold text-slate-900 mb-2">Form Unavailable</h2>
      <p class="text-slate-500">This form is either inactive or does not exist.</p>
    </div>

    <div v-else class="w-full max-w-xl transition-all duration-500 ease-in-out">
      <div class="text-center mb-8">
        <h1 class="text-3xl font-bold tracking-tight text-slate-900">{{ form.name }}</h1>
        <p class="mt-2 text-slate-500">Please fill out the details below.</p>
      </div>

      <div
        class="bg-white shadow-2xl shadow-slate-200/50 rounded-2xl border border-slate-100 overflow-hidden relative"
      >
        <div
          v-if="successMessage"
          class="bg-emerald-50 p-6 text-center border-b border-emerald-100"
        >
          <div
            class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-emerald-100 mb-3"
          >
            <svg
              class="h-6 w-6 text-emerald-600"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M5 13l4 4L19 7"
              />
            </svg>
          </div>
          <h3 class="text-lg font-medium text-emerald-900">Success!</h3>
          <p class="text-emerald-700 mt-1">{{ successMessage }}</p>
        </div>

        <div
          v-if="errorMessage && errorMessage !== '404'"
          class="bg-red-50 p-4 border-l-4 border-red-500 flex items-start gap-3 mx-6 mt-6 rounded-r-md"
        >
          <svg
            class="h-5 w-5 text-red-500 shrink-0 mt-0.5"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
            />
          </svg>
          <p class="text-sm text-red-700 font-medium">{{ errorMessage }}</p>
        </div>

        <div class="p-8 space-y-6">
          <PublicFormRenderer
            ref="renderer"
            v-model="values"
            :schema="form.schema"
            :errors="fieldErrors"
          />

          <div class="pt-4">
            <button
              class="group relative w-full flex justify-center py-3.5 px-4 border border-transparent text-sm font-semibold rounded-xl text-white bg-blue-500 hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-all shadow-lg shadow-blue-600/30 hover:shadow-blue-600/50 disabled:opacity-70 disabled:cursor-not-allowed disabled:shadow-none"
              :disabled="submitting"
              @click="submit"
            >
              <span v-if="submitting" class="absolute left-4 inset-y-0 flex items-center">
                <svg class="animate-spin h-5 w-5 text-blue-200" fill="none" viewBox="0 0 24 24">
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
              {{ submitting ? 'Processing...' : 'Submit Form' }}
            </button>
          </div>
        </div>
      </div>

      <div class="mt-8 text-center">
        <button
          @click="showEmbed = !showEmbed"
          class="text-xs font-medium text-slate-400 hover:text-blue-600 transition-colors inline-flex items-center gap-1.5"
        >
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"
            />
          </svg>
          Embed this form
        </button>

        <div
          v-if="showEmbed"
          class="mt-4 bg-white p-4 rounded-xl border border-slate-200 shadow-lg text-left animate-in fade-in slide-in-from-bottom-2"
        >
          <label class="text-xs font-bold text-slate-700 uppercase tracking-wide">Copy Code</label>
          <div class="mt-2 flex gap-2">
            <input
              readonly
              :value="`<iframe src='${typeof window !== 'undefined' ? window.location.href : ''}' width='100%' height='600px' frameborder='0'></iframe>`"
              class="flex-1 bg-slate-50 border border-slate-300 rounded-lg px-3 py-2 text-xs font-mono text-slate-600 focus:outline-none focus:border-blue-500"
            />
            <button
              @click="copyEmbedCode"
              class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-xs font-semibold transition-colors"
            >
              Copy
            </button>
          </div>
        </div>

        <p class="mt-6 text-xs text-slate-300">Powered by Agency SaaS</p>
      </div>
    </div>
  </div>
</template>
