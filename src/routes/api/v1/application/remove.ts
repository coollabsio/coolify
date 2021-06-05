import { purgeImagesContainers } from '$lib/api/applications/cleanup';
import { docker } from '$lib/api/docker';
import Deployment from '$models/Deployment';
import ApplicationLog from '$models/ApplicationLog';
import { delay, execShellAsync } from '$lib/api/common';
import Configuration from '$models/Configuration';

async function call(found) {
	await delay(10000);
	await purgeImagesContainers(found, true);
}
export async function post(request: Request) {
	const { organization, name, branch, domain } = request.body;
	let found = false;
	try {
		const allServices = await docker.engine.listServices()

		const allServicesForRepository = allServices.filter((r) =>
			r.Spec.Labels.managedBy === 'coolify' &&
			r.Spec.Labels.type === 'application' &&
			JSON.parse(r.Spec.Labels.configuration).repository.organization === organization &&
			JSON.parse(r.Spec.Labels.configuration).repository.name === name &&
			JSON.parse(r.Spec.Labels.configuration).repository.branch === branch
		).map(r => {
			return JSON.parse(r.Spec.Labels.configuration)
		})
		const shouldDeleteMe = allServicesForRepository.find(f => f.publish.domain === domain)
		// if (shouldDeleteMe.repository.pullRequest === 0 || !shouldDeleteMe.repository.pullRequest) {
			if (shouldDeleteMe.repository.pullRequest === 0) {
			for (const delMe of allServicesForRepository) {
				const { name, organization, branch } = delMe.repository
				const { domain } = delMe.publish
				await Configuration.findOneAndRemove({
					'repository.name': name,
					'repository.organization': organization,
					'repository.branch': branch,
					'publish.domain': domain
				})
				const deploys = await Deployment.find({ organization, branch, name, domain });
				for (const deploy of deploys) {
					await ApplicationLog.deleteMany({ deployId: deploy.deployId });
					await Deployment.deleteMany({ deployId: deploy.deployId });
				}
				await execShellAsync(`docker stack rm ${delMe.build.container.name}`);
				call(delMe);
			}

		} else {
			const { name, organization, branch } = shouldDeleteMe.repository
			const { domain } = shouldDeleteMe.publish
			await Configuration.findOneAndRemove({
				'repository.name': name,
				'repository.organization': organization,
				'repository.branch': branch,
				'publish.domain': domain
			})
			const deploys = await Deployment.find({ organization, branch, name, domain });
			for (const deploy of deploys) {
				await ApplicationLog.deleteMany({ deployId: deploy.deployId });
				await Deployment.deleteMany({ deployId: deploy.deployId });
			}
			await execShellAsync(`docker stack rm ${shouldDeleteMe.build.container.name}`);
			call(shouldDeleteMe);
		}

		return {
			status: 200,
			body: {
				organization,
				name,
				branch
			}
		};

	} catch (error) {
		return {
			status: 500,
			error: {
				message: 'Nothing to do.'
			}
		};
	}
}
