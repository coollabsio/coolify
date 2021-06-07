import { docker } from '$lib/api/docker';
import type { Request } from '@sveltejs/kit';
import Configuration from '$models/Configuration'
export async function get(request: Request) {
	// Should update this to get data from mongodb and update db with the currently running services on start!
	const dockerServices = await docker.engine.listServices();
	let databases: any = dockerServices.filter(
		(r) =>
			r.Spec.Labels.managedBy === 'coolify' &&
			r.Spec.Labels.type === 'database' &&
			r.Spec.Labels.configuration
	);
	let services: any = dockerServices.filter(
		(r) =>
			r.Spec.Labels.managedBy === 'coolify' &&
			r.Spec.Labels.type === 'service' &&
			r.Spec.Labels.configuration
	);
	databases = databases.map((r) => {
		if (JSON.parse(r.Spec.Labels.configuration)) {
			return {
				configuration: JSON.parse(r.Spec.Labels.configuration)
			};
		}
		return {};
	});
	services = services.map((r) => {
		if (JSON.parse(r.Spec.Labels.configuration)) {
			return {
				serviceName: r.Spec.Labels.serviceName,
				configuration: JSON.parse(r.Spec.Labels.configuration)
			};
		}
		return {};
	});
	const configurations = await Configuration.find({
		'repository.pullRequest': { '$in': [null, 0] }
	}).select('-_id -__v -createdAt')
	const applications = []
	for (const configuration of configurations) {
		const foundPRDeployments = await Configuration.find({
			'repository.id': configuration.repository.id,
			'repository.branch': configuration.repository.branch,
			'repository.pullRequest': { '$ne': 0 }
		}).select('-_id -__v -createdAt')
		const payload = {
			configuration,
			UpdatedAt: configuration.updatedAt,
			prBuilds: foundPRDeployments.length > 0 ? true : false,
		}
		applications.push(payload)
	}
	return {
		status: 200,
		body: {
			success: true,
			applications: {
				deployed: applications
			},
			databases: {
				deployed: databases
			},
			services: {
				deployed: services
			}
		}
	};
}
