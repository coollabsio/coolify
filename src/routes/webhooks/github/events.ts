import { getDomain, getTeam, getUserDetails, removeDestinationDocker } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';
import cuid from 'cuid';
import crypto from 'crypto';
import { buildQueue } from '$lib/queues';
import { checkContainer } from '$lib/haproxy';
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
	try {
		const buildId = cuid();
		const allowedGithubEvents = ['push', 'pull_request'];
		const allowedActions = ['opened', 'reopened', 'synchronize', 'closed'];
		const githubEvent = event.request.headers.get('x-github-event')?.toLowerCase();
		const githubSignature = event.request.headers.get('x-hub-signature-256')?.toLowerCase();
		if (!allowedGithubEvents.includes(githubEvent)) {
			return {
				status: 500,
				body: {
					message: 'Event not allowed.'
				}
			};
		}
		let repository, projectId, branch;
		const body = await event.request.json();
		if (githubEvent === 'push') {
			repository = body.repository;
			projectId = repository.id;
			branch = body.ref.split('/')[2];
		} else if (githubEvent === 'pull_request') {
			repository = body.pull_request.head.repo;
			projectId = repository.id;
			branch = body.pull_request.head.ref.split('/')[2];
		}

		const applicationFound = await db.getApplicationWebhook({ projectId, branch });
		if (applicationFound) {
			const webhookSecret = applicationFound.gitSource.githubApp.webhookSecret || null;
			const hmac = crypto.createHmac('sha256', webhookSecret);
			const digest = Buffer.from(
				'sha256=' + hmac.update(JSON.stringify(body)).digest('hex'),
				'utf8'
			);
			if (!dev) {
				const checksum = Buffer.from(githubSignature, 'utf8');
				if (checksum.length !== digest.length || !crypto.timingSafeEqual(digest, checksum)) {
					return {
						status: 500,
						body: {
							message: 'SHA256 checksum failed. Are you doing something fishy?'
						}
					};
				}
			}

			if (githubEvent === 'push') {
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
				await db.prisma.application.update({
					where: { id: applicationFound.id },
					data: { updatedAt: new Date() }
				});
				await db.prisma.build.create({
					data: {
						id: buildId,
						applicationId: applicationFound.id,
						destinationDockerId: applicationFound.destinationDocker.id,
						gitSourceId: applicationFound.gitSource.id,
						githubAppId: applicationFound.gitSource.githubApp?.id,
						gitlabAppId: applicationFound.gitSource.gitlabApp?.id,
						status: 'queued',
						type: 'webhook_commit'
					}
				});
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
			} else if (githubEvent === 'pull_request') {
				const pullmergeRequestId = body.number;
				const pullmergeRequestAction = body.action;
				const sourceBranch = body.pull_request.head.ref;
				if (!allowedActions.includes(pullmergeRequestAction)) {
					return {
						status: 500,
						body: {
							message: 'Action not allowed.'
						}
					};
				}

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
					if (
						pullmergeRequestAction === 'opened' ||
						pullmergeRequestAction === 'reopened' ||
						pullmergeRequestAction === 'synchronize'
					) {
						await db.prisma.application.update({
							where: { id: applicationFound.id },
							data: { updatedAt: new Date() }
						});
						await db.prisma.build.create({
							data: {
								id: buildId,
								applicationId: applicationFound.id,
								destinationDockerId: applicationFound.destinationDocker.id,
								gitSourceId: applicationFound.gitSource.id,
								githubAppId: applicationFound.gitSource.githubApp?.id,
								gitlabAppId: applicationFound.gitSource.gitlabApp?.id,
								status: 'queued',
								type: 'webhook_pr'
							}
						});
						await buildQueue.add(buildId, {
							build_id: buildId,
							type: 'webhook_pr',
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
					} else if (pullmergeRequestAction === 'closed') {
						if (applicationFound.destinationDockerId) {
							const id = `${applicationFound.id}-${pullmergeRequestId}`;
							const engine = applicationFound.destinationDocker.engine;
							await removeDestinationDocker({ id, engine });
						}
						return {
							status: 200,
							body: {
								message: 'Removed preview. Thank you!'
							}
						};
					}
				} else {
					return {
						status: 500,
						body: {
							message: 'Pull request previews are not enabled.'
						}
					};
				}
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
