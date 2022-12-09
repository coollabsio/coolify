import cuid from "cuid";
import crypto from "crypto";
import type { FastifyReply, FastifyRequest } from "fastify";
import { errorHandler, getAPIUrl, getDomain, getUIUrl, isDev, listSettings, prisma } from "../../../lib/common";
import { checkContainer, removeContainer } from "../../../lib/docker";
import { getApplicationFromDB, getApplicationFromDBWebhook } from "../../api/v1/applications/handlers";

import type { ConfigureGitLabApp, GitLabEvents } from "./types";

export async function configureGitLabApp(request: FastifyRequest<ConfigureGitLabApp>, reply: FastifyReply) {
    try {
        const { default: got } = await import('got')
        const { code, state } = request.query;
        const { fqdn } = await listSettings();
        const { gitSource: { gitlabApp: { appId, appSecret }, htmlUrl } }: any = await getApplicationFromDB(state, undefined);

        let domain = `http://${request.hostname}`;
        if (fqdn) domain = fqdn;
        if (isDev) {
            domain = getAPIUrl();
        }

        const { access_token } = await got.post(`${htmlUrl}/oauth/token`, {
            searchParams: {
                client_id: appId,
                client_secret: appSecret,
                code,
                state,
                grant_type: 'authorization_code',
                redirect_uri: `${domain}/webhooks/gitlab`
            }
        }).json()
        if (isDev) {
            return reply.redirect(`${getUIUrl()}/webhooks/success?token=${access_token}`)
        }
        return reply.redirect(`/webhooks/success?token=${access_token}`)
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
            throw { status: 500, message: 'Invalid webhookToken.', type: 'webhook' }
        }
        const settings = await prisma.setting.findUnique({ where: { id: '0' } });
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
            const { object_attributes: { work_in_progress: isDraft, action, source_branch: sourceBranch, target_branch: targetBranch, source: { path_with_namespace: sourceRepository } }, project: { id } } = request.body
            const pullmergeRequestId = request.body.object_attributes.iid.toString();
            const projectId = Number(id);
            if (!allowedActions.includes(action)) {
                throw { status: 500, message: 'Action not allowed.', type: 'webhook' }
            }
            if (isDraft) {
                throw { status: 500, message: 'Draft MR, do nothing.', type: 'webhook' }
            }
            const applicationsFound = await getApplicationFromDBWebhook(projectId, targetBranch);
            if (applicationsFound && applicationsFound.length > 0) {
                for (const application of applicationsFound) {
                    const buildId = cuid();
                    if (application.settings.previews) {
                        if (application.destinationDockerId) {
                            const { found: isRunning } = await checkContainer(
                                {
                                    dockerId: application.destinationDocker.id,
                                    container: application.id
                                }
                            );
                            if (!isRunning) {
                                throw { status: 500, message: 'Application not running.', type: 'webhook' }
                            }
                        }
                        if (!isDev && application.gitSource.gitlabApp.webhookToken !== webhookToken) {
                            throw { status: 500, message: 'Invalid webhookToken. Are you doing something nasty?!', type: 'webhook' }
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
                            let previewApplicationId = undefined
                            if (pullmergeRequestId) {
                                const foundPreviewApplications = await prisma.previewApplication.findMany({ where: { applicationId: application.id, pullmergeRequestId } })
                                if (foundPreviewApplications.length > 0) {
                                    previewApplicationId = foundPreviewApplications[0].id
                                } else {
                                    const protocol = application.fqdn.includes('https://') ? 'https://' : 'http://'
                                    const previewApplication = await prisma.previewApplication.create({
                                        data: {
                                            pullmergeRequestId,
                                            sourceBranch,
                                            customDomain: `${protocol}${pullmergeRequestId}${settings.previewSeparator}${getDomain(application.fqdn)}`,
                                            application: { connect: { id: application.id } }
                                        }
                                    })
                                    previewApplicationId = previewApplication.id
                                }
                            }
                            await prisma.build.create({
                                data: {
                                    id: buildId,
                                    pullmergeRequestId,
                                    previewApplicationId,
                                    sourceRepository,
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
                                try {
                                    await removeContainer({ id, dockerId: application.destinationDocker.id });
                                } catch (error) { }
                            }
                            const foundPreviewApplications = await prisma.previewApplication.findMany({ where: { applicationId: application.id, pullmergeRequestId } })
                            if (foundPreviewApplications.length > 0) {
                                for (const preview of foundPreviewApplications) {
                                    await prisma.previewApplication.delete({ where: { id: preview.id } })
                                }
                            }
                            return {
                                message: 'MR closed. Thank you!'
                            };

                        }
                    }
                }
            }
        }
    } catch ({ status, message, type }) {
        return errorHandler({ status, message, type })
    }
}