import axios from "axios";
import cuid from "cuid";
import crypto from "crypto";
import type { FastifyReply, FastifyRequest } from "fastify";
import { errorHandler, getAPIUrl, getUIUrl, isDev, listSettings, prisma } from "../../../lib/common";
import { checkContainer, removeContainer } from "../../../lib/docker";
import { getApplicationFromDB, getApplicationFromDBWebhook } from "../../api/v1/applications/handlers";

import type { ConfigureGitLabApp, GitLabEvents } from "./types";

export async function configureGitLabApp(request: FastifyRequest<ConfigureGitLabApp>, reply: FastifyReply) {
    try {
        const { code, state } = request.query;
        const { fqdn } = await listSettings();
        const { gitSource: { gitlabApp: { appId, appSecret }, htmlUrl } }: any = await getApplicationFromDB(state, undefined);

        let domain = `http://${request.hostname}`;
        if (fqdn) domain = fqdn;
        if (isDev) {
            domain = getAPIUrl();
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
            return reply.redirect(`${getUIUrl()}/webhooks/success?token=${data.access_token}`)
        }
        return reply.redirect(`/webhooks/success?token=${data.access_token}`)
    } catch ({ status, message, ...other }) {
        return errorHandler({ status, message })
    }
}
export async function gitLabEvents(request: FastifyRequest<GitLabEvents>) {
    const { object_kind: objectKind, ref, project_id } = request.body
    try {

        const allowedActions = ['opened', 'reopen', 'close', 'open', 'update'];

        const webhookToken = request.headers['x-gitlab-token'];
        if (!webhookToken && !isDev) {
            throw { status: 500, message: 'Invalid webhookToken.' }
        }
        if (objectKind === 'push') {
            const projectId = Number(project_id);
            const branch = ref.split('/')[2];
            const applicationsFound = await getApplicationFromDBWebhook(projectId, branch);
            if (applicationsFound && applicationsFound.length > 0) {
                for (const application of applicationsFound) {
                    const buildId = cuid();
                    if (!application.configHash) {
                        const configHash = crypto
                            .createHash('sha256')
                            .update(
                                JSON.stringify({
                                    buildPack: application.buildPack,
                                    port: application.port,
                                    exposePort: application.exposePort,
                                    installCommand: application.installCommand,
                                    buildCommand: application.buildCommand,
                                    startCommand: application.startCommand
                                })
                            )
                            .digest('hex');
                        await prisma.application.update({
                            where: { id: application.id },
                            data: { configHash }
                        });
                    }
                    await prisma.application.update({
                        where: { id: application.id },
                        data: { updatedAt: new Date() }
                    });
                    await prisma.build.create({
                        data: {
                            id: buildId,
                            applicationId: application.id,
                            destinationDockerId: application.destinationDocker.id,
                            gitSourceId: application.gitSource.id,
                            githubAppId: application.gitSource.githubApp?.id,
                            gitlabAppId: application.gitSource.gitlabApp?.id,
                            status: 'queued',
                            type: 'webhook_commit'
                        }
                    });
                }
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

            const applicationsFound = await getApplicationFromDBWebhook(projectId, targetBranch);
            if (applicationsFound && applicationsFound.length > 0) {
                for (const application of applicationsFound) {
                    const buildId = cuid();
                    if (application.settings.previews) {
                        if (application.destinationDockerId) {
                            const isRunning = await checkContainer(
                                {
                                    dockerId: application.destinationDocker.id,
                                    container: application.id
                                }
                            );
                            if (!isRunning) {
                                throw { status: 500, message: 'Application not running.' }
                            }
                        }
                        if (!isDev && application.gitSource.gitlabApp.webhookToken !== webhookToken) {
                            throw { status: 500, message: 'Invalid webhookToken. Are you doing something nasty?!' }
                        }
                        if (
                            action === 'opened' ||
                            action === 'reopen' ||
                            action === 'open' ||
                            action === 'update'
                        ) {
                            await prisma.application.update({
                                where: { id: application.id },
                                data: { updatedAt: new Date() }
                            });
                            await prisma.build.create({
                                data: {
                                    id: buildId,
                                    pullmergeRequestId: pullmergeRequestId.toString(),
                                    sourceBranch,
                                    applicationId: application.id,
                                    destinationDockerId: application.destinationDocker.id,
                                    gitSourceId: application.gitSource.id,
                                    githubAppId: application.gitSource.githubApp?.id,
                                    gitlabAppId: application.gitSource.gitlabApp?.id,
                                    status: 'queued',
                                    type: 'webhook_mr'
                                }
                            });
                            return {
                                message: 'Queued. Thank you!'
                            };
                        } else if (action === 'close') {
                            if (application.destinationDockerId) {
                                const id = `${application.id}-${pullmergeRequestId}`;
                                await removeContainer({ id, dockerId: application.destinationDocker.id });
                            }

                        }
                    }
                }
            }
        }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}