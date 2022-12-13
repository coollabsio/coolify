import { goto } from '$app/navigation';
import { errorNotification } from '$lib/common';
import { trpc } from '$lib/store';

export async function saveForm() {
	return await trpc.applications.save.mutate();
}
