import axios from "axios";
import cuid from "cuid";
import crypto from "crypto";
import type { FastifyReply, FastifyRequest } from "fastify";
import { encrypt, errorHandler, isDev, listSettings, prisma } from "../../../lib/common";
import { checkContainer, removeContainer } from "../../../lib/docker";
import { scheduler } from "../../../lib/scheduler";
import { getApplicationFromDB, getApplicationFromDBWebhook } from "../../api/v1/applications/handlers";

export async function configureGitLabApp(request: FastifyRequest, reply: FastifyReply) {
    try {
        const { code, state } = request.query;
        const { fqdn } = await listSettings();
        const { gitSource: { gitlabApp: { appId, appSecret }, htmlUrl } } = await getApplicationFromDB(state, undefined);

        let domain = `http://${request.hostname}`;
        if (fqdn) domain = fqdn;
        if (isDev) {
            domain = `http://localhost:3001`;
        }
        const params = new URLSearchParams({
            client_id: appId,
            client_secret: appSecret,
            code,
            state,
            grant_type: 'authorization_code',
            redirect_uri: `${domain}/webhooks/gitlab`
        });
        const { data } = await axios.post(`${htmlUrl}/oauth/token`, params)
        if (isDev) {
            return reply.redirect(`http://localhost:3000/webhooks/success?token=${data.access_token}`)
        }
        return reply.redirect(`/webhooks/success?token=${data.access_token}`)
    } catch ({ status, message, ...other }) {
        console.log(other)
        return errorHandler({ status, message })
    }
}
export async function gitLabEvents(request: FastifyRequest, reply: FastifyReply) {
    try {
        const buildId = cuid();

        const allowedActions = ['opened', 'reopen', 'close', 'open', 'update'];
        const { object_kind: objectKind, ref, project_id } = request.body
        const webhookToken = request.headers['x-gitlab-token'];
        if (!webhookToken) {
            throw { status: 500, message: 'Invalid webhookToken.' }
        }
        if (objectKind === 'push') {
            const projectId = Number(project_id);
            const branch = ref.split('/')[2];
            const applicationFound = await getApplicationFromDBWebhook(projectId, branch);
            if (applicationFound) {
                if (!applicationFound.configHash) {
                    const configHash = crypto
                        .createHash('sha256')
                        .update(
                            JSON.stringify({
                                buildPack: applicationFound.buildPack,
                                port: applicationFound.port,
                                exposePort: applicationFound.exposePort,
                                installCommand: applicationFound.installCommand,
                                buildCommand: applicationFound.buildCommand,
                                startCommand: applicationFound.startCommand
                            })
                        )
                        .digest('hex');
                    await prisma.application.updateMany({
                        where: { branch, projectId },
                        data: { configHash }
                    });
                }
                await prisma.application.update({
                    where: { id: applicationFound.id },
                    data: { updatedAt: new Date() }
                });
                await prisma.build.create({
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

                scheduler.workers.get('deployApplication').postMessage({
                    build_id: buildId,
                    type: 'webhook_commit',
                    ...applicationFound
                });

                return {
                    message: 'Queued. Thank you!'
                };

            }
        } else if (objectKind === 'merge_request') {
            const { object_attributes: { work_in_progress: isDraft, action, source_branch: sourceBranch, target_branch: targetBranch, iid: pullmergeRequestId }, project: { id } } = request.body

            const projectId = Number(id);
            if (!allowedActions.includes(action)) {
                throw { status: 500, message: 'Action not allowed.' }
            }
            if (isDraft) {
                throw { status: 500, message: 'Draft MR, do nothing.' }
            }

            const applicationFound = await getApplicationFromDBWebhook(projectId, targetBranch);
            if (applicationFound) {
                if (applicationFound.settings.previews) {
                    if (applicationFound.destinationDockerId) {
                        const isRunning = await checkContainer(
                            applicationFound.destinationDocker.engine,
                            applicationFound.id
                        );
                        if (!isRunning) {
                            throw { status: 500, message: 'Application not running.' }
                        }
                    }
                    if (!isDev && applicationFound.gitSource.gitlabApp.webhookToken !== webhookToken) {
                        throw { status: 500, message: 'Invalid webhookToken. Are you doing something nasty?!' }
                    }
                    if (
                        action === 'opened' ||
                        action === 'reopen' ||
                        action === 'open' ||
                        action === 'update'
                    ) {
                        await prisma.application.update({
                            where: { id: applicationFound.id },
                            data: { updatedAt: new Date() }
                        });
                        await prisma.build.create({
                            data: {
                                id: buildId,
                                applicationId: applicationFound.id,
                                destinationDockerId: applicationFound.destinationDocker.id,
                                gitSourceId: applicationFound.gitSource.id,
                                githubAppId: applicationFound.gitSource.githubApp?.id,
                                gitlabAppId: applicationFound.gitSource.gitlabApp?.id,
                                status: 'queued',
                                type: 'webhook_mr'
                            }
                        });
                        scheduler.workers.get('deployApplication').postMessage({
                            build_id: buildId,
                            type: 'webhook_mr',
                            ...applicationFound,
                            sourceBranch,
                            pullmergeRequestId
                        });

                        return {
                            message: 'Queued. Thank you!'
                        };
                    } else if (action === 'close') {
                        if (applicationFound.destinationDockerId) {
                            const id = `${applicationFound.id}-${pullmergeRequestId}`;
                            const engine = applicationFound.destinationDocker.engine;
                            await removeContainer({ id, engine });
                        }
                        return {
                            message: 'Removed preview. Thank you!'
                        };
                    }
                }
                throw { status: 500, message: 'Merge request previews are not enabled.' }
            }
        }
        throw { status: 500, message: 'Not handled event.' }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}