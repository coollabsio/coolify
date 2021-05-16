import { docker } from '$lib/api/docker';
import Configuration from '$models/Configuration';
import type { Request } from '@sveltejs/kit';

export async function post(request: Request) {
	const { name, organization, branch }: any = request.body || {};
	if (name && organization && branch) {
		const configurationFound = await Configuration.findOne({
			'repository.name': name,
			'repository.organization': organization,
			'repository.branch': branch,
		}).lean()
		if (configurationFound) {
			return {
				status: 200,
				body: {
					success: true,
					...configurationFound
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
			if (branch) {
				if (
					configuration.repository.name === name &&
					configuration.repository.organization === organization &&
					configuration.repository.branch === branch
				) {
					return r;
				}
			} else {
				if (
					configuration.repository.name === name &&
					configuration.repository.organization === organization
				) {
					return r;
				}
			}
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
