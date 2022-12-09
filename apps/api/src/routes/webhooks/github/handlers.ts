import cuid from "cuid";
import crypto from "crypto";
import { encrypt, errorHandler, getDomain, getUIUrl, isDev, prisma } from "../../../lib/common";
import { checkContainer, removeContainer } from "../../../lib/docker";
import { createdBranchDatabase, getApplicationFromDBWebhook, removeBranchDatabase } from "../../api/v1/applications/handlers";

import type { FastifyReply, FastifyRequest } from "fastify";
import type { GitHubEvents, InstallGithub } from "./types";

export async function installGithub(request: FastifyRequest<InstallGithub>, reply: FastifyReply): Promise<any> {
    try {
        const { gitSourceId, installation_id } = request.query;
        const source = await prisma.gitSource.findUnique({
            where: { id: gitSourceId },
            include: { githubApp: true }
        });
        await prisma.githubApp.update({
            where: { id: source.githubAppId },
            data: { installationId: Number(installation_id) }
        });
        if (isDev) {
            return reply.redirect(`${getUIUrl()}/sources/${gitSourceId}`)
        } else {
            return reply.redirect(`/sources/${gitSourceId}`)
        }

    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }

}
export async function configureGitHubApp(request, reply) {
    try {
        const { default: got } = await import('got')
        const { code, state } = request.query;
        const { apiUrl } = await prisma.gitSource.findFirst({
            where: { id: state },
            include: { githubApp: true, gitlabApp: true }
        });

        const data: any = await got.post(`${apiUrl}/app-manifests/${code}/conversions`).json()
        const { id, client_id, slug, client_secret, pem, webhook_secret } = data

        const encryptedClientSecret = encrypt(client_secret);
        const encryptedWebhookSecret = encrypt(webhook_secret);
        const encryptedPem = encrypt(pem);
        await prisma.githubApp.create({
            data: {
                appId: id,
                name: slug,
                clientId: client_id,
                clientSecret: encryptedClientSecret,
                webhookSecret: encryptedWebhookSecret,
                privateKey: encryptedPem,
                gitSource: { connect: { id: state } }
            }
        });
        if (isDev) {
            return reply.redirect(`${getUIUrl()}/sources/${state}`)
        } else {
            return reply.redirect(`/sources/${state}`)
        }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function gitHubEvents(request: FastifyRequest<GitHubEvents>): Promise<any> {
    try {
        const allowedGithubEvents = ['push', 'pull_request', 'ping', 'installation'];
        const allowedActions = ['opened', 'reopened', 'synchronize', 'closed'];
        const githubEvent = request.headers['x-github-event']?.toString().toLowerCase();
        const githubSignature = request.headers['x-hub-signature-256']?.toString().toLowerCase();
        if (!allowedGithubEvents.includes(githubEvent)) {
            throw { status: 500, message: 'Event not allowed.', type: 'webhook' }
        }
        if (githubEvent === 'ping') {
            return { pong: 'cool' }
        }
        if (githubEvent === 'installation') {
            return { status: 'cool' }
        }
        let projectId, branch;
        const body = request.body
        if (githubEvent === 'push') {
            projectId = body.repository.id;
            branch = body.ref.includes('/') ? body.ref.split('/')[2] : body.ref;
        } else if (githubEvent === 'pull_request') {
            projectId = body.pull_request.base.repo.id;
            branch = body.pull_request.base.ref
        }
        if (!projectId || !branch) {
            throw { status: 500, message: 'Cannot parse projectId or branch from the webhook?!', type: 'webhook' }
        }
        const applicationsFound = await getApplicationFromDBWebhook(projectId, branch);
        const settings = await prisma.setting.findUnique({ where: { id: '0' } });
        if (applicationsFound && applicationsFound.length > 0) {
            for (const application of applicationsFound) {
                const buildId = cuid();
                const webhookSecret = application.gitSource.githubApp.webhookSecret || null;
                //@ts-ignore
                const hmac = crypto.createHmac('sha256', webhookSecret);
                const digest = Buffer.from(
                    'sha256=' + hmac.update(JSON.stringify(body)).digest('hex'),
                    'utf8'
                );
                if (!isDev) {
                    const checksum = Buffer.from(githubSignature, 'utf8');
                    //@ts-ignore
                    if (checksum.length !== digest.length || !crypto.timingSafeEqual(digest, checksum)) {
                        throw { status: 500, message: 'SHA256 checksum failed. Are you doing something fishy?', type: 'webhook' }
                    };
                }

                if (githubEvent === 'push') {
                    if (!application.configHash) {
                        const configHash = crypto
                            //@ts-ignore
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
                    console.log(`Webhook for ${application.name} queued.`)

                } else if (githubEvent === 'pull_request') {
                    const pullmergeRequestId = body.number.toString();
                    const pullmergeRequestAction = body.action;
                    const sourceBranch = body.pull_request.head.ref
                    const sourceRepository = body.pull_request.head.repo.full_name
                    if (!allowedActions.includes(pullmergeRequestAction)) {
                        throw { status: 500, message: 'Action not allowed.', type: 'webhook' }
                    }

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
                        if (
                            pullmergeRequestAction === 'opened' ||
                            pullmergeRequestAction === 'reopened' ||
                            pullmergeRequestAction === 'synchronize'
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
                            // if (application.connectedDatabase && pullmergeRequestAction === 'opened' || pullmergeRequestAction === 'reopened') {
                            //     // Coolify hosted database
                            //     if (application.connectedDatabase.databaseId) {
                            //         const databaseId = application.connectedDatabase.databaseId;
                            //         const database = await prisma.database.findUnique({ where: { id: databaseId } });
                            //         if (database) {
                            //             await createdBranchDatabase(database, application.connectedDatabase.hostedDatabaseDBName, pullmergeRequestId);
                            //         }
                            //     }
                            // }
                            await prisma.build.create({
                                data: {
                                    id: buildId,
                                    sourceRepository,
                                    pullmergeRequestId,
                                    previewApplicationId,
                                    sourceBranch,
                                    applicationId: application.id,
                                    destinationDockerId: application.destinationDocker.id,
                                    gitSourceId: application.gitSource.id,
                                    githubAppId: application.gitSource.githubApp?.id,
                                    gitlabAppId: application.gitSource.gitlabApp?.id,
                                    status: 'queued',
                                    type: 'webhook_pr'
                                }
                            });

                            return {
                                message: 'Queued. Thank you!'
                            };
                        } else if (pullmergeRequestAction === 'closed') {
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
                                message: 'PR closed. Thank you!'
                            };
                            // if (application?.connectedDatabase?.databaseId) {
                            //     const databaseId = application.connectedDatabase.databaseId;
                            //     const database = await prisma.database.findUnique({ where: { id: databaseId } });
                            //     if (database) {
                            //         await removeBranchDatabase(database, pullmergeRequestId);
                            //     }
                            // }
                        }
                    }
                }
            }
        }
    } catch ({ status, message, type }) {
        return errorHandler({ status, message, type })
    }

}