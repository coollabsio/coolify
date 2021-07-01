import { purgeImagesContainers } from '$lib/api/applications/cleanup';
import Deployment from '$models/Deployment';
import ApplicationLog from '$models/ApplicationLog';
import { delay, execShellAsync } from '$lib/api/common';
import Configuration from '$models/Configuration';

export async function post(request: Request) {
	const { nickname } = request.body;
	try {
		const configurationFound = await Configuration.findOne({
			'general.nickname': nickname
		});
		if (configurationFound) {
			const id = configurationFound._id;
			if (configurationFound?.general?.pullRequest === 0) {
				// Main deployment deletion request; deleting main + PRs
				const allConfiguration = await Configuration.find({
					'publish.domain': { $regex: `.*${configurationFound.publish.domain}`, $options: 'i' },
					'publish.path': configurationFound.publish.path
				});
				for (const config of allConfiguration) {
					await execShellAsync(`docker stack rm ${config.build.container.name}`);
				}
				await Configuration.deleteMany({
					'publish.domain': { $regex: `.*${configurationFound.publish.domain}`, $options: 'i' },
					'publish.path': configurationFound.publish.path
				});
				const deploys = await Deployment.find({ nickname });
				for (const deploy of deploys) {
					await ApplicationLog.deleteMany({ deployId: deploy.deployId });
					await Deployment.deleteMany({ deployId: deploy.deployId });
				}
			} else {
				// Delete only PRs
				await Configuration.findByIdAndRemove(id);
				await execShellAsync(`docker stack rm ${configurationFound.build.container.name}`);
				const deploys = await Deployment.find({ nickname });
				for (const deploy of deploys) {
					await ApplicationLog.deleteMany({ deployId: deploy.deployId });
					await Deployment.deleteMany({ deployId: deploy.deployId });
				}
			}
		}

		return {
			status: 200,
			body: {}
		};
	} catch (error) {
		console.log(error);
		return {
			status: 500,
			error: {
				message: 'Nothing to do.'
			}
		};
	}
}
