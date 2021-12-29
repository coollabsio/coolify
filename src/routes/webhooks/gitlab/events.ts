import { getTeam, getUserDetails } from '$lib/common';
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
    const { object_kind: objectKind } = request.body
    const buildId = cuid()
    try {
        if (objectKind === 'push') {
            const { ref } = request.body
            const projectId = Number(request.body['project_id'])
            const branch = ref.split('/')[2]
            const applicationFound = await db.getApplicationWebhook({ projectId, branch })
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
            }
        } else if (objectKind === 'merge_request') {
            const webhookToken = request.headers['x-gitlab-token']
            if (!webhookToken) {
                return {
                    status: 500,
                    body: {
                        message: 'Ooops, something is not okay, are you okay?'
                    }
                }
            }
            const projectId = Number(request.body.project.id)
            const sourceBranch = request.body.object_attributes.source_branch
            const targetBranch = request.body.object_attributes.target_branch
            const pullmergeRequestId = request.body.object_attributes.iid
            const applicationFound = await db.getApplicationGitLabWebhook({ projectId, branch: targetBranch })
            if (applicationFound) {
                if (applicationFound.mergepullRequestDeployments) {
                    if (applicationFound.gitSource.gitlabApp.webhookToken !== webhookToken) {
                        return {
                            status: 500,
                            body: {
                                message: 'Ooops, something is not okay, are you okay?'
                            }
                        }
                    }

                    await buildQueue.add(buildId, { build_id: buildId, type: 'webhook_mr', ...applicationFound, sourceBranch, pullmergeRequestId })
                    return {
                        status: 200,
                        body: {
                            message: 'Queued. Thank you!'
                        }
                    }
                }
                return {
                    status: 500,
                    body: {
                        message: 'Merge request deployments are not enabled.'
                    }
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
        return err
    }
}
