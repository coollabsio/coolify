import { getTeam, getUserDetails, getDomain, removeDestinationDocker } from '$lib/common';
import { checkContainer } from '$lib/haproxy';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';
import cuid from 'cuid';
import crypto from 'crypto';
import { buildQueue } from '$lib/queues';
import { dev } from '$app/env';

export const options: RequestHandler = async () => {
	return {
		status: 204,
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

	try {
		const { object_kind: objectKind } = body;
		if (objectKind === 'push') {
			const { ref } = body;
			const projectId = Number(body['project_id']);
			const branch = ref.split('/')[2];
			const applications = await db.getApplicationWebhook({ projectId, branch });
			if (applications.length > 0) {
				for (const application of applications) {
					const buildId = cuid();
					if (!application.configHash) {
						const configHash = crypto
							.createHash('sha256')
							.update(
								JSON.stringify({
									buildPack: application.buildPack,
									port: application.port,
									installCommand: application.installCommand,
									buildCommand: application.buildCommand,
									startCommand: application.startCommand
								})
							)
							.digest('hex');
						await db.prisma.application.update({
							where: { id: application.id },
							data: { configHash }
						});
					}
					await db.prisma.application.update({
						where: { id: application.id },
						data: { updatedAt: new Date() }
					});
					await buildQueue.add(buildId, {
						build_id: buildId,
						type: 'webhook_commit',
						...application
					});
				}

				return {
					status: 200,
					body: {
						message: 'Queued. Thank you!'
					}
				};
			}
			return {
				status: 500,
				body: {
					message: 'No applications configured in Coolify.'
				}
			};
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

			const applications = await db.getApplicationWebhook({ projectId, branch: targetBranch });
			if (applications.length > 0) {
				for (const application of applications) {
					const buildId = cuid();
					if (application.settings.previews) {
						if (application.destinationDockerId) {
							const isRunning = await checkContainer(
								application.destinationDocker.engine,
								application.id
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
						if (!dev && application.gitSource.gitlabApp.webhookToken !== webhookToken) {
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
							await db.prisma.application.update({
								where: { id: application.id },
								data: { updatedAt: new Date() }
							});
							await buildQueue.add(buildId, {
								build_id: buildId,
								type: 'webhook_mr',
								...application,
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
							if (application.destinationDockerId) {
								const id = `${application.id}-${pullmergeRequestId}`;
								const engine = application.destinationDocker.engine;
								await removeDestinationDocker({ id, engine });
							}

							return {
								status: 200,
								body: {
									message: 'Removed preview. Thank you!'
								}
							};
						}
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
