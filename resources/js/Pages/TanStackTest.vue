<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@tanstack/vue-form'
import { router } from '@inertiajs/vue3'

type Props = {
  username: string
  notifications_enabled: boolean
  errors?: Record<string, string>
  flash?: { message?: string }
}

const props = defineProps<Props>()

// Manual tracking state
const submitSuccess = ref(false)
const submitErrors = ref<Record<string, string>>({})

const form = useForm({
  defaultValues: {
    username: props.username,
    notifications_enabled: props.notifications_enabled,
  },
  onSubmit: async ({ value }) => {
    // Manual form submission via Inertia router.
    router.post('/test-form', value, {
      preserveScroll: true,
      onSuccess: () => {
        submitSuccess.value = true
      },
      onError: (errors) => {
        submitErrors.value = errors
      },
    })
  },
})

function handleReset() {
  form.reset()
  submitSuccess.value = false
  submitErrors.value = {}
}

function clearErrors() {
  submitErrors.value = {}
}
</script>

<template>
  <div class="min-h-screen bg-gray-50 py-8">
    <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="text-center mb-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-2">
          Vue TanStack
        </h1>
      </div>

      <div class="bg-white shadow-lg rounded-lg overflow-hidden">
        <form @submit.prevent.stop="form.handleSubmit" class="p-6">
          <form.Field name="username">
            <template v-slot="{ field }">
              <label :for="field.name" class="block text-sm font-medium text-gray-700 mb-2">
                Username
              </label>
              <input :id="field.name" type="text" :value="field.state.value" placeholder="John Doe" @input="(e: Event) => {
                const target = e.target as HTMLInputElement;
                field.handleChange(target.value);
              }"
                class="w-full text-black px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors duration-200"
                :class="{
                  'border-yellow-400': field.state.meta.isDirty && !submitErrors.username,
                  'border-red-500': submitErrors.username || field.state.meta.errors.length > 0
                }" />
              <!-- Getting the errors from the defineProps(). Which can NOT be reset because it is a prop from Inertia. -->
              <p v-if="props.errors.username" class="mt-2 text-sm text-red-600">
                Way 1 - Errors from defineProps(): {{ props.errors.username }}
                - This can NOT be reset because it is a prop from Inertia.
              </p>
              <!-- Getting the errors from the submitErrors state. Which can be reset because it is vue state. -->
              <p v-if="submitErrors.username" class="mt-2 text-sm text-red-600">
                Way 2 - Errors from Vue state submitErrors: {{ submitErrors.username }}
                - This can be reset because it is Vue state.
              </p>
            </template>
          </form.Field>

          <form.Field name="notifications_enabled">
            <template v-slot="{ field }">
              <div class="flex items-center justify-between py-4">
                <div class="flex flex-col">
                  <label :for="field.name" class="text-sm font-medium text-gray-900">
                    Enable Notifications
                  </label>
                  <p class="text-sm text-gray-500">
                    Receive notifications about your account
                  </p>
                </div>
                <input :id="field.name" type="checkbox" :checked="field.state.value" @input="(e: Event) => {
                  const target = e.target as HTMLInputElement;
                  field.handleChange(target.checked);
                }" class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500 focus:ring-2" />
              </div>
            </template>
          </form.Field>

          <div v-if="submitSuccess" class="mt-6 bg-green-50 border border-green-200 p-4 rounded-md flex">
            <svg class="h-5 w-5 text-green-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd"
                d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                clip-rule="evenodd" />
            </svg>
            <p class="ml-3 text-sm font-medium text-green-800">
              {{ props.flash?.message }}
            </p>
          </div>

          <div class="flex justify-between items-center pt-6 mt-8 border-t border-gray-200">
            <form.Subscribe :selector="(state: any) => ({ isDirty: state.isDirty, isSubmitting: state.isSubmitting })">
              <template v-slot="state">
                <button type="button" @click="handleReset" :disabled="!state.isDirty || state.isSubmitting"
                  class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors duration-200">
                  Reset
                </button>
              </template>
            </form.Subscribe>

            <div class="flex space-x-3">
              <button v-if="Object.keys(submitErrors).length > 0" type="button" @click="clearErrors"
                class="px-4 py-2 text-sm font-medium text-red-700 bg-red-50 border border-red-300 rounded-md shadow-sm hover:bg-red-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition-colors duration-200">
                Clear Errors
              </button>

              <form.Subscribe
                :selector="(state: any) => ({ canSubmit: state.canSubmit, isSubmitting: state.isSubmitting })">
                <template v-slot="state">
                  <button type="submit" :disabled="!state.canSubmit"
                    class="px-6 py-2 text-sm font-medium text-white bg-blue-600 border border-transparent rounded-md shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors duration-200">
                    <span v-if="state.isSubmitting">Saving...</span>
                    <span v-else>Save Settings</span>
                  </button>
                </template>
              </form.Subscribe>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
