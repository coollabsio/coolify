import { get, post } from '$lib/api';
import { t } from '$lib/translations';
import { errorNotification } from '$lib/common';

type Props = {
	isNew: boolean;
	name: string;
	value: string;
	isBuildSecret?: boolean;
	isPRMRSecret?: boolean;
	isNewSecret?: boolean;
	serviceId: string;
};

export async function saveSecret({
	isNew,
	name,
	value,
	isBuildSecret,
	isPRMRSecret,
	isNewSecret,
	serviceId
}: Props): Promise<void> {
	if (!name) return errorNotification(`${t.get('forms.name')} ${t.get('forms.is_required')}`);
	if (!value) return errorNotification(`${t.get('forms.value')} ${t.get('forms.is_required')}`);
	try {
		await post(`/services/${serviceId}/secrets`, {
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

export async function saveForm(formData: any, service: any) {
	const settings = service.serviceSetting.map((setting: { name: string }) => setting.name);
	const secrets = service.serviceSecret.map((secret: { name: string }) => secret.name);
	const baseCoolifySetting = ['name', 'fqdn', 'exposePort', 'version'];
	for (let field of formData) {
		const [key, value] = field;
		if (secrets.includes(key) && value) {
			await post(`/services/${service.id}/secrets`, {
				name: key,
				value,
			});
		} else {
			service.serviceSetting = service.serviceSetting.map((setting: any) => {
				if (setting.name === key) {
					setting.changed = true;
					setting.value = value;
				}
				return setting;
			});
			if (!settings.includes(key) && !baseCoolifySetting.includes(key)) {
				service.serviceSetting.push({
					id: service.id,
					name: key,
					value: value,
					isNew: true
				});
			}
			if (baseCoolifySetting.includes(key)) {
				service[key] = value;
			}
		}

	}
	await post(`/services/${service.id}`, { ...service });
	const { service: reloadedService } = await get(`/services/${service.id}`);
	return reloadedService;

}
