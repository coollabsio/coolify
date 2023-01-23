import { errorNotification } from '$lib/common';
import { trpc } from '$lib/store';

type Props = {
	isNew: boolean;
	name: string;
	value: string;
	isBuildSecret?: boolean;
	isPRMRSecret?: boolean;
	isNewSecret?: boolean;
	databaseId: string;
};

export async function saveSecret({
	isNew,
	name,
	value,
	isNewSecret,
	databaseId
}: Props): Promise<void> {
	if (!name) return errorNotification('Name is required');
	if (!value) return errorNotification('Value is required');
	try {
		await trpc.databases.saveSecret.mutate({
			name,
			value,
			isNew: isNew || false
		});

		if (isNewSecret) {
			name = '';
			value = '';
		}
	} catch (error) {
		throw error;
	}
}
