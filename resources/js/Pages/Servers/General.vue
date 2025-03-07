<script setup lang="ts">
import { ref } from 'vue';
import { toTypedSchema } from '@vee-validate/zod';
import { useForm as useVeeForm, useIsFormValid, useIsFormDirty } from 'vee-validate';
import * as z from 'zod'
import { useForm } from '@inertiajs/vue3'
import { toast } from 'vue-sonner'
import { route } from '@/route';
import CustomFormField from '@/components/CustomFormField.vue';
import CustomForm from '@/components/CustomForm.vue';
import { Separator } from '@/components/ui/separator';
import { onSubmit as sharedOnSubmit } from '@/lib/custom';
import MainView from '@/components/MainView.vue';
import { getServerBreadcrumbs, getServerSidebarNavItems } from '@/config/server/shared';
import { Deferred } from '@inertiajs/vue3'

import { Server } from '@/types/ServerType';

const props = defineProps<{
    server: Server,
}>()

const schema = z.object({
    name: z.string({ message: 'The name of the server is required.' }).min(1, 'The name of the server is required.'),
    description: z.string().optional(),
    wildcard_domain: z.string().optional(),
    server_timezone: z.string(),
})

const formData = {
    name: props.server.name,
    description: props.server.description,
    wildcard_domain: props.server.settings.wildcard_domain,
    server_timezone: props.server.settings.server_timezone,
}
const inertiaForm = useForm(formData)

const veeForm = useVeeForm({
    validationSchema: toTypedSchema(schema),
    initialValues: formData,
})
const isFormValid = useIsFormValid()
const isFormDirty = useIsFormDirty()

const onSubmit = veeForm.handleSubmit(async (values) => {
    return sharedOnSubmit({
        route: route('next_server_store', props.server.uuid),
        values,
        veeForm,
        inertiaForm,
    })
})

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
                    <CustomFormField :hidden="true" field="wildcard_domain" :form-schema="schema" :form="veeForm"
                        :value="wildcard_domain" placeholder="https://coolify.io" />
                    <CustomFormField field="server_timezone" :form-schema="schema" :form="veeForm"
                        :value="server_timezone" placeholder="America/New_York" />
                    <!-- <CustomFormField type="combobox" field="server_timezone" readonly :form-schema="schema"
                        :form="veeForm" :value="server_timezone" placeholder="America/New_York">
                        <template #combobox-options>
                            <ComboboxItem v-for="timezone in timezones" :key="timezone" :value="timezone">
                                {{ timezone }}

                                <ComboboxItemIndicator>
                                    <Check :class="cn('ml-auto h-4 w-4')" />
                                </ComboboxItemIndicator>
                            </ComboboxItem>
                        </template>
</CustomFormField> -->
                </div>
            </CustomForm>
        </template>
    </MainView>
</template>