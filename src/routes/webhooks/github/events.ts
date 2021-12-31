import { getTeam, getUserDetails, removePreviewDestinationDocker } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';
import cuid from 'cuid';
import crypto from 'crypto'
import { buildQueue } from '$lib/queues';

export const options = async () => {
    return {
        status: 200,
        headers: {
            'Access-Control-Allow-Origin': '*',
            'Access-Control-Allow-Headers': 'Content-Type, Authorization',
            'Access-Control-Allow-Methods': 'GET, POST, PUT, DELETE, OPTIONS',

        }
    }
}

export const post = async (request) => {
    try {
        const buildId = cuid()
        const allowedGithubEvents = ['push', 'pull_request'];
        const allowedActions = ['opened', 'reopened', 'synchronize', 'closed'];
        const githubEvent = request.headers['x-github-event'].toLowerCase();
        const githubSignature = request.headers['x-hub-signature-256'].toLowerCase();
        if (!allowedGithubEvents.includes(githubEvent)) {
            return {
                status: 500,
                body: {
                    message: 'Event not allowed.'
                }
            };
        }
        let repository, projectId, branch;

        if (githubEvent === 'push') {
            repository = request.body.repository
            projectId = repository.id
            branch = request.body.ref.split('/')[2]
        } else if (githubEvent === 'pull_request') {
            repository = request.body.pull_request.head.repo
            projectId = repository.id
            branch = request.body.pull_request.head.ref.split('/')[2]
        }

        const applicationFound = await db.getApplicationWebhook({ projectId, branch })
        if (applicationFound) {
            const webhookSecret = applicationFound.gitSource.githubApp.webhookSecret
            const hmac = crypto.createHmac('sha256', webhookSecret);
            const digest = Buffer.from(
                'sha256=' + hmac.update(JSON.stringify(request.body)).digest('hex'),
                'utf8'
            );
            const checksum = Buffer.from(githubSignature, 'utf8');
            if (checksum.length !== digest.length || !crypto.timingSafeEqual(digest, checksum)) {
                return {
                    status: 500,
                    body: {
                        message: 'Ooops, something is not okay, are you okay?'
                    }
                };
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
                                startCommand: applicationFound.startCommand,
                            })
                        )
                        .digest('hex')
                    await db.prisma.application.updateMany({ where: { branch, projectId }, data: { configHash } })
                }
                await buildQueue.add(buildId, { build_id: buildId, type: 'webhook_commit', ...applicationFound })
                return {
                    status: 200,
                    body: {
                        message: 'Queued. Thank you!'
                    }
                }
            } else if (githubEvent === 'pull_request') {
                const pullmergeRequestId = request.body.number
                const pullmergeRequestAction = request.body.action
                const sourceBranch = request.body.pull_request.head.ref
                if (!allowedActions.includes(pullmergeRequestAction)) {
                    return {
                        status: 500,
                        body: {
                            message: 'Action not allowed.'
                        }
                    };
                }

                if (applicationFound.settings.previews) {
                    if (pullmergeRequestAction === 'opened' || pullmergeRequestAction === 'reopened') {
                        await buildQueue.add(buildId, { build_id: buildId, type: 'webhook_pr', ...applicationFound, sourceBranch, pullmergeRequestId })
                        return {
                            status: 200,
                            body: {
                                message: 'Queued. Thank you!'
                            }
                        }
                    } else if (pullmergeRequestAction === 'closed') {
                        if (applicationFound.destinationDockerId) {
                            await removePreviewDestinationDocker({ id: applicationFound.id, destinationDocker: applicationFound.destinationDocker, pullmergeRequestId })
                        }
                        return {
                            status: 200,
                            body: {
                                message: 'Removed preview. Thank you!'
                            }
                        }
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
        }
    } catch (err) {
        console.log(err)
        return {
            status: 500,
            body: {
                message: err.message
            }
        }
    }
}
