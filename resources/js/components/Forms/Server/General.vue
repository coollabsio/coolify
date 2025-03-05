<script setup lang="ts">
import { toTypedSchema } from '@vee-validate/zod';
import { useForm as useVeeForm, useIsFormValid, useIsFormDirty } from 'vee-validate';
import * as z from 'zod'
import { useForm } from '@inertiajs/vue3'
import { route } from '@/route';
import { toast } from 'vue-sonner'
import CustomFormField from '@/components/CustomFormField.vue';
import CustomForm from '@/components/CustomForm.vue';
import { Separator } from '@/components/ui/separator';
import { onSubmit as sharedOnSubmit } from '@/lib/utils';

const props = defineProps<{
  uuid: string
  name: string
  description?: string
  wildcard_domain?: string
  server_timezone?: string
}>()

const schema = z.object({
  name: z.string({ message: 'The name of the server is required.' }).min(1, 'The name of the server is required.'),
  description: z.string().optional(),
  wildcard_domain: z.string().optional(),
  server_timezone: z.string().optional(),
})

const formData = {
  name: props.name,
  description: props.description,
  wildcard_domain: props.wildcard_domain,
  server_timezone: props.server_timezone,
}
const inertiaForm = useForm(formData)

const veeForm = useVeeForm({
  validationSchema: toTypedSchema(schema),
  initialValues: {
    name: props.name,
    description: props.description,
    wildcard_domain: props.wildcard_domain,
    server_timezone: props.server_timezone,
  }
})
const isFormValid = useIsFormValid()
const isFormDirty = useIsFormDirty()

const onSubmit = veeForm.handleSubmit(async (values) => {
  return sharedOnSubmit({
    route: route('next_server_store', props.uuid),
    values,
    veeForm,
    inertiaForm,
  })
})
</script>

<template>
  <h2 class="pb-2 font-bold text-lg">
    General
  </h2>
  <p class="text-sm text-muted-foreground pb-2">
    General configuration for the server.
  </p>
  <Separator class="my-4" />
  <CustomForm @submit="onSubmit" :is-submitting="inertiaForm.processing" :is-form-valid="isFormValid"
    :is-form-dirty="isFormDirty">
    <div class="flex md:flex-row flex-col gap-2 w-full">
      <CustomFormField field="name" :form-schema="schema" :form="veeForm" :value="name" />
      <CustomFormField field="description" :form-schema="schema" :form="veeForm" :value="description" />
    </div>
    <div class="flex md:flex-row flex-col gap-2 w-full">
      <CustomFormField field="wildcard_domain" :form-schema="schema" :form="veeForm" :value="wildcard_domain"
        placeholder="https://coolify.io" />
      <CustomFormField field="server_timezone" :form-schema="schema" :form="veeForm" :value="server_timezone"
        placeholder="America/New_York" />
    </div>
  </CustomForm>
</template>