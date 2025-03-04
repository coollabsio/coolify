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
import { ServerSettings } from '@/types/ServerType';
import { AlertCircle, CheckCircle } from 'lucide-vue-next';
const props = defineProps<{
  uuid: string
  docker_cleanup_frequency: ServerSettings['docker_cleanup_frequency']
  docker_cleanup_threshold: ServerSettings['docker_cleanup_threshold']
  force_docker_cleanup: ServerSettings['force_docker_cleanup']
}>()


const schema = z.object({
  docker_cleanup_frequency: z.string({ message: 'The docker cleanup frequency is required.' }),
  docker_cleanup_threshold: z.number({ message: 'The docker cleanup threshold is required.' }),
  force_docker_cleanup: z.boolean({ message: 'The docker cleanup force is required.' }),

})
const formData = {
  docker_cleanup_frequency: props.docker_cleanup_frequency,
  docker_cleanup_threshold: props.docker_cleanup_threshold,
  force_docker_cleanup: props.force_docker_cleanup,
}
const inertiaForm = useForm(formData)

const veeForm = useVeeForm({
  validationSchema: toTypedSchema(schema),
  initialValues: {
    docker_cleanup_frequency: props.docker_cleanup_frequency,
    docker_cleanup_threshold: props.docker_cleanup_threshold,
    force_docker_cleanup: props.force_docker_cleanup,
  }
})

const onSubmit = veeForm.handleSubmit(async (values) => {
  inertiaForm.transform(() => ({
    docker_cleanup_frequency: values.docker_cleanup_frequency,
    docker_cleanup_threshold: values.docker_cleanup_threshold,
    force_docker_cleanup: values.force_docker_cleanup,
  })).post(route('next_server_automations_store', props.uuid), {
    showProgress: false,
    onSuccess: async () => {
      toast.success('Automations updated successfully')
      inertiaForm.reset()
      veeForm.resetForm({
        values
      })
    },
  })
})

const instantSave = async (value: boolean) => {
  const formData = {
    force_docker_cleanup: value,
  }
  const tempInertiaForm = useForm(formData)
  await tempInertiaForm.transform(() => formData).post(route('next_server_automations_store', props.uuid), {
    showProgress: true,
    onSuccess: async () => {
      toast.success('Automations updated successfully.')
    },
  })
}
</script>

<template>
  <h2 class="pb-2">
    Automations
  </h2>
  <p class="text-sm text-muted-foreground pb-2">
    Automations configuration for the server.
  </p>
  <Separator class="my-4" />
  <CustomForm @submit="onSubmit" :is-submitting="inertiaForm.processing" :is-form-valid="useIsFormValid()"
    :is-form-dirty="useIsFormDirty()">
    <div class="flex md:flex-row flex-col gap-2 w-full">
      <CustomFormField field="docker_cleanup_frequency" :form-schema="schema" :form="veeForm"
        :value="docker_cleanup_frequency" placeholder="daily" description="The frequency of the docker cleanup." />
      <CustomFormField field="docker_cleanup_threshold" :form-schema="schema" :form="veeForm"
        :value="docker_cleanup_threshold" placeholder="10" description="The threshold of the docker cleanup." />
    </div>
    <CustomFormField type="checkbox" field="force_docker_cleanup" :form-schema="schema" :form="veeForm"
      :value="force_docker_cleanup" @instant-save="instantSave"
      description="Ignore the docker cleanup threshold and do the cleanup based on the frequency." />
  </CustomForm>
</template>