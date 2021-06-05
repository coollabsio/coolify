import { updateServiceLabels } from '$lib/api/applications/configuration';
import { execShellAsync } from '$lib/api/common';
import { docker } from '$lib/api/docker';
import ApplicationLog from '$models/ApplicationLog';
import Configuration from '$models/Configuration';
import Deployment from '$models/Deployment';
import type { Request } from '@sveltejs/kit';

export async function post(request: Request) {
	const { name, organization, branch, isPreviewDeploymentEnabled }: any = request.body || {};
	if (name && organization && branch) {
		const configuration = await Configuration.findOneAndUpdate({
			'repository.name': name,
			'repository.organization': organization,
			'repository.branch': branch
		}, { $set: { 'general.isPreviewDeploymentEnabled': isPreviewDeploymentEnabled, 'repository.pullRequest': 0 } }, { new: true }).select('-_id -__v -createdAt -updatedAt')

		await updateServiceLabels(configuration);
		if (!isPreviewDeploymentEnabled) {
			const found = (await docker.engine.listServices())
				.filter((r) => 
				r.Spec.Labels.managedBy === 'coolify' && 
				r.Spec.Labels.type === 'application' && 
				JSON.parse(r.Spec.Labels.configuration).repository.organization === organization &&
				JSON.parse(r.Spec.Labels.configuration).repository.name === name &&
				JSON.parse(r.Spec.Labels.configuration).repository.branch === branch  &&
				// JSON.parse(r.Spec.Labels.configuration).repository.pullRequest &&
				JSON.parse(r.Spec.Labels.configuration).repository.pullRequest !== 0
				)
			
			if (found.length > 0) {
				for (const prDeployment of found) {
					const pr = JSON.parse(prDeployment.Spec.Labels.configuration)
					await Configuration.findOneAndRemove({
						'repository.name': name,
						'repository.organization': organization,
						'repository.branch': branch,
						'publish.domain': pr.publish.domain
					})
					const deploys = await Deployment.find({ organization, branch, name, domain: pr.publish.domain });
					for (const deploy of deploys) {
						await ApplicationLog.deleteMany({ deployId: deploy.deployId });
						await Deployment.deleteMany({ deployId: deploy.deployId });
					}
					await execShellAsync(`docker stack rm ${pr.build.container.name}`);
				}
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
					status: 200,
					body: {
						success: true,
						message: 'Nothing to do.'
					}
				};
			}

		}
		return {
			status: 200,
			body: {
				success: true
			}
		};

	}
	return {
		status: 500,
		body: {
			error: 'Cannot save.'
		}
	};
}
