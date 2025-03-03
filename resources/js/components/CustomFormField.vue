<script setup lang="ts">
import { FormContext } from 'vee-validate';
import { computed } from 'vue';
import * as z from 'zod';
import { FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
  Select,
  SelectContent,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'

const props = defineProps<{
  field: string;
  type?: string;
  label?: string;
  readonly?: boolean;
  disabled?: boolean;
  placeholder?: string;
  formSchema: z.ZodObject<any>;
  form: FormContext<any>;
}>();

const type = computed(() => props.type || 'text');

const details = computed(() => {
  const schema = props.formSchema.shape[props.field];
  let type = 'text';
  if (schema._def.typeName === 'ZodNumber') type = 'number';
  if (schema._def.typeName === 'ZodString') type = 'text';
  if (schema._def.typeName === 'ZodBoolean') type = 'checkbox';
  return {
    zodType: type,
    required: schema._def.typeName !== 'ZodOptional'
  }
})

const computedField = computed(() => {
  return props.field.split('_').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ');
})

const isFieldDirty = computed(() => props.form.isFieldDirty(props.field));
const isRequired = computed(() => details.value.required);
</script>

<template>
  <FormField v-slot="{ componentField }" :name="field" :is-dirty="isFieldDirty">
    <FormItem class="w-full">
      <FormLabel>{{ label || computedField }}<span class="text-destructive pl-1" v-if="isRequired">*</span></FormLabel>
      <FormControl v-if="type === 'text' || type === 'textarea'">
        <Input v-if="type === 'text'" :type="details.zodType" :placeholder="placeholder || ''" v-bind="componentField"
          :class="isFieldDirty ? 'border-l-4 border-warning' : ''" :readonly="readonly"
          :disabled="disabled || readonly" />
        <Textarea v-else-if="type === 'textarea'" :placeholder="placeholder || ''" v-bind="componentField"
          :class="isFieldDirty ? 'border-l-4 border-warning' : ''" :readonly="readonly"
          :disabled="disabled || readonly" />
      </FormControl>
      <FormControl v-else-if="type === 'select'">
        <Select v-bind="componentField">
          <FormControl>
            <SelectTrigger :is-field-dirty="isFieldDirty">
              <SelectValue :placeholder="placeholder || ''" />
            </SelectTrigger>
          </FormControl>
          <SelectContent>
            <slot name="select-options" />
          </SelectContent>
        </Select>
      </FormControl>
      <FormMessage />
    </FormItem>
  </FormField>
</template>
