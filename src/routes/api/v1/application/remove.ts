import { purgeImagesContainers } from '$lib/api/applications/cleanup';
import { docker } from '$lib/api/docker';
import Deployment from '$models/Logs/Deployment';
import ApplicationLog from '$models/Logs/Application';
import { delay, execShellAsync } from '$lib/api/common';

async function call(found) {
	await delay(10000);
	await purgeImagesContainers(found, true);
}
export async function post(request: Request) {
	const { organization, name, branch } = request.body;
	let found = false;
	try {
		(await docker.engine.listServices())
			.filter((r) => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'application')
			.map((s) => {
				const running = JSON.parse(s.Spec.Labels.configuration);
				if (
					running.repository.organization === organization &&
					running.repository.name === name &&
					running.repository.branch === branch
				) {
					found = running;
				}
				return null;
			});
		if (found) {
			const deploys = await Deployment.find({ organization, branch, name });
			for (const deploy of deploys) {
				await ApplicationLog.deleteMany({ deployId: deploy.deployId });
				await Deployment.deleteMany({ deployId: deploy.deployId });
			}
			await execShellAsync(`docker stack rm ${found.build.container.name}`);
			call(found);
			return {
				status: 200,
				body: {
					organization,
					name,
					branch
				}
			};
		} else {
			return {
				status: 500,
				error: {
					message: 'Nothing to do.'
				}
			};
		}
	} catch (error) {
		return {
			status: 500,
			error: {
				message: 'Nothing to do.'
			}
		};
	}
}
