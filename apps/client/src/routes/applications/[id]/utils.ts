import { goto } from '$app/navigation';
import { errorNotification } from '$lib/common';
import { t } from '$lib/store';

export async function saveForm() {
	return await t.applications.save.mutate();
}
