<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { toTypedSchema } from '@vee-validate/zod';
import { useForm as useVeeForm, useIsFormValid, useIsFormDirty } from 'vee-validate';
import * as z from 'zod'
import { useForm } from '@inertiajs/vue3'
import { route } from '@/route';
import CustomFormField from '@/components/CustomFormField.vue';
import CustomForm from '@/components/CustomForm.vue';
import { Separator } from '@/components/ui/separator';
import { onSubmit as sharedOnSubmit, simpleFetch } from '@/lib/custom';
import MainView from '@/components/MainView.vue';
import { getServerBreadcrumbs, getServerSidebarNavItems } from '@/config/server/shared';
import { Deferred } from '@inertiajs/vue3'
import { Server } from '@/types/ServerType';
import { Play, Pause, RefreshCcw } from 'lucide-vue-next';
import { instantSave as sharedInstantSave } from '@/lib/custom';
import axios from 'axios';
import Confirmation from '@/components/Confirmation.vue';
import { toast } from 'vue-sonner';
import { usePage } from '@inertiajs/vue3'
import { useEchoPrivate } from '@/lib/useEcho';
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { PageProps } from '@/types/PagePropsType';

const page = usePage<PageProps>()
const echo = useEchoPrivate(`team.${page.props.currentTeam.id}`);

const props = defineProps<{
    server: Server,
    configuration: string,
}>()

onMounted(() => {
    router.flushAll()
    echo.listen('ProxyStatusChanged', (e: any) => {
        router.reload({ only: ['server'] })
        toast.success('Proxy status changed.')
    })
})


const proxyType = props.server.proxy.type.charAt(0).toUpperCase() + props.server.proxy.type.slice(1).toLowerCase()
const restarting = ref(false)

const schema = z.object({
    type: z.enum(['TRAEFIK', 'CADDY', 'NONE']),
    configuration: z.string(),
    generate_exact_labels: z.boolean(),
    redirect_enabled: z.boolean(),
    redirect_url: z.string().url().optional().or(z.literal('')),
})

const formData = {
    type: props.server.proxy.type,
    configuration: props.configuration,
    redirect_enabled: props.server.proxy.redirect_enabled,
    redirect_url: props.server.proxy.redirect_url || '',
    generate_exact_labels: props.server.settings.generate_exact_labels,
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
        route: route('next_server_proxy_store', props.server.uuid),
        values,
        veeForm,
        inertiaForm,
    })
})

const breadcrumb = ref(getServerBreadcrumbs(props.server.name, props.server.uuid))
const sidebarNavItems = computed(() => getServerSidebarNavItems(props.server))

const instantSave = ({ target: { name, value } }: { target: { name: string; value: any } }) => {
    return sharedInstantSave(route('next_server_proxy_store', props.server.uuid), { [name]: value });
}

const startProxy = async () => {
    return await simpleFetch.get(route('next_server_proxy_start', props.server.uuid), 'Proxy started.', 'Failed to start proxy.')
}

const stopProxy = async () => {
    return await simpleFetch.get(route('next_server_proxy_stop', props.server.uuid), 'Proxy stopped.', 'Failed to stop proxy.')
}

const restartProxy = async () => {
    restarting.value = true
    await simpleFetch.get(route('next_server_proxy_restart', props.server.uuid), 'Proxy restarted.', 'Failed to restart proxy.')
    restarting.value = false
}

const switchProxyType = async () => {
    return await simpleFetch.get(route('next_server_proxy_switch', props.server.uuid), 'Proxy type switched.', 'Failed to switch proxy type.')
}
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
            <div class="flex items-center justify-between gap-2 pb-2">
                <h2 class="font-bold text-lg">
                    Proxy
                </h2>


                <div v-if="props.server.proxy.status === 'running'" class="flex items-center gap-2">
                    <Confirmation variant="outline" size="sm" @continue="restartProxy"
                        :loading-text="`Restarting proxy...`">
                        <RefreshCcw class="size-4 text-warning" /> Restart
                    </Confirmation>
                    <Confirmation variant="outline" size="sm" @continue="stopProxy" :loading-text="`Stopping proxy...`">
                        <Pause class="size-4 text-destructive" /> Stop
                    </Confirmation>
                </div>
                <Confirmation v-else variant="outline" size="sm" @continue="startProxy"
                    :loading-text="`Starting proxy...`">
                    <Play class="size-4 text-warning" /> Start
                </Confirmation>
            </div>
            <p class="text-sm text-muted-foreground pb-2">
                Proxy configuration for the server.
            </p>
            <Separator class="my-4" />
            <CustomForm @submit="onSubmit" :is-submitting="inertiaForm.processing" :is-form-valid="isFormValid"
                :is-form-dirty="isFormDirty">
                <CustomFormField type="checkbox" :label="`Generate labels only for ${proxyType}`"
                    description="If set, all resources will only have docker container labels for Traefik. For applications, labels needs to be regenerated manually."
                    description-error="Resources needs to be restarted." field="generate_exact_labels"
                    :form-schema="schema" :form="veeForm" :value="veeForm.values.generate_exact_labels"
                    @instant-save="instantSave" />
                <CustomFormField type="checkbox" label="Override default request handler"
                    description="Requests to unknown hosts or stopped services will recieve a 503 response or be redirected to the URL you set below."
                    field="redirect_enabled" :form-schema="schema" :form="veeForm"
                    :value="veeForm.values.redirect_enabled" @instant-save="instantSave">
                    <template #switch-additional-content>
                        <CustomFormField v-if="props.server.proxy.redirect_enabled" type="text" label="Redirect URL"
                            field="redirect_url" :form-schema="schema" :form="veeForm"
                            :value="veeForm.values.redirect_url" placeholder="https://example.com" />
                    </template>
                </CustomFormField>
                <Deferred data="configuration">
                    <template #fallback>
                        <CustomFormField :rows="20" label="Proxy configuration" key="loading" type="textarea"
                            field="configuration" :form-schema="schema" disabled :form="veeForm"
                            value="Loading proxy configuration..." />
                    </template>
                    <CustomFormField :rows="20" label="Proxy configuration" key="configuration" type="textarea"
                        field="configuration" :form-schema="schema" :form="veeForm" :value="props.configuration" />
                </Deferred>
            </CustomForm>
        </template>
    </MainView>
</template>
