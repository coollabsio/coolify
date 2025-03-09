<script setup lang="ts">
import { FormContext } from 'vee-validate';
import { computed, ref } from 'vue';
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

import { Combobox, ComboboxAnchor, ComboboxEmpty, ComboboxGroup, ComboboxInput, ComboboxItem, ComboboxItemIndicator, ComboboxList, ComboboxTrigger } from '@/components/ui/combobox'
import { InfoIcon, Check, ChevronsUpDown, Search, Eye, EyeOff } from 'lucide-vue-next';
const props = defineProps<{
  field: string;
  formSchema?: z.ZodObject<any>;
  form?: FormContext<any>;
  type?: string;
  label?: string;
  description?: string;
  descriptionError?: string;
  readonly?: boolean;
  disabled?: boolean;
  placeholder?: string;
  instantSave?: boolean;
  hidden?: boolean;
  rows?: number;
}>();

const emit = defineEmits<{
  (e: 'instant-save', event: { target: { name: string; value: any } }): void
}>();

const type = computed(() => props.type || 'text');

const showPassword = ref(false);

const details = computed(() => {
  const schema = props.formSchema?.shape[props.field];
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
        <TooltipProvider v-if="description" :delay-duration="0">
          <Tooltip>
            <TooltipTrigger>
              <InfoIcon class=" size-4 text-warning" />
            </TooltipTrigger>
            <TooltipContent v-html="description" align="center" side="right" />
          </Tooltip>
        </TooltipProvider>
      </FormLabel>
      <FormControl v-if="type === 'text' || type === 'textarea'">
        <div class="relative">
          <Input v-if="type === 'text'" :type="hidden && !showPassword ? 'password' : details.zodType"
            :placeholder="placeholder || ''" v-bind="componentField"
            :class="isFieldDirty ? 'border-l-4 border-warning' : ''" :readonly="readonly"
            :disabled="disabled || readonly" />
          <Textarea v-else-if="type === 'textarea'" :placeholder="placeholder || ''" v-bind="componentField"
            :rows="rows" :class="isFieldDirty ? 'border-l-4 border-warning' : ''" :readonly="readonly"
            :disabled="disabled || readonly" />
          <button v-if="hidden" type="button"
            class="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
            @click="showPassword = !showPassword">
            <Eye v-if="!showPassword" class="size-4" />
            <EyeOff v-else class="size-4" />
          </button>
        </div>
      </FormControl>
      <FormControl v-else-if="type === 'select'">
        <Select v-bind="componentField">
          <FormControl>
            <SelectTrigger :is-field-dirty="isFieldDirty">
              <SelectValue :placeholder="placeholder || ''" />
            </SelectTrigger>
          </FormControl>
          <SelectContent position="item-aligned">
            <slot name="select-options" />
          </SelectContent>
        </Select>
      </FormControl>
      <Combobox v-model="value" by="label" v-else-if="type === 'combobox'">
        <FormControl>
          <ComboboxAnchor>
            <ComboboxTrigger>
              <div class="relative w-full max-w-sm items-center">
                <ComboboxInput :display-value="(val) => val?.label ?? ''" placeholder="Select framework..." />
                <ComboboxTrigger class="absolute end-0 inset-y-0 flex items-center justify-center px-3">
                  <ChevronsUpDown class="size-4 text-muted-foreground" />
                </ComboboxTrigger>
              </div>
            </ComboboxTrigger>
          </ComboboxAnchor>
        </FormControl>

        <ComboboxList>
          <div class="relative w-full max-w-sm items-center">
            <ComboboxInput class="pl-9 focus-visible:ring-0 border-0 border-b rounded-none h-10"
              placeholder="Select framework..." />
            <span class="absolute start-0 inset-y-0 flex items-center justify-center px-3">
              <Search class="size-4 text-muted-foreground" />
            </span>
          </div>
          <ComboboxEmpty>
            Nothing found.
          </ComboboxEmpty>
          <ComboboxGroup>
            <slot name="combobox-options" />
          </ComboboxGroup>
        </ComboboxList>
      </Combobox>
      <FormControl v-else-if="type === 'checkbox'">
        <FormItem class="flex flex-col items-center justify-between rounded-xl border border-border p-4">
          <div class="flex flex-row items-center justify-between w-full">
            <div class="space-y-0.5">
              <FormLabel>
                {{ label || computedField }}
              </FormLabel>
              <FormDescription>
                <div v-if="description" class="text-sm text-muted-foreground" v-html="description" />
                <div v-if="descriptionError" class="text-sm text-destructive" v-html="descriptionError" />
              </FormDescription>
            </div>
            <FormControl>
              <Switch :checked="componentField.modelValue" @update:checked="(checked) => {
                componentField['onUpdate:modelValue'](checked);
                handleInstantSave(checked);
              }" :disabled="disabled || readonly" />
            </FormControl>
          </div>
          <slot name="switch-additional-content" />
        </FormItem>
      </FormControl>
      <FormMessage />
    </FormItem>
  </FormField>
</template>
