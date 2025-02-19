<script setup lang="ts">
import { FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import { FormContext } from 'vee-validate';
import { computed } from 'vue';
import * as z from 'zod';

const props = defineProps<{
  field: string;
  placeholder?: string;
  formSchema: z.ZodObject<any>;
  form: FormContext<any>;
}>();

const details = computed(() => {
  const schema = props.formSchema.shape[props.field];
  let type = 'text';
  if (schema._def.typeName === 'ZodNumber') type = 'number';
  if (schema._def.typeName === 'ZodString') type = 'text';
  return {
    type,
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
    <FormItem class="w-full" v-auto-animate>
      <FormLabel>{{ computedField }}<span class="text-destructive pl-1" v-if="isRequired">*</span></FormLabel>
      <FormControl>
        <Input :type="details.type" :placeholder="placeholder || ''" v-bind="componentField"
          :class="isFieldDirty ? 'border-l-4 border-warning' : ''" />
      </FormControl>
      <FormMessage />
    </FormItem>
  </FormField>
</template>
