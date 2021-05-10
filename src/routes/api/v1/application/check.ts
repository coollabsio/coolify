import { setDefaultConfiguration } from '$lib/api/applications/configuration';
import { saveServerLog } from '$lib/api/applications/logging';
import { docker } from '$lib/api/docker';
import type { Request } from '@sveltejs/kit';

export async function post(request: Request) {
	try {
		const { DOMAIN } = process.env;
		const configuration = setDefaultConfiguration(request.body);

		const services = (await docker.engine.listServices()).filter(
			(r) => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'application'
		);
		let foundDomain = false;

		for (const service of services) {
			const running = JSON.parse(service.Spec.Labels.configuration);
			if (running) {
				if (
					running.publish.domain === configuration.publish.domain &&
					running.repository.id !== configuration.repository.id &&
					running.publish.path === configuration.publish.path
				) {
					foundDomain = true;
				}
			}
		}
		if (DOMAIN === configuration.publish.domain) foundDomain = true;
		if (foundDomain) {
			return {
				status: 200,
				body: {
					success: false,
					message: 'Domain already in use.'
				}
			};
		}
		return {
			status: 200,
			body: { success: true, message: 'OK' }
		};
	} catch (error) {
		await saveServerLog(error);
		return {
			status: 500,
			body: {
				error
			}
		};
	}
}
