<script setup lang="ts">
import { FormContext } from 'vee-validate';
import { computed } from 'vue';
import * as z from 'zod';
import { FormControl, FormField, FormItem, FormLabel, FormMessage, FormDescription } from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Switch } from '@/components/ui/switch';
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  Select,
  SelectContent,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { InfoIcon } from 'lucide-vue-next';
const props = defineProps<{
  field: string;
  formSchema: z.ZodObject<any>;
  form: FormContext<any>;
  type?: string;
  label?: string;
  description?: string;
  descriptionError?: string;
  readonly?: boolean;
  disabled?: boolean;
  placeholder?: string;
  instantSave?: boolean;
}>();

const emit = defineEmits<{
  (e: 'instant-save', event: { target: { name: string; value: any } }): void
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

const isFieldDirty = computed(() => props.form?.isFieldDirty(props.field) ?? false);
const isRequired = computed(() => details.value.required);

const handleInstantSave = (value: boolean) => {
  emit('instant-save', {
    target: {
      name: props.field,
      value: value
    }
  });
};
</script>

<template>
  <FormField v-slot="{ componentField }" :name="field" :is-dirty="isFieldDirty">
    <FormItem class="w-full">
      <FormLabel class="flex items-center" v-if="type === 'text' || type === 'textarea' || type === 'select'">
        {{ label || computedField }}
        <span class="text-destructive px-1" v-if="isRequired">*</span>
        <TooltipProvider v-if="description" delay-duration="0">
          <Tooltip>
            <TooltipTrigger>
              <InfoIcon class=" size-4 text-warning" />
            </TooltipTrigger>
            <TooltipContent v-html="description" align="center" side="right" />
          </Tooltip>
        </TooltipProvider>
      </FormLabel>
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
      <FormControl v-else-if="type === 'checkbox'">
        <FormItem class="flex flex-row items-center justify-between rounded-xl border border-border p-4">
          <div class="space-y-0.5">
            <FormLabel>
              {{ label || computedField }}
            </FormLabel>
            <FormDescription>
              <span v-if="description" class="text-sm text-muted-foreground">
                {{ description }}
              </span>
              <span v-if="descriptionError" class="text-sm text-destructive">
                {{ descriptionError }}
              </span>
            </FormDescription>
          </div>
          <FormControl>
            <Switch :checked="componentField.modelValue" @update:checked="(checked) => {
              componentField['onUpdate:modelValue'](checked);
              handleInstantSave(checked);
            }" :disabled="disabled || readonly" />
          </FormControl>
        </FormItem>
      </FormControl>
      <FormMessage />
    </FormItem>
  </FormField>
</template>
