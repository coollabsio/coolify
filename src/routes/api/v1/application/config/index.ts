import { docker } from '$lib/api/docker';
import Configuration from '$models/Configuration';
import type { Request } from '@sveltejs/kit';

export async function post(request: Request) {
	const { domain }: any = request.body || {};
	if (domain) {
		const configurationFound = await Configuration.find({
			'publish.domain': domain
		}).select('-_id -__v -createdAt -updatedAt');

		if (configurationFound) {
			return {
				status: 200,
				body: {
					configuration: [...configurationFound]
				}
			};
		}

		const services = await docker.engine.listServices();
		const applications = services.filter(
			(r) => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'application'
		);
		const found = applications.find((r) => {
			const configuration = r.Spec.Labels.configuration
				? JSON.parse(r.Spec.Labels.configuration)
				: null;

			if (configuration.publish.domain === domain) return r;
			return null;
		});

		if (found) {
			return {
				status: 200,
				body: {
					success: true,
					...JSON.parse(found.Spec.Labels.configuration)
				}
			};
		}
		return {
			status: 500,
			body: {
				error: 'No configuration found.'
			}
		};
	}
}
