import type { Request } from '@sveltejs/kit';
import crypto from 'crypto';
import Deployment from '$models/Deployment';
import { docker } from '$lib/api/docker';
import { precheckDeployment, setDefaultConfiguration } from '$lib/api/applications/configuration';
import cloneRepository from '$lib/api/applications/cloneRepository';
import { cleanupTmp, execShellAsync } from '$lib/api/common';
import queueAndBuild from '$lib/api/applications/queueAndBuild';
import Configuration from '$models/Configuration';
import ApplicationLog from '$models/ApplicationLog';
import { cleanupStuckedDeploymentsInDB } from '$lib/api/applications/cleanup';
export async function post(request: Request) {
	let configuration;
	const allowedGithubEvents = ['push', 'pull_request'];
	const allowedPRActions = ['opened', 'reopened', 'synchronize', 'closed'];
	const githubEvent = request.headers['x-github-event'];
	const { GITHUP_APP_WEBHOOK_SECRET } = process.env;
	const hmac = crypto.createHmac('sha256', GITHUP_APP_WEBHOOK_SECRET);
	const digest = Buffer.from(
		'sha256=' + hmac.update(JSON.stringify(request.body)).digest('hex'),
		'utf8'
	);
	const checksum = Buffer.from(request.headers['x-hub-signature-256'], 'utf8');
	if (checksum.length !== digest.length || !crypto.timingSafeEqual(digest, checksum)) {
		return {
			status: 500,
			body: {
				error: 'Invalid request.'
			}
		};
	}

	if (!allowedGithubEvents.includes(githubEvent)) {
		return {
			status: 500,
			body: {
				error: 'Event not allowed.'
			}
		};
	}

	// TODO: Monorepo support here. Find all configurations by id and update all deployments! Tough! 
	try {
		const applications = await Configuration.find({
			'repository.id': request.body.repository.id
		}).select('-_id -__v -createdAt -updatedAt');
		if (githubEvent === 'push') {
			configuration = applications.find((r) => {
				if (request.body.ref.startsWith('refs')) {
					if (r.repository.branch === request.body.ref.split('/')[2]) {
						return r;
					}
				}
				return null;
			});
		} else if (githubEvent === 'pull_request') {
			if (!allowedPRActions.includes(request.body.action)) {
				return {
					status: 500,
					body: {
						error: 'PR action is not allowed.'
					}
				};
			}
			configuration = applications.find(
				(r) => r.repository.branch === request.body['pull_request'].base.ref
			);
			if (configuration) {
				if (!configuration.general.isPreviewDeploymentEnabled) {
					return {
						status: 500,
						body: {
							error: 'PR deployments are not enabled.'
						}
					};
				}
				configuration.general.pullRequest = request.body.number;
			}
		}
		if (!configuration) {
			return {
				status: 500,
				body: {
					error: 'No configuration found.'
				}
			};
		}
		configuration = setDefaultConfiguration(configuration);
		const { id, organization, name, branch } = configuration.repository;
		const { domain } = configuration.publish;
		const { deployId, nickname, pullRequest } = configuration.general;

		if (request.body.action === 'closed') {
			const deploys = await Deployment.find({ organization, branch, name, domain });
			for (const deploy of deploys) {
				await ApplicationLog.deleteMany({ deployId: deploy.deployId });
				await Deployment.deleteMany({ deployId: deploy.deployId });
			}
			await Configuration.findOneAndRemove({
				'repository.id': id,
				'repository.organization': organization,
				'repository.name': name,
				'repository.branch': branch,
				'general.pullRequest': pullRequest
			});
			await execShellAsync(`docker stack rm ${configuration.build.container.name}`);
			return {
				status: 200,
				body: {
					success: true,
					message: 'Removed'
				}
			};
		}
		await cloneRepository(configuration);
		const { foundService, imageChanged, configChanged, forceUpdate } = await precheckDeployment(
			configuration
		);
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
			repoId: id,
			branch: branch,
			organization: organization,
			name: name,
			domain: domain,
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

		await new Deployment({
			repoId: id,
			branch,
			deployId,
			domain,
			organization,
			name,
			nickname
		}).save();

		if (githubEvent === 'pull_request') {
			await Configuration.findOneAndUpdate(
				{
					'repository.id': id,
					'repository.organization': organization,
					'repository.name': name,
					'repository.branch': branch,
					'general.pullRequest': pullRequest
				},
				{ ...configuration },
				{ upsert: true, new: true }
			);
		} else {
			await Configuration.findOneAndUpdate(
				{
					'repository.id': id,
					'repository.organization': organization,
					'repository.name': name,
					'repository.branch': branch,
					'general.pullRequest': { $in: [null, 0] }
				},
				{ ...configuration },
				{ upsert: true, new: true }
			);
		}

		queueAndBuild(configuration, imageChanged);
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
		// console.log(configuration)
		if (configuration) {
			cleanupTmp(configuration.general.workdir);
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
		}

		return {
			status: 500,
			body: {
				error: error.message || error
			}
		};
	} finally {
		try {
			await cleanupStuckedDeploymentsInDB();
		} catch (error) {
			console.log(error);
		}
	}
}
