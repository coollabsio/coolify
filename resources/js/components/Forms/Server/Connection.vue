<script setup lang="ts">
import { toTypedSchema } from '@vee-validate/zod';
import { useForm as useVeeForm, useIsFormValid, useIsFormDirty } from 'vee-validate';
import * as z from 'zod'
import { useForm } from '@inertiajs/vue3'
import { route } from '@/route';
import { toast } from 'vue-sonner'
import CustomFormField from '@/components/CustomFormField.vue';
import CustomForm from '@/components/CustomForm.vue';
import { Button } from '@/components/ui/button';
import { ref } from 'vue';
import axios from 'axios';
import { SelectGroup, SelectItem } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';


const props = defineProps<{
  uuid: string
  name: string
  ip: string
  user: string
  description?: string
  port: number
  private_key: {
    id: any
    name: string
  }
  private_keys: {
    id: any
    uuid: string
    name: string
  }[]
}>()
const isTestingConnection = ref(false)
const lastPrivateKeyId = ref(props.private_key.id)

const schema = z.object({
  name: z.string({ message: 'The name of the server is required.' }).min(1, 'The name of the server is required.'),
  description: z.string().optional(),
  ip: z.string({ message: 'The IP address / domain of the server is required.' }).min(1, 'The IP address / domain of the server is required.'),
  user: z.string({ message: 'The user of the server is required.' }).min(1, 'The user of the server is required.'),
  port: z.number({ message: 'The port of the server is required.' }).min(1, 'The port of the server is required.'),
  private_key_id: z.number({ message: 'The private key id of the server is required.' }).min(1, 'The private key id of the server is required.'),
})

const formData = {
  name: props.name,
  description: props.description,
  ip: props.ip,
  user: props.user,
  port: props.port,
  private_key_id: props.private_key.id,
}
const inertiaForm = useForm(formData)

const veeForm = useVeeForm({
  validationSchema: toTypedSchema(schema),
  initialValues: {
    name: props.name,
    description: props.description,
    ip: props.ip,
    user: props.user,
    port: props.port,
    private_key_id: props.private_key.id,
  }
})
const isFormValid = useIsFormValid()
const isFormDirty = useIsFormDirty()

const onSubmit = veeForm.handleSubmit(async (values) => {
  inertiaForm.transform(() => ({
    name: values.name,
    description: values.description,
    ip: values.ip,
    user: values.user,
    port: values.port,
    private_key_id: values.private_key_id,
  })).post(route('next_server_connection_store', props.uuid), {
    showProgress: false,
    onSuccess: async (asd) => {
      toast.success('Server connection updated successfully.')
      inertiaForm.reset()
      veeForm.resetForm({
        values
      })
    },
    onError: (errors) => {
      if (errors.error) {
        toast.error('Server connection update failed.', {
          description: errors.error,
        })
      } else {
        toast.error('Server connection update failed.')
      }
      if (errors.original_private_key_id) {
        veeForm.setFieldValue('private_key_id', Number(errors.original_private_key_id))
      }
      if (errors.original_ip) {
        veeForm.setFieldValue('ip', errors.original_ip)
      }
      if (errors.original_user) {
        veeForm.setFieldValue('user', errors.original_user)
      }
      if (errors.original_port) {
        veeForm.setFieldValue('port', Number(errors.original_port))
      }
    }
  })
})

const testConnection = async () => {
  isTestingConnection.value = true
  try {
    const response = await axios.get(route('next_server_connection_test', props.uuid))
    if (response.data.success) {
      lastPrivateKeyId.value = props.private_key.id
      toast.success('Server connection test successful.')
    } else {
      if (response.data.error) {
        toast.error(response.data.error)
        if (lastPrivateKeyId.value !== props.private_key.id) {
          veeForm.setFieldValue('private_key_id', lastPrivateKeyId.value)
          onSubmit()
        }
      } else {
        toast.error('Server connection test failed.')
      }
    }
  } catch (error) {
    toast.error('Server connection test failed.')
  } finally {
    isTestingConnection.value = false
  }
}
</script>

<template>
  <h2 class="pb-2">
    Connection
  </h2>
  <p class="text-sm text-muted-foreground pb-2">
    Connection configuration for the server.
  </p>
  <Separator class="my-4" />
  <CustomForm @submit="onSubmit" :is-submitting="inertiaForm.processing" :is-form-valid="isFormValid"
    :is-form-dirty="isFormDirty" :is-confirmation="true"
    :confirmation-message="`This will change how Coolify connects to the server.<br><br> Are you sure you want to continue?`"
    :custom-saving-text="`Saving & Checking Connection...`">
    <div class="flex gap-2 w-full">
      <CustomFormField label="IP Address / Domain" field="ip" :form-schema="schema" :form="veeForm" :value="ip" />
      <CustomFormField field="port" :form-schema="schema" :form="veeForm" :value="port" />
      <CustomFormField field="user" :form-schema="schema" :form="veeForm" :value="user" />
    </div>
    <div class="flex gap-2 w-full items-end">
      <CustomFormField type="select" label="Private Key" readonly field="private_key_id" :form-schema="schema"
        :form="veeForm" :value="private_key.id">
        <template #select-options>
          <SelectGroup>
            <SelectItem :value="private_key.id" disabled>
              {{ private_key.name }} (Current)
            </SelectItem>
            <SelectItem v-for="key in private_keys" :key="key.id" :value="key.id">
              {{ key.name }}
            </SelectItem>
          </SelectGroup>
        </template>
      </CustomFormField>
      <Button variant="outline" @click.prevent="testConnection"
        :disabled="isTestingConnection || inertiaForm.processing" :loading="isTestingConnection">
        Test Connection
      </Button>
    </div>
  </CustomForm>
</template>