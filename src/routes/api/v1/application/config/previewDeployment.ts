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
			const found = await Configuration.find({
				'repository.name': name,
				'repository.organization': organization,
				'repository.branch': branch,
				'repository.pullRequest': { '$ne': 0 }
			})
			for (const prDeployment of found) {
				await Configuration.findOneAndRemove({
					'repository.name': name,
					'repository.organization': organization,
					'repository.branch': branch,
					'publish.domain': prDeployment.publish.domain
				})
				const deploys = await Deployment.find({ organization, branch, name, domain: prDeployment.publish.domain });
				for (const deploy of deploys) {
					await ApplicationLog.deleteMany({ deployId: deploy.deployId });
					await Deployment.deleteMany({ deployId: deploy.deployId });
				}
				await execShellAsync(`docker stack rm ${prDeployment.build.container.name}`);
			}
			return {
				status: 200,
				body: {
					organization,
					name,
					branch
				}
			};
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
