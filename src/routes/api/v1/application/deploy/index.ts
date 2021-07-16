import type { Request } from '@sveltejs/kit';
import Deployment from '$models/Deployment';
import { precheckDeployment, setDefaultConfiguration } from '$lib/api/applications/configuration';
import cloneRepository from '$lib/api/applications/cloneRepository';
import { cleanupTmp } from '$lib/api/common';
import queueAndBuild from '$lib/api/applications/queueAndBuild';
import Configuration from '$models/Configuration';
import preChecks from '$lib/api/applications/preChecks';
import preTasks from '$lib/api/applications/preTasks';

export async function post(request: Request) {
	const configuration = setDefaultConfiguration(request.body);
	if (!configuration) {
		return {
			status: 500,
			body: {
				error: 'Whaaat?'
			}
		};
	}
	try {
		await cloneRepository(configuration);
		const nextStep = await preChecks(configuration);
		if (nextStep === 0) {
			cleanupTmp(configuration.general.workdir);
			return {
				status: 200,
				body: {
					success: false,
					message: 'Nothing changed, no need to redeploy.'
				}
			};
		}
		await preTasks(configuration)

		queueAndBuild(configuration, nextStep);
		return {
			status: 201,
			body: {
				message: 'Deployment queued.',
				nickname: configuration.general.nickname,
				name: configuration.build.container.name,
				deployId: configuration.general.deployId
			}
		};
	} catch (error) {
		console.log(error);
		await Deployment.findOneAndUpdate({ nickname: configuration.general.nickname }, { $set: { progress: 'failed' } });
		return {
			status: 500,
			body: {
				error: error.message || error
			}
		};
	}
}
