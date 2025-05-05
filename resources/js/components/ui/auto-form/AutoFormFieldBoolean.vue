<script setup lang="ts">
import type { FieldProps } from './interface'
import { Checkbox } from '@/components/ui/checkbox'
import { FormControl, FormDescription, FormField, FormItem, FormMessage } from '@/components/ui/form'
import { Switch } from '@/components/ui/switch'
import { computed } from 'vue'
import AutoFormLabel from './AutoFormLabel.vue'
import { beautifyObjectName, maybeBooleanishToBoolean } from './utils'

const props = defineProps<FieldProps>()

const booleanComponent = computed(() => props.config?.component === 'switch' ? Switch : Checkbox)
</script>

<template>
  <FormField v-slot="slotProps" :name="fieldName">
    <FormItem>
      <div class="space-y-0 mb-3 flex items-center gap-3">
        <FormControl>
          <slot v-bind="slotProps">
            <component
              :is="booleanComponent"
              :disabled="maybeBooleanishToBoolean(config?.inputProps?.disabled) ?? disabled"
              :name="slotProps.componentField.name"
              :model-value="slotProps.componentField.modelValue"
              @update:model-value="slotProps.componentField['onUpdate:modelValue']"
            />
          </slot>
        </FormControl>
        <AutoFormLabel v-if="!config?.hideLabel" :required="required">
          {{ config?.label || beautifyObjectName(label ?? fieldName) }}
        </AutoFormLabel>
      </div>

      <FormDescription v-if="config?.description">
        {{ config.description }}
      </FormDescription>
      <FormMessage />
    </FormItem>
  </FormField>
</template>
