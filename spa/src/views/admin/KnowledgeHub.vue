<script setup>
import { onMounted, ref } from 'vue'
import api from '@/utils/request'
import { Dialog, DialogPanel, DialogTitle, TransitionRoot } from '@headlessui/vue'

const sources = ref([])
const loading = ref(false)
const showModal = ref(false)
const processing = ref(false)

const form = ref({
  title: '',
  source_type: 'document',
  source_url: '',
  content: '',
  file: null,
})

const fetchSources = async () => {
  loading.value = true
  try {
    const { data } = await api.get('/knowledge-sources')
    sources.value = data.sources || []
    // console.log(sources.value)
  } catch (e) {
    console.error('Failed to load knowledge sources:', e)
  } finally {
    loading.value = false
  }
}

const openCreateModal = () => {
  form.value = {
    title: '',
    source_type: 'document',
    source_url: '',
    content: '',
    file: null,
  }
  showModal.value = true
}

const handleFileSelect = (event) => {
  const file = event.target.files[0]
  if (file) {
    form.value.file = file
    if (!form.value.title) {
      form.value.title = file.name.split('.').slice(0, -1).join('.')
    }
  }
}

const saveSource = async () => {
  if (!form.value.title) return
  
  processing.value = true
  try {
    let payload = form.value
    let headers = {}
    
    if (form.value.source_type === 'document' && form.value.file) {
      payload = new FormData()
      payload.append('title', form.value.title)
      payload.append('source_type', form.value.source_type)
      payload.append('file', form.value.file)
      headers = { 'Content-Type': 'multipart/form-data' }
    }
    
    const { data } = await api.post('/knowledge-sources', payload, { headers })
    sources.value.unshift(data.source)
    showModal.value = false
  } catch (e) {
    alert('Failed to save source: ' + (e.response?.data?.message || e.message))
  } finally {
    processing.value = false
  }
}

const toggleStatus = async (source) => {
  try {
    const { data } = await api.post(`/knowledge-sources/${source.id}/toggle-status`)
    const index = sources.value.findIndex(s => s.id === source.id)
    if (index !== -1) sources.value[index] = data.source
  } catch (e) {
    alert('Failed to toggle status')
  }
}

const deleteSource = async (source) => {
  if (!confirm(`Delete knowledge source "${source.title}"?`)) return
  try {
    await api.delete(`/knowledge-sources/${source.id}`)
    sources.value = sources.value.filter(s => s.id !== source.id)
  } catch (e) {
    alert('Failed to delete source')
  }
}

onMounted(fetchSources)
</script>

<template>
  <div class="space-y-6">
    <div class="page-header">
      <div>
        <h2 class="page-title">Knowledge Hub</h2>
        <p class="page-subtitle">Global repository for AI Agent knowledge and memories.</p>
      </div>
      <button @click="openCreateModal" class="btn-primary">+ Add Source</button>
    </div>

    <div v-if="loading" class="text-center py-12 text-slate-500">Loading...</div>

    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      <div v-if="sources.length === 0" class="col-span-full text-center py-12 text-slate-500 italic border border-dashed border-slate-300 dark:border-slate-700 rounded-xl">
        No knowledge sources found. Add your first document or URL.
      </div>
      
      <div v-for="source in sources" :key="source.id" class="bg-white dark:bg-slate-900 rounded-xl shadow-sm border border-slate-200 dark:border-slate-800 p-5 flex flex-col hover:shadow-md transition-shadow">
        <div class="flex justify-between items-start mb-4">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center text-blue-600 dark:text-blue-400">
              <svg v-if="source.source_type === 'document'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>
              <svg v-else-if="source.source_type === 'url'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" /></svg>
              <svg v-else class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
            </div>
            <div>
              <h3 class="font-bold text-slate-900 dark:text-white line-clamp-1" :title="source.title">{{ source.title }}</h3>
              <span class="text-xs text-slate-500 capitalize">{{ source.source_type }}</span>
            </div>
          </div>
          
          <button @click="toggleStatus(source)" :class="source.is_active ? 'text-emerald-500' : 'text-slate-400'" title="Toggle Active Status">
            <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20">
              <path v-if="source.is_active" fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
              <path v-else fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
            </svg>
          </button>
        </div>
        
        <div class="mt-auto pt-4 border-t border-slate-100 dark:border-slate-800 flex justify-between items-center">
          <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium" :class="{
            'bg-yellow-100 text-yellow-800': source.status === 'pending',
            'bg-blue-100 text-blue-800': source.status === 'processing',
            'bg-emerald-100 text-emerald-800': source.status === 'indexed',
            'bg-red-100 text-red-800': source.status === 'failed'
          }">
            {{ source.status }}
          </span>
          
          <button @click="deleteSource(source)" class="text-xs text-red-500 hover:text-red-700 uppercase font-bold transition-colors">
            Delete
          </button>
        </div>
      </div>
    </div>

    <!-- Create Modal -->
    <TransitionRoot appear :show="showModal" as="template">
      <Dialog as="div" @close="showModal = false" class="relative z-50">
        <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" />
        <div class="fixed inset-0 overflow-y-auto flex items-center justify-center p-4">
          <DialogPanel class="w-full max-w-lg bg-white dark:bg-slate-900 rounded-xl shadow-xl p-6 border border-slate-200 dark:border-slate-800">
            <DialogTitle class="text-lg font-bold mb-4 text-slate-900 dark:text-white">
              Add Knowledge Source
            </DialogTitle>

            <div class="space-y-4">
              <div class="form-group">
                <label class="form-label">Title</label>
                <input v-model="form.title" class="form-input" placeholder="e.g. Refund Policy 2026" />
              </div>

              <div class="form-group">
                <label class="form-label">Source Type</label>
                <select v-model="form.source_type" class="form-input">
                  <option value="document">Document (Raw Text)</option>
                  <option value="url">Website URL</option>
                  <option value="manual_note">Manual Note</option>
                </select>
              </div>

              <div v-if="form.source_type === 'url'" class="form-group">
                <label class="form-label">URL</label>
                <input v-model="form.source_url" type="url" class="form-input" placeholder="https://example.com/docs" />
              </div>

              <div v-else class="form-group">
                <label class="form-label">Upload Document (.pdf, .doc, .docx, .md, .txt)</label>
                <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-slate-300 dark:border-slate-700 border-dashed rounded-lg hover:border-indigo-500 dark:hover:border-indigo-400 transition-colors">
                  <div class="space-y-1 text-center">
                    <svg class="mx-auto h-12 w-12 text-slate-400" stroke="currentColor" fill="none" viewBox="0 0 48 48" aria-hidden="true">
                      <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <div class="flex text-sm text-slate-600 dark:text-slate-400 justify-center">
                      <label for="file-upload" class="relative cursor-pointer bg-white dark:bg-slate-900 rounded-md font-medium text-indigo-600 dark:text-indigo-400 hover:text-indigo-500 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-indigo-500">
                        <span>Upload a file</span>
                        <input id="file-upload" name="file-upload" type="file" class="sr-only" accept=".pdf,.doc,.docx,.txt,.md" @change="handleFileSelect">
                      </label>
                      <p class="pl-1">or drag and drop</p>
                    </div>
                    <p class="text-xs text-slate-500">
                      {{ form.file ? form.file.name : 'PDF, DOCX, TXT, MD up to 10MB' }}
                    </p>
                  </div>
                </div>
                
                <div class="mt-4 text-center text-sm font-medium text-slate-500">OR</div>
                <div class="mt-4">
                    <label class="form-label">Paste Content (Markdown / Text)</label>
                    <textarea v-model="form.content" rows="4" class="form-input" placeholder="Alternatively, paste document contents here..."></textarea>
                </div>
              </div>
            </div>

            <div class="mt-6 flex justify-end gap-3 pt-4 border-t border-slate-100 dark:border-slate-800">
              <button @click="showModal = false" class="btn-secondary">Cancel</button>
              <button @click="saveSource" :disabled="processing" class="btn-primary">
                {{ processing ? 'Saving...' : 'Save & Index' }}
              </button>
            </div>
          </DialogPanel>
        </div>
      </Dialog>
    </TransitionRoot>
  </div>
</template>
