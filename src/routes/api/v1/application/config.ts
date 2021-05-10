import { docker } from '$lib/api/docker';
import type { Request } from '@sveltejs/kit';

export async function post(request: Request) {
	const { name, organization, branch }: any = request.body || {};
	if (name && organization && branch) {
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
		} else {
			return {
				status: 500,
				body: {
					error: 'No configuration found.'
				}
			};
		}
	}
}
