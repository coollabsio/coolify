import { docker } from '$lib/docker';
import LogsServer from '$models/Logs/Server';
import type { Request } from '@sveltejs/kit';


export async function get(request: Request) {
	const serverLogs = await LogsServer.find();
	const dockerServices = await docker.engine.listServices();
	let applications: any = dockerServices.filter(
		(r) =>
			r.Spec.Labels.managedBy === 'coolify' &&
			r.Spec.Labels.type === 'application' &&
			r.Spec.Labels.configuration
	);
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
	applications = applications.map((r) => {
		if (JSON.parse(r.Spec.Labels.configuration)) {
			return {
				configuration: JSON.parse(r.Spec.Labels.configuration),
				UpdatedAt: r.UpdatedAt
			};
		}
		return {};
	});
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
	applications = [
		...new Map(
			applications.map((item) => [
				item.configuration.publish.domain + item.configuration.publish.path,
				item
			])
		).values()
	];
	return {
		status: 200,
		body: {
			success: true,
			serverLogs,
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
