import { type ClassValue, clsx } from 'clsx'
import { twMerge } from 'tailwind-merge'
import { useForm } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { Ref } from 'vue';
import { ref } from 'vue';

export const inputType = 'w-full rounded-xl border border-l-4 border-input bg-input-background px-3 py-2 text-sm';

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

export async function instantSave(route: string, data: Record<string, any>, successMessage: string = 'Settings updated successfully.') {
  return await useForm(data).post(route, {
    showProgress: true,
    onSuccess: async () => {
      toast.success(successMessage);
    },
    onError: async (error) => {
      const errorMessage = error.error || 'Unknown error occurred.';
      toast.error('Failed to update settings.', {
        description: errorMessage
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

