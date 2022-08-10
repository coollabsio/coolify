import { post } from '$lib/api';
import { t } from '$lib/translations';
import { errorNotification } from '$lib/common';

type Props = {
	isNew: boolean;
	name: string;
	value: string;
	isBuildSecret?: boolean;
	isPRMRSecret?: boolean;
	isNewSecret?: boolean;
	applicationId: string;
};

export async function saveSecret({
	isNew,
	name,
	value,
	isBuildSecret,
	isPRMRSecret,
	isNewSecret,
	applicationId
}: Props): Promise<void> {
	if (!name) return errorNotification(`${t.get('forms.name')} ${t.get('forms.is_required')}`);
	if (!value) return errorNotification(`${t.get('forms.value')} ${t.get('forms.is_required')}`);
	try {
		await post(`/applications/${applicationId}/secrets`, {
			name,
			value,
			isBuildSecret,
			isPRMRSecret,
			isNew: isNew || false
		});
		if (isNewSecret) {
			name = '';
			value = '';
			isBuildSecret = false;
		}
	} catch (error) {
		throw error
	}
}
