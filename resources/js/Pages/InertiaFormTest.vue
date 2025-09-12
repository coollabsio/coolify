<script setup lang="ts">
import { Form } from '@inertiajs/vue3'

interface Props {
  username: string
  notifications_enabled: boolean
  flash?: { message?: string }
}

const props = defineProps<Props>()
</script>

<template>
  <div class="min-h-screen bg-gray-50 py-8">
    <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="text-center mb-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-2">
          Vue - "Form Component"
        </h1>
      </div>

      <div class="bg-white shadow-lg rounded-lg overflow-hidden">
        <Form action="/test-form" method="post" class="p-6" #default="{
          errors,
          hasErrors,
          processing,
          wasSuccessful,
          resetAndClearErrors,
          isDirty
        }">
          <div>
            <label for="username" class="block text-sm font-medium text-gray-700 mb-2">
              Username
            </label>
            <input type="text" id="username" name="username" :value="username"
              class="w-full text-black px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors duration-200"
              :class="errors.username ? 'border-red-500' : 'border-gray-300'" placeholder="Enter your username" />
            <p v-if="errors.username" class="mt-1 text-sm text-red-600">
              {{ errors.username }}
            </p>
          </div>

          <div class="flex items-center justify-between py-4">
            <div class="flex flex-col">
              <label for="notifications_enabled" class="text-sm font-medium text-gray-900">
                Enable Notifications
              </label>
              <p class="text-sm text-gray-500">
                Receive notifications about your account
              </p>
            </div>
            <div class="flex items-center">
              <!-- If I select this boolean the following error happens: "The notifications enabled field must be true or false." Issue: https://github.com/inertiajs/inertia/issues/2522 -->
              <input type="checkbox" id="notifications_enabled" name="notifications_enabled"
                :checked="notifications_enabled"
                class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500 focus:ring-2" />
              <p v-if="errors.notifications_enabled" class="mt-1 text-sm text-red-600">
                {{ errors.notifications_enabled }}
              </p>
            </div>
          </div>

          <div v-if="wasSuccessful" class="mt-6 bg-green-50 border border-green-200 p-4 rounded-md flex">
            <svg class="h-5 w-5 text-green-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd"
                d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                clip-rule="evenodd" />
            </svg>
            <p v-if="props.flash.message" class="ml-3 text-sm font-medium text-green-800">
              {{ props.flash.message }}
            </p>
          </div>

          <div class="flex justify-between items-center pt-6 mt-8 border-t border-gray-200">
            <button type="reset"
              class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors duration-200"
              :disabled="!isDirty || processing">
              Reset
            </button>

            <div class="flex space-x-3">
              <button v-if="hasErrors" type="button" @click="resetAndClearErrors()"
                class="px-4 py-2 text-sm font-medium text-red-700 bg-red-50 border border-red-300 rounded-md shadow-sm hover:bg-red-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition-colors duration-200">
                Clear Errors
              </button>

              <button type="submit"
                class="px-6 py-2 text-sm font-medium text-white bg-blue-600 border border-transparent rounded-md shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors duration-200"
                :disabled="processing">
                {{ processing ? "Saving..." : "Save Settings" }}
              </button>
            </div>
          </div>
        </Form>
      </div>
    </div>
  </div>
</template>
