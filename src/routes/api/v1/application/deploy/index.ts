import type { Request } from '@sveltejs/kit';
import Deployment from '$models/Deployment';
import { docker } from '$lib/api/docker';
import { precheckDeployment, setDefaultConfiguration } from '$lib/api/applications/configuration';
import cloneRepository from '$lib/api/applications/cloneRepository';
import { cleanupTmp } from '$lib/api/common';
import queueAndBuild from '$lib/api/applications/queueAndBuild';
import Configuration from '$models/Configuration';
export async function post(request: Request) {
	let configuration;
	try {
		const services = (await docker.engine.listServices()).filter(
			(r) => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'application'
		);
		configuration = setDefaultConfiguration(request.body);

		if (!configuration) {
			return {
				status: 500,
				body: {
					error: 'Whaaat?'
				}
			};
		}
		await cloneRepository(configuration);
		const { foundService, imageChanged, configChanged, forceUpdate } = await precheckDeployment({
			services,
			configuration
		});
		if (foundService && !forceUpdate && !imageChanged && !configChanged) {
			cleanupTmp(configuration.general.workdir);
			return {
				status: 200,
				body: {
					success: false,
					message: 'Nothing changed, no need to redeploy.'
				}
			};
		}
		const alreadyQueued = await Deployment.find({
			repoId: configuration.repository.id,
			branch: configuration.repository.branch,
			organization: configuration.repository.organization,
			name: configuration.repository.name,
			domain: configuration.publish.domain,
			progress: { $in: ['queued', 'inprogress'] }
		});
		if (alreadyQueued.length > 0) {
			return {
				status: 200,
				body: {
					success: false,
					message: 'Already in the queue.'
				}
			};
		}
		const { id, organization, name, branch } = configuration.repository;
		const { domain } = configuration.publish;
		const { deployId, nickname } = configuration.general;
		await new Deployment({
			repoId: id,
			branch,
			deployId,
			domain,
			organization,
			name,
			nickname
		}).save();
		await Configuration.findOneAndUpdate({
			'repository.id': id,
			'repository.organization': organization,
			'repository.name': name,
			'repository.branch': branch,
		},
			{ ...configuration },
			{ upsert: true, new: true })

		queueAndBuild(configuration, imageChanged);
		return {
			status: 200,
			body: {
				message: 'Deployment queued.',
				nickname: configuration.general.nickname,
				name: configuration.build.container.name,
				deployId: configuration.general.deployId
			}
		};
	} catch (error) {
		await Deployment.findOneAndUpdate(
			{
				repoId: configuration.repository.id,
				branch: configuration.repository.branch,
				organization: configuration.repository.organization,
				name: configuration.repository.name,
				domain: configuration.publish.domain
			},
			{
				repoId: configuration.repository.id,
				branch: configuration.repository.branch,
				organization: configuration.repository.organization,
				name: configuration.repository.name,
				domain: configuration.publish.domain,
				progress: 'failed'
			}
		);
		return {
			status: 500,
			body: {
				error: error.message || error
			}
		};
	}
}
