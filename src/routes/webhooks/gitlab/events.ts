import { getTeam, getUserDetails, getDomain, removeDestinationDocker } from '$lib/common';
import { checkContainer, removeProxyConfiguration } from '$lib/haproxy';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';
import cuid from 'cuid';
import crypto from 'crypto';
import { buildQueue } from '$lib/queues';
import { dev } from '$app/env';

export const options: RequestHandler = async () => {
	return {
		status: 200,
		headers: {
			'Access-Control-Allow-Origin': '*',
			'Access-Control-Allow-Headers': 'Content-Type, Authorization',
			'Access-Control-Allow-Methods': 'GET, POST, PUT, DELETE, OPTIONS'
		}
	};
};

export const post: RequestHandler = async (event) => {
	const allowedActions = ['opened', 'reopen', 'close', 'open', 'update'];
	const body = await event.request.json();
	const buildId = cuid();
	try {
		const { object_kind: objectKind } = body;
		if (objectKind === 'push') {
			const { ref } = body;
			const projectId = Number(body['project_id']);
			const branch = ref.split('/')[2];
			const applicationFound = await db.getApplicationWebhook({ projectId, branch });
			if (applicationFound) {
				if (!applicationFound.configHash) {
					const configHash = crypto
						.createHash('sha256')
						.update(
							JSON.stringify({
								buildPack: applicationFound.buildPack,
								port: applicationFound.port,
								installCommand: applicationFound.installCommand,
								buildCommand: applicationFound.buildCommand,
								startCommand: applicationFound.startCommand
							})
						)
						.digest('hex');
					await db.prisma.application.updateMany({
						where: { branch, projectId },
						data: { configHash }
					});
				}
				await buildQueue.add(buildId, {
					build_id: buildId,
					type: 'webhook_commit',
					...applicationFound
				});
				return {
					status: 200,
					body: {
						message: 'Queued. Thank you!'
					}
				};
			}
		} else if (objectKind === 'merge_request') {
			const webhookToken = event.request.headers.get('x-gitlab-token');
			if (!webhookToken) {
				return {
					status: 500,
					body: {
						message: 'Ooops, something is not okay, are you okay?'
					}
				};
			}

			const isDraft = body.object_attributes.work_in_progress;
			const action = body.object_attributes.action;
			const projectId = Number(body.project.id);
			const sourceBranch = body.object_attributes.source_branch;
			const targetBranch = body.object_attributes.target_branch;
			const pullmergeRequestId = body.object_attributes.iid;
			if (!allowedActions.includes(action)) {
				return {
					status: 500,
					body: {
						message: 'Action not allowed.'
					}
				};
			}
			if (isDraft) {
				return {
					status: 500,
					body: {
						message: 'Draft MR, do nothing.'
					}
				};
			}

			const applicationFound = await db.getApplicationWebhook({ projectId, branch: targetBranch });
			if (applicationFound) {
				if (applicationFound.settings.previews) {
					if (applicationFound.destinationDockerId) {
						const isRunning = await checkContainer(
							applicationFound.destinationDocker.engine,
							applicationFound.id
						);
						if (!isRunning) {
							return {
								status: 500,
								body: {
									message: 'Application not running.'
								}
							};
						}
					}
					if (!dev && applicationFound.gitSource.gitlabApp.webhookToken !== webhookToken) {
						return {
							status: 500,
							body: {
								message: 'Ooops, something is not okay, are you okay?'
							}
						};
					}
					if (
						action === 'opened' ||
						action === 'reopen' ||
						action === 'open' ||
						action === 'update'
					) {
						await buildQueue.add(buildId, {
							build_id: buildId,
							type: 'webhook_mr',
							...applicationFound,
							sourceBranch,
							pullmergeRequestId
						});
						return {
							status: 200,
							body: {
								message: 'Queued. Thank you!'
							}
						};
					} else if (action === 'close') {
						if (applicationFound.destinationDockerId) {
							const domain = getDomain(applicationFound.fqdn);
							const isHttps = applicationFound.fqdn.startsWith('https://');
							const isWWW = applicationFound.fqdn.includes('www.');
							const fqdn = `${isHttps ? 'https://' : 'http://'}${
								isWWW ? 'www.' : ''
							}${pullmergeRequestId}.${domain}`;

							const id = `${applicationFound.id}-${pullmergeRequestId}`;
							const engine = applicationFound.destinationDocker.engine;

							await removeDestinationDocker({ id, engine });
							await removeProxyConfiguration(fqdn);
						}

						return {
							status: 200,
							body: {
								message: 'Removed preview. Thank you!'
							}
						};
					}
				}
				return {
					status: 500,
					body: {
						message: 'Merge request previews are not enabled.'
					}
				};
			}
		}

		return {
			status: 500,
			body: {
				message: 'Not handled event.'
			}
		};
	} catch (err) {
		console.log(err);
		return {
			status: 500,
			body: {
				message: err.message
			}
		};
	}
};
