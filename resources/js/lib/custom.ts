import type { Ref } from 'vue'
import { useForm } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { ref } from 'vue';
import axios from 'axios';

export const inputType = 'w-full rounded-xl border border-input bg-input-background px-3 py-2 text-sm disabled:opacity-50';

export function instantSave(route: string, data: Record<string, any>, successMessage: string = 'Settings updated successfully.') {
    return useForm(data).post(route, {
        showProgress: true,
        onSuccess: async () => {
            toast.success(successMessage);
        },
        onError: async (error) => {
            toast.error('Failed to update settings.', {
                description: error.error || 'Unknown error occurred.'
            });
        }
    });
}
export function getInstantSaveRefs(fields: string[], props: any) {
    return fields.reduce((acc, field) => ({
        ...acc,
        [field]: ref(props[field])
    }), {} as Record<string, Ref<boolean>>)
}
export function onSubmit({ route, values, veeForm, inertiaForm, instantSaveRefs, onError, onSuccess, successMessage = 'Configuration updated successfully.', errorMessage = 'Failed to update configuration.' }: { route: string, values: any, veeForm: any, inertiaForm: any, instantSaveRefs?: any, onError?: (error: any) => Promise<void>, onSuccess?: () => Promise<void>, successMessage?: string, errorMessage?: string }) {
    const options = {
        showProgress: false,
        onSuccess: async () => {
            toast.success(successMessage)
            veeForm.resetForm({
                values
            })
            if (instantSaveRefs) {
                for (const field in instantSaveRefs) {
                    veeForm.setFieldValue(field, instantSaveRefs[field].value)
                }
            }
            inertiaForm.reset()
        },
        onError: async (error: any) => {
            toast.error(errorMessage, {
                description: error.error || 'Unknown error occurred.'
            })
        }
    }
    if (onError) {
        options.onError = onError
    }
    if (onSuccess) {
        options.onSuccess = onSuccess
    }
    return inertiaForm.transform(() => values).post(route, options)
}

export const simpleFetch = {
    async get(route: string, successMessage?: string, errorMessage?: string) {
        try {
            const response = await axios.get(route)
            if (!response.data.success) {
                if (response.data.error) {
                    toast.error(response.data.error)
                } else {
                    toast.error(errorMessage || 'Failed to perform operation.')
                }
            }
        } catch (error) {
            toast.error(errorMessage || 'Failed to perform operation.')
        }
    },
    async post(route: string, data: any = {}, successMessage: string = 'Operation successful.', errorMessage: string = 'Failed to perform operation.') {
        try {
            const response = await axios.post(route, data)
            if (!response.data.success) {
                if (response.data.error) {
                    toast.error(response.data.error)
                } else {
                    toast.error(errorMessage)
                }
            } else {
                toast.success(successMessage)
            }
        } catch (error) {
            toast.error(errorMessage)
        }
    }
}
