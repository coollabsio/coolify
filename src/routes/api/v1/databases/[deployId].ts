import { execShellAsync } from '$lib/api/common';
import { docker } from '$lib/api/docker';
import type { Request } from '@sveltejs/kit';

export async function del(request: Request) {
	const { deployId } = request.params;
	await execShellAsync(`docker stack rm ${deployId}`);
	return {
		status: 200,
		body: {}
	};
}
export async function get(request: Request) {
	const { deployId } = request.params;

	try {
		const database = (await docker.engine.listServices()).find(
			(r) =>
				r.Spec.Labels.managedBy === 'coolify' &&
				r.Spec.Labels.type === 'database' &&
				JSON.parse(r.Spec.Labels.configuration).general.deployId === deployId
		);

		if (database) {
			const jsonEnvs = {};
			if (database.Spec.TaskTemplate.ContainerSpec.Env) {
				for (const d of database.Spec.TaskTemplate.ContainerSpec.Env) {
					const s = d.split('=');
					jsonEnvs[s[0]] = s[1];
				}
			}
			const payload = {
				config: JSON.parse(database.Spec.Labels.configuration),
				envs: jsonEnvs || null
			};

			return {
				status: 200,
				body: {
					...payload
				}
			};
		} else {
			return {
				status: 500,
				body: {
					error: 'No database found.'
				}
			};
		}
	} catch (error) {
		return {
			status: 500,
			body: {
				error: 'No database found.'
			}
		};
	}
}
