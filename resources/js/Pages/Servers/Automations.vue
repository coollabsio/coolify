<script setup lang="ts">
import { ref, computed, nextTick } from 'vue';
import { useForm as useVeeForm, useIsFormValid, useIsFormDirty } from 'vee-validate';
import { toTypedSchema } from '@vee-validate/zod';
import * as z from 'zod';
import { useForm } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import MainView from '@/components/MainView.vue';
import { getServerBreadcrumbs, getServerSidebarNavItems } from '@/config/server/shared';
import { route } from '@/route';
import CustomFormField from '@/components/CustomFormField.vue';
import CustomForm from '@/components/CustomForm.vue';
import { Separator } from '@/components/ui/separator';
import { instantSave as sharedInstantSave, getInstantSaveRefs, onSubmit as sharedOnSubmit } from '@/lib/utils';

import { ServerSettings, Server } from '@/types/ServerType';
const props = defineProps<{
  server: Server;
}>();

const instantSaveFields = ['force_docker_cleanup', 'delete_unused_volumes', 'delete_unused_networks']
const instantSaveRefs = getInstantSaveRefs(instantSaveFields, props.server.settings)

const schema = z.object({
  docker_cleanup_frequency: z.string({ message: 'The docker cleanup frequency is required.' }),
  docker_cleanup_threshold: z.number({ message: 'The docker cleanup threshold is required.' }),
  server_disk_usage_notification_threshold: z.number({ message: 'The server disk usage notification threshold is required.' }),
  server_disk_usage_check_frequency: z.string({ message: 'The server disk usage check frequency is required.' }),
})
const formData = {
  docker_cleanup_frequency: props.server.settings.docker_cleanup_frequency,
  docker_cleanup_threshold: props.server.settings.docker_cleanup_threshold,
  server_disk_usage_notification_threshold: props.server.settings.server_disk_usage_notification_threshold,
  server_disk_usage_check_frequency: props.server.settings.server_disk_usage_check_frequency,
}
const inertiaForm = useForm(formData)

const veeForm = useVeeForm({
  validationSchema: toTypedSchema(schema),
  initialValues: formData
})

const isFormValid = useIsFormValid()
const isFormDirty = useIsFormDirty();

const onSubmit = veeForm.handleSubmit(async (values) => {
  return sharedOnSubmit({
    route: route('next_server_automations_store', props.server.uuid),
    values,
    veeForm,
    inertiaForm,
    instantSaveRefs
  })
})

const instantSave = ({ target: { name, value } }: { target: { name: string; value: any } }) => {
  return sharedInstantSave(route('next_server_automations_store', props.server.uuid), { [name]: value });
}

const runDockerCleanup = () => {
  toast.success('Docker cleanup run successfully.')
}

const recentExecutions = () => {
  toast.success('Recent executions fetched successfully.')
}

const breadcrumb = ref(getServerBreadcrumbs(props.server.name, props.server.uuid))
const sidebarNavItems = getServerSidebarNavItems(props.server.uuid)
</script>

<template>
  <MainView hideSearch :breadcrumb="breadcrumb" :sidebarNavItems="sidebarNavItems">
    <template #title>
      {{ server.name }}
    </template>
    <template #subtitle>
      {{ server.description }}
    </template>
    <template #main>
      <h2 class="pb-2 font-bold text-lg">
        Automations
      </h2>
      <p class="text-sm text-muted-foreground pb-2">
        Automations configuration for the server.
      </p>
      <Separator class="my-4" />
      <CustomForm @submit="onSubmit" :is-submitting="inertiaForm.processing" :is-form-valid="isFormValid"
        :is-form-dirty="isFormDirty">
        <div class="flex md:flex-row flex-col gap-2 w-full">
          <CustomFormField field="docker_cleanup_frequency" :form-schema="schema" :form="veeForm"
            :value="docker_cleanup_frequency" placeholder="daily" description="The frequency of the docker cleanup." />
          <CustomFormField field="docker_cleanup_threshold" :form-schema="schema" :form="veeForm"
            :value="docker_cleanup_threshold" placeholder="10" description="The threshold of the docker cleanup." />
        </div>
        <div class="flex md:flex-row flex-col gap-2 w-full">
          <CustomFormField field="server_disk_usage_check_frequency" :form-schema="schema" :form="veeForm"
            :value="server_disk_usage_check_frequency" placeholder="daily"
            description="The frequency of the disk usage check." />
          <CustomFormField field="server_disk_usage_notification_threshold" :form-schema="schema" :form="veeForm"
            :value="server_disk_usage_notification_threshold" placeholder="10"
            description="The threshold of the disk usage notification." />
        </div>
        <!-- <Separator class="my-4" /> -->
        <!-- <div class="flex gap-2">
          <Button variant="outline" @click.prevent="runDockerCleanup">
            Run Docker Cleanup
          </Button>
          <Button variant="outline" @click.prevent="recentExecutions">
            Recent Executions
          </Button>
        </div>
        <Separator class="my-4" /> -->
        <CustomFormField type="checkbox" field="force_docker_cleanup" :value="instantSaveRefs.force_docker_cleanup"
          @instant-save="instantSave"
          description="Ignore the docker cleanup threshold and do the cleanup based on the frequency." />
        <CustomFormField type="checkbox" field="delete_unused_volumes" :value="instantSaveRefs.delete_unused_volumes"
          @instant-save="instantSave"
          description-error="Warning: This will delete all unused volumes on the server and could cause data loss." />
        <CustomFormField type="checkbox" field="delete_unused_networks" :value="instantSaveRefs.delete_unused_networks"
          @instant-save="instantSave"
          description-error="Warning: This will delete all unused networks on the server and could cause functional issues." />
      </CustomForm>
    </template>
  </MainView>
</template>