<script setup>
import { computed, reactive, watch } from 'vue'
import { Listbox, ListboxButton, ListboxOptions, ListboxOption } from '@headlessui/vue'

const props = defineProps({
  schema: { type: Array, default: () => [] },
  modelValue: { type: Object, default: () => ({}) },
  errors: { type: Object, default: () => ({}) }, // External errors (e.g. from Zod/Server)
})

const emit = defineEmits(['update:modelValue', 'update:errors'])

// -----------------------------
// VALIDATION ENGINE
// -----------------------------
const validationErrors = reactive({})
const touched = reactive({})

const validators = {
  required(value, field) {
    if (
      field.required &&
      (value === '' ||
        value === null ||
        value === undefined ||
        (Array.isArray(value) && value.length === 0))
    ) {
      return `${field.label || field.name} is required`
    }
  },
  min(value, field) {
    if (field.min !== undefined && typeof value === 'number' && value < field.min) {
      return `${field.label} must be at least ${field.min}`
    }
  },
  max(value, field) {
    if (field.max !== undefined && typeof value === 'number' && value > field.max) {
      return `${field.label} must be less than or equal to ${field.max}`
    }
  },
  minLength(value, field) {
    if (field.minLength && value?.length < field.minLength) {
      return `${field.label} must be minimum ${field.minLength} characters`
    }
  },
  maxLength(value, field) {
    if (field.maxLength && value?.length > field.maxLength) {
      return `${field.label} must be maximum ${field.maxLength} characters`
    }
  },
  email(value, field) {
    if (field.type === 'email' && value) {
      const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
      if (!re.test(value)) return `Invalid email format`
    }
  },
  url(value, field) {
    if (field.type === 'url' && value) {
      try {
        new URL(value)
      } catch {
        return `Invalid URL`
      }
    }
  },
  pattern(value, field) {
    if (field.pattern && value) {
      const regex = new RegExp(field.pattern)
      if (!regex.test(value)) return `Invalid format`
    }
  },
  checkboxMin(value, field) {
    if ((field.type === 'checkbox-group' || field.multiple) && field.min) {
      if (!value || value.length < field.min) return `Select at least ${field.min} options`
    }
  },
  checkboxMax(value, field) {
    if ((field.type === 'checkbox-group' || field.multiple) && field.max) {
      if (value?.length > field.max) return `Select no more than ${field.max} options`
    }
  },
}

const validateField = (field, value) => {
  for (const rule of Object.values(validators)) {
    const error = rule(value, field)
    if (error) return error
  }
  return null
}

const runValidation = () => {
  // Mark all as touched on submit attempt
  props.schema.forEach((field) => {
    touched[field.name] = true
    const value = props.modelValue[field.name]
    const error = validateField(field, value)
    if (error) validationErrors[field.name] = error
    else delete validationErrors[field.name]
  })
  emit('update:errors', { ...validationErrors })
  return Object.keys(validationErrors).length === 0
}

const getFieldError = (fieldName) => {
  // Only show error if touched OR if there is an external error (e.g. from server/Zod)
  if (touched[fieldName] && validationErrors[fieldName]) {
    return validationErrors[fieldName]
  }
  return props.errors[fieldName]
}

const handleBlur = (fieldName) => {
  touched[fieldName] = true
  // Trigger validation on blur
  const field = props.schema.find((f) => f.name === fieldName)
  if (field) {
    const error = validateField(field, props.modelValue[fieldName])
    if (error) validationErrors[fieldName] = error
    else delete validationErrors[fieldName]
  }
}

// -----------------------------
// DEFAULT VALUE HANDLER
// -----------------------------
const valueFor = (field) =>
  computed(() => {
    const val = props.modelValue[field.name]
    if (val !== undefined) return val
    if (field.type === 'checkbox-group' || field.multiple) return []
    if (field.type === 'range') return field.min || 0
    if (field.type === 'color') return field.value || '#000000'
    if (field.type === 'progress') return field.value || 0
    return field.value || ''
  })

// -----------------------------
// UPDATE FIELD (with live validation)
// -----------------------------
const updateField = (name, value) => {
  emit('update:modelValue', { ...props.modelValue, [name]: value })

  const field = props.schema.find((f) => f.name === name)
  if (field) {
    // We validate immediately to keep state fresh, BUT we do NOT mark as touched.
    // Error will only show if it was already touched or if we set touched here.
    // Intentionally NOT setting touched here to avoid "error while typing" unless
    // user has already focused out once.
    const error = validateField(field, value)
    if (error) validationErrors[name] = error
    else delete validationErrors[name]
    emit('update:errors', { ...validationErrors })
  }
}

// -----------------------------
// CHECKBOX GROUP
// -----------------------------
const handleCheckboxGroup = (field, optionValue, checked) => {
  // Mark as touched on interaction
  touched[field.name] = true

  const current = Array.isArray(props.modelValue[field.name])
    ? [...props.modelValue[field.name]]
    : []
  if (checked && !current.includes(optionValue)) current.push(optionValue)
  else if (!checked) {
    const idx = current.indexOf(optionValue)
    if (idx !== -1) current.splice(idx, 1)
  }
  updateField(field.name, current)
}

// -----------------------------
// WATCH MODEL VALUE
// -----------------------------
watch(
  () => props.modelValue,
  () => {
    // Optional: Could validate here, but updateField covers 99% of cases
  },
  { deep: true },
)

// expose method so parent can call it
defineExpose({
  runValidation,
  validationErrors,
})
</script>

<template>
  <form class="space-y-6" @submit.prevent>
    <div v-for="(field, index) in schema" :key="field.name || index" class="space-y-1.5">
      <!-- Label -->
      <div class="flex items-center justify-between">
        <label class="block text-sm font-semibold text-slate-700">
          {{ field.label || field.name }}
          <span v-if="field.required" class="text-red-500">*</span>
        </label>
        <span v-if="field.hint" class="text-xs text-slate-400 italic">{{ field.hint }}</span>
      </div>

      <div class="relative">
        <!-- Basic Inputs -->
        <div v-if="['text', 'email', 'number', 'tel', 'url'].includes(field.type)" class="pt-2">
          <input
            :type="field.type"
            class="block w-full rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm"
            :placeholder="field.placeholder"
            :value="valueFor(field).value"
            :min="field.min"
            :max="field.max"
            @input="updateField(field.name, $event.target.value)"
            @blur="handleBlur(field.name)"
          />
          <p v-if="getFieldError(field.name)" class="text-xs text-red-500 font-medium mt-1">
            {{ getFieldError(field.name) }}
          </p>
        </div>

        <div v-else-if="field.type === 'textarea'" class="pt-2">
          <textarea
            class="block w-full rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm"
            rows="4"
            :placeholder="field.placeholder"
            :value="valueFor(field).value"
            @input="updateField(field.name, $event.target.value)"
            @blur="handleBlur(field.name)"
          />

          <p v-if="getFieldError(field.name)" class="text-xs text-red-500 font-medium mt-1">
            {{ getFieldError(field.name) }}
          </p>
        </div>

        <!-- Single Select -->
        <Listbox
          v-else-if="field.type === 'select' && !field.multiple"
          :model-value="valueFor(field).value || ''"
          @update:model-value="(val) => updateField(field.name, val)"
        >
          <div class="relative">
            <ListboxButton
              class="relative w-full cursor-default rounded-lg border border-slate-300 bg-white py-2.5 pl-4 pr-10 text-left shadow-lg"
              @blur="handleBlur(field.name)"
            >
              <span
                class="block truncate text-sm"
                :class="!valueFor(field).value ? 'text-slate-400' : 'text-slate-900'"
              >
                {{
                  (field.options || []).find((o) => o.value === valueFor(field).value)?.label ||
                  field.placeholder ||
                  'Select an option'
                }}
              </span>
            </ListboxButton>
            <ListboxOptions
              class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-md bg-white py-1 shadow-lg ring-1"
            >
              <ListboxOption
                v-for="option in field.options || []"
                :key="option.value"
                :value="option.value"
                v-slot="{ active, selected }"
              >
                <li
                  :class="[
                    active ? 'bg-primary/10 text-primary' : 'text-slate-900',
                    'relative cursor-default select-none py-2 pl-3 pr-9',
                  ]"
                >
                  <span :class="[selected ? 'font-semibold' : 'font-normal']">{{
                    option.label
                  }}</span>
                </li>
              </ListboxOption>
            </ListboxOptions>

            <p v-if="getFieldError(field.name)" class="text-xs text-red-500 font-medium mt-1">
              {{ getFieldError(field.name) }}
            </p>
          </div>
        </Listbox>

        <!-- Multi-Select Dropdown -->
        <Listbox
          v-else-if="field.type === 'select' && field.multiple"
          :model-value="valueFor(field).value"
          multiple
          @update:model-value="(val) => updateField(field.name, val)"
        >
          <div class="relative">
            <ListboxButton
              class="relative w-full cursor-default rounded-lg border px-4 py-2 text-left"
              :class="getFieldError(field.name) ? 'border-red-500' : 'border-slate-300'"
              @blur="handleBlur(field.name)"
            >
              <span
                class="block truncate text-sm"
                :class="!valueFor(field).value.length ? 'text-slate-400' : 'text-slate-900'"
              >
                {{
                  valueFor(field).value.length
                    ? valueFor(field)
                        .value.map((v) => field.options.find((o) => o.value === v)?.label || v)
                        .join(', ')
                    : field.placeholder || 'Select options'
                }}
              </span>
            </ListboxButton>

            <ListboxOptions
              class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-md bg-white py-1 shadow-lg ring-1 ring-black/5"
            >
              <ListboxOption
                v-for="option in field.options || []"
                :key="option.value"
                :value="option.value"
                v-slot="{ active, selected }"
              >
                <li
                  :class="[
                    active ? 'bg-primary/10 text-primary' : 'text-slate-900',
                    'relative cursor-default select-none py-2 pl-3 pr-9',
                  ]"
                >
                  <span :class="[selected ? 'font-semibold' : 'font-normal']">{{
                    option.label
                  }}</span>
                  <span
                    v-if="selected"
                    class="absolute inset-y-0 right-0 flex items-center pr-4 text-primary"
                    >✔</span
                  >
                </li>
              </ListboxOption>
            </ListboxOptions>

            <p v-if="getFieldError(field.name)" class="text-xs text-red-500 font-medium mt-1">
              {{ getFieldError(field.name) }}
            </p>
          </div>
        </Listbox>

        <!-- Checkbox Group -->
        <div v-else-if="field.type === 'checkbox-group'">
          <label
            v-for="option in field.options"
            :key="option.value"
            class="flex items-center gap-3 p-2 rounded-lg border mb-1 cursor-pointer bg-slate-50"
          >
            <input
              type="checkbox"
              :value="option.value"
              :checked="(valueFor(field).value || []).includes(option.value)"
              @change="handleCheckboxGroup(field, option.value, $event.target.checked)"
            />
            <span>{{ option.label }}</span>
          </label>
          <p v-if="getFieldError(field.name)" class="text-xs text-red-500 font-medium mt-1">
            {{ getFieldError(field.name) }}
          </p>
        </div>

        <!-- Radio Group -->
        <div v-else-if="field.type === 'radio-group'" class="grid grid-cols-2 gap-3">
          <label
            v-for="option in field.options"
            :key="option.value"
            class="flex items-center gap-3 p-3 rounded-lg border cursor-pointer"
            :class="
              valueFor(field).value === option.value ? 'border-primary bg-primary/5' : 'bg-white'
            "
          >
            <input
              type="radio"
              :name="field.name"
              :value="option.value"
              :checked="valueFor(field).value === option.value"
              @change="updateField(field.name, option.value)"
            />
            <span>{{ option.label }}</span>
          </label>
          <p v-if="getFieldError(field.name)" class="text-xs text-red-500 font-medium mt-1">
            {{ getFieldError(field.name) }}
          </p>
        </div>

        <!-- Range -->
        <div v-else-if="field.type === 'range'" class="pt-2">
          <input
            type="range"
            class="w-full"
            :min="field.min || 0"
            :max="field.max || 100"
            :step="field.step || 1"
            :value="valueFor(field).value"
            @input="updateField(field.name, Number($event.target.value))"
            @blur="handleBlur(field.name)"
          />
          <p v-if="getFieldError(field.name)" class="text-xs text-red-500 font-medium mt-1">
            {{ getFieldError(field.name) }}
          </p>
        </div>

        <!-- File -->
        <div v-else-if="field.type === 'file'" class="pt-2">
          <input
            type="file"
            :multiple="field.multiple"
            @change="
              updateField(
                field.name,
                field.multiple ? [...$event.target.files] : $event.target.files[0],
              )
            "
            @blur="handleBlur(field.name)"
          />

          <p v-if="getFieldError(field.name)" class="text-xs text-red-500 font-medium mt-1">
            {{ getFieldError(field.name) }}
          </p>
        </div>

        <!-- Color -->
        <div v-else-if="field.type === 'color'" class="pt-2">
          <input
            type="color"
            :value="valueFor(field).value"
            @input="updateField(field.name, $event.target.value)"
            @blur="handleBlur(field.name)"
          />
          <p v-if="getFieldError(field.name)" class="text-xs text-red-500 font-medium mt-1">
            {{ getFieldError(field.name) }}
          </p>
        </div>
      </div>
    </div>
  </form>
</template>
