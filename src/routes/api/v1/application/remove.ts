import { purgeImagesContainers } from '$lib/api/applications/cleanup';
import Deployment from '$models/Deployment';
import ApplicationLog from '$models/ApplicationLog';
import { delay, execShellAsync } from '$lib/api/common';
import Configuration from '$models/Configuration';

async function purgeImagesAsync(found) {
	await delay(10000);
	await purgeImagesContainers(found, true);
}
export async function post(request: Request) {
	const { organization, name, branch, domain } = request.body;
	try {
		const configurationFound = await Configuration.findOne({
			'repository.organization': organization,
			'repository.name': name,
			'repository.branch': branch,
			'publish.domain': domain
		})
		if (configurationFound) {
			const id = configurationFound._id
			if (configurationFound?.general?.pullRequest === 0) {
				// Main deployment deletion request; deleting main + PRs
				const allConfiguration = await Configuration.find({
					'repository.name': name,
					'repository.organization': organization,
					'repository.branch': branch,
				})
				for (const config of allConfiguration) {
					await Configuration.findOneAndRemove({
						'repository.name': config.repository.name,
						'repository.organization': config.repository.organization,
						'repository.branch': config.repository.branch,
					})
					await execShellAsync(`docker stack rm ${config.build.container.name}`);
				}
				const deploys = await Deployment.find({ organization, branch, name })
				for (const deploy of deploys) {
					await ApplicationLog.deleteMany({ deployId: deploy.deployId });
					await Deployment.deleteMany({ deployId: deploy.deployId });
				}

				purgeImagesAsync(configurationFound);
			} else {
				// Delete only PRs
				await Configuration.findByIdAndRemove(id)
				await execShellAsync(`docker stack rm ${configurationFound.build.container.name}`);
				const deploys = await Deployment.find({ organization, branch, name, domain })
				for (const deploy of deploys) {
					await ApplicationLog.deleteMany({ deployId: deploy.deployId });
					await Deployment.deleteMany({ deployId: deploy.deployId });
				}
				purgeImagesAsync(configurationFound);
			}
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
		console.log(error)
		return {
			status: 500,
			error: {
				message: 'Nothing to do.'
			}
		};
	}
}
