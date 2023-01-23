import { errorNotification } from '$lib/common';
import { trpc } from '$lib/store';

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
	isNewSecret
}: Props): Promise<void> {
	if (!name) return errorNotification('Name is required');
	if (!value) return errorNotification('Value is required');
	try {
		await trpc.services.createSecret.mutate({
			name,
			value,
			isBuildSecret,
			isNew: isNew || false
		});

		if (isNewSecret) {
			name = '';
			value = '';
			isBuildSecret = false;
		}
	} catch (error) {
		throw error;
	}
}

export async function saveForm(formData: any, service: any) {
	const settings = service.serviceSetting.map((setting: { name: string }) => setting.name);
	const secrets = service.serviceSecret.map((secret: { name: string }) => secret.name);
	const baseCoolifySetting = ['name', 'fqdn', 'exposePort', 'version'];
	for (let field of formData) {
		const [key, value] = field;
		if (secrets.includes(key) && value) {
			await trpc.services.createSecret.mutate({
				name: key,
				value
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
	await trpc.services.saveService.mutate(service);
	const {
		data: { service: reloadedService }
	} = await trpc.services.getServices.query({ id: service.id });
	return reloadedService;
}
