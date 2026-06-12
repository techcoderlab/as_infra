<script setup>
    import { onMounted, ref, reactive, computed } from 'vue'
    import api from '../../utils/request'
    
    const webhooks = ref([]);
    const forms = ref([]); // Store available forms
    const loading = ref(false);
    const showCreateModal = ref(false);
    
    const form = reactive({ name: '', url: '', secret: '', events: [], form_id: null });
    const errors = reactive({ url: '', events: '', form_id: '' }); // Validation errors
    
    const availableEvents = [
        { label: 'Lead Created', value: 'lead.created' },
        { label: 'Lead Status Update', value: 'lead.updated.status' },
        { label: 'Any Update', value: 'lead.updated' },
        { label: 'Form Submission', value: 'form.submission' }
    ];
    
    const SUBMISSION_EVENT = 'form.submission';
    
    // --- Logic to handle "disable others and uncheck" requirement ---
    
    // Function to handle the checkbox change event
    const handleEventChange = (eventValue) => {
        // If the changed event is the special submission event
        if (eventValue === SUBMISSION_EVENT) {
            // If 'form.submission' is now checked (it will be in the v-model array)
            if (form.events.includes(SUBMISSION_EVENT)) {
                // Force the array to only contain 'form.submission', unchecking all others
                form.events = [SUBMISSION_EVENT];
            }
        }
        // Note: If 'form.submission' is unchecked, nothing special needs to happen here.
        // The user is free to select other events again.
    };
    
    // Computed property to determine if a specific checkbox should be disabled
    const isOtherDisabled = (eventValue) => {
        // Disable any event that is NOT the submission event IF the submission event is currently active.
        return form.events.includes(SUBMISSION_EVENT) && eventValue !== SUBMISSION_EVENT;
    };
    // --- End of new logic ---
    
    
    const fetchWebhooks = async () => {
        loading.value = true;
        try { const { data } = await api.get('/webhooks'); webhooks.value = data }
        catch (e) { console.error(e) } finally { loading.value = false; }
    }
    
    // Fetch forms for the dropdown
    const fetchForms = async () => {
        try { const { data } = await api.get('/forms'); forms.value = data; } catch (e) { }
    }
    
    const openCreate = () => {
        Object.assign(form, { name: '', url: '', secret: '', events: [], form_id: null });
        Object.assign(errors, { url: '', events: '', form_id: '' });
        showCreateModal.value = true
    }
    
    const validateForm = () => {
        let isValid = true;
        Object.assign(errors, { url: '', events: '', form_id: '' });
    
        if (!form.url) {
            errors.url = 'Payload URL is required.';
            isValid = false;
        }
    
        if (form.events.length === 0) {
            errors.events = 'Please select at least one event.';
            isValid = false;
        }
    
        // Conditional Validation: Form ID required if form.submission event is selected
        if (form.events.includes('form.submission') && !form.form_id) {
            errors.form_id = 'Please select a form to attach.';
            isValid = false;
        }
    
        return isValid;
    }
    
    const createWebhook = async () => {
        if (!validateForm()) return;
    
        try {
            await api.post('/webhooks', form);
            showCreateModal.value = false;
            fetchWebhooks();
        } catch (e) {
            // Handle API errors if needed
            alert('Failed to create webhook');
        }
    }
    
    const deleteWebhook = async (id) => {
        if (!confirm('Delete?')) return;
        try { await api.delete(`/webhooks/${id}`); fetchWebhooks() } catch (e) { }
    }
    
    onMounted(() => {
        fetchWebhooks();
        fetchForms(); // Fetch forms on mount
    })
    </script>
    
    <template>
        <div class="space-y-6">
            <div class="page-header">
                <div>
                    <h2 class="page-title">Webhooks</h2>
                    <p class="page-subtitle">External event triggers.</p>
                </div>
                <button @click="openCreate" class="btn-primary">+ Add Webhook</button>
            </div>
    
            <div v-if="loading" class="text-center py-12 text-slate-500">Loading...</div>
            <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <div v-if="webhooks.length === 0"
                    class="col-span-full py-12 text-center border-2 border-dashed border-slate-200 dark:border-slate-800 rounded-xl text-slate-500">
                    No webhooks found.</div>
                <div v-for="hook in webhooks" :key="hook.id" class="card p-5 group">
                    <div class="flex justify-between items-start mb-4">
                        <div class="flex items-center gap-3">
                            <div
                                class="p-2 bg-purple-50 dark:bg-purple-900/20 text-purple-600 rounded-lg">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M13 10V3L4 14h7v7l9-11h-7z" />
                                </svg>
                            </div>
                            <div class="flex flex-col">
                                <div class="flex items-baseline">
                                    <h3 class="font-bold text-slate-900 dark:text-white text-sm mr-3">{{ hook.name || 'Unnamed'
                                        }}</h3><span v-if="hook.is_active" class="badge badge-green mt-1">Active</span>
                                </div>
                                <span v-if="hook.form" class="text-[10px] text-slate-500 mt-1">Attached to: {{ hook.form.name
                                    }}</span>
                            </div>
                        </div>
                        <button @click="deleteWebhook(hook.id)"
                            class="btn-icon text-red-400 hover:text-red-600 hover:bg-red-50">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                        </button>
                    </div>
                    <div class="space-y-3">
                        <div>
                            <label class="form-label">Hook to</label>
                            <div
                                class="text-xs font-mono bg-slate-50 dark:bg-slate-950 p-2 rounded border border-slate-100 dark:border-slate-800 break-all">
                                {{ hook.url }}</div>
                        </div>
                        <div>
                            <label class="form-label">Triggers on</label>
                            <div class="flex flex-wrap gap-1 mt-1">
                                <span v-for="ev in hook.events" :key="ev" class="badge badge-slate">{{ ev }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
    
            <Transition name="modal">
                <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" @click="showCreateModal = false"></div>
                    <div class="card w-full max-w-lg relative">
                        <div class="card-header"><span class="card-title">New Webhook</span></div>
                        <div class="card-body space-y-4">
                            <div class="form-group">
                                <label class="form-label">Name</label>
                                <input v-model="form.name" class="form-input" placeholder="e.g. N8N Integration">
                            </div>
    
                            <div class="form-group">
                                <label class="form-label">URL <span class="text-red-500">*</span></label>
                                <input v-model="form.url" type="url" class="form-input" placeholder="https://..."
                                    :class="{ 'border-red-500': errors.url }">
                                <span v-if="errors.url" class="text-xs text-red-500 mt-1">{{ errors.url }}</span>
                            </div>
    
                            <div class="form-group">
                                <label class="form-label">Secret</label>
                                <input v-model="form.secret" class="form-input" type="password" autocomplete="new-password"
                                    placeholder="Optional signing secret">
                            </div>
    
                            <div class="form-group">
                                <label class="form-label">Events <span class="text-red-500">*</span></label>
                                <div class="space-y-2 max-h-40 overflow-y-auto border border-slate-200 dark:border-slate-700 rounded p-2"
                                    :class="{ 'border-red-500': errors.events }">
                                    <!-- EDITED THIS SECTION FOR CONDITIONAL LOGIC -->
                                    <label v-for="ev in availableEvents" :key="ev.value"
                                        class="flex items-center gap-2 cursor-pointer"
                                        :class="{'opacity-50 cursor-not-allowed': isOtherDisabled(ev.value)}">
                                        <input 
                                            type="checkbox" 
                                            v-model="form.events" 
                                            :value="ev.value" 
                                            class="rounded border-slate-300 text-slate-900 focus:ring-slate-900" 
                                            :disabled="isOtherDisabled(ev.value)"
                                            @change="handleEventChange(ev.value)" 
                                        />
                                        <span class="text-sm text-slate-700 dark:text-slate-300">{{ ev.label }}</span>
                                    </label>
                                    <!-- END EDITED SECTION -->
                                </div>
                                <span v-if="errors.events" class="text-xs text-red-500 mt-1">{{ errors.events }}</span>
                            </div>
    
                            <!-- Form Selection Dropdown (Conditionally Visible) -->
                            <div v-if="form.events.includes('form.submission')" class="form-group">
                                <label class="form-label">Select Form <span class="text-red-500">*</span></label>
                                <select v-model="form.form_id" class="form-select"
                                    :class="{ 'border-red-500': errors.form_id }">
                                    <option :value="null" disabled>Choose a form</option>
                                    <option v-for="f in forms" :key="f.id" :value="f.id">{{ f.name }}</option>
                                </select>
                                <span v-if="errors.form_id" class="text-xs text-red-500 mt-1">{{ errors.form_id }}</span>
                            </div>
    
                            <div class="flex justify-end gap-3">
                                <button @click="showCreateModal = false" class="btn-secondary">Cancel</button>
                                <button @click="createWebhook" class="btn-primary">Create Webhook</button>
                            </div>
                        </div>
                    </div>
                </div>
            </Transition>
        </div>
    </template>
    
    <style>
    /* Add CSS for modal transition if needed */
    .modal-enter-active,
    .modal-leave-active {
        transition: opacity 0.3s ease;
    }
    
    .modal-enter-from,
    .modal-leave-to {
        opacity: 0;
    }
    </style>
    