import cuid from 'cuid';
import crypto from 'node:crypto'
import jsonwebtoken from 'jsonwebtoken';
import axios from 'axios';
import { FastifyReply } from 'fastify';
import { day } from '../../../../lib/dayjs';
import { setDefaultBaseImage, setDefaultConfiguration } from '../../../../lib/buildPacks/common';
import { checkDomainsIsValidInDNS, checkDoubleBranch, decrypt, encrypt, errorHandler, executeDockerCmd, generateSshKeyPair, getContainerUsage, getDomain, getFreeExposedPort, isDev, isDomainConfigured, prisma, stopBuild, uniqueName } from '../../../../lib/common';
import { checkContainer, dockerInstance, isContainerExited, removeContainer } from '../../../../lib/docker';
import { scheduler } from '../../../../lib/scheduler';

import type { FastifyRequest } from 'fastify';
import type { GetImages, CancelDeployment, CheckDNS, CheckRepository, DeleteApplication, DeleteSecret, DeleteStorage, GetApplicationLogs, GetBuildIdLogs, GetBuildLogs, SaveApplication, SaveApplicationSettings, SaveApplicationSource, SaveDeployKey, SaveDestination, SaveSecret, SaveStorage, DeployApplication, CheckDomain } from './types';
import { OnlyId } from '../../../../types';

export async function listApplications(request: FastifyRequest) {
    try {
        const { teamId } = request.user
        const applications = await prisma.application.findMany({
            where: { teams: { some: { id: teamId === '0' ? undefined : teamId } } },
            include: { teams: true, destinationDocker: true }
        });
        const settings = await prisma.setting.findFirst()
        return {
            applications,
            settings
        }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function getImages(request: FastifyRequest<GetImages>) {
    try {
        const { buildPack, deploymentType } = request.body
        let publishDirectory = undefined;
        let port = undefined
        const { baseImage, baseBuildImage, baseBuildImages, baseImages, } = setDefaultBaseImage(
            buildPack, deploymentType
        );
        if (buildPack === 'nextjs') {
            if (deploymentType === 'static') {
                publishDirectory = 'out'
                port = '80'
            } else {
                publishDirectory = ''
                port = '3000'
            }
        }
        if (buildPack === 'nuxtjs') {
            if (deploymentType === 'static') {
                publishDirectory = 'dist'
                port = '80'
            } else {
                publishDirectory = ''
                port = '3000'
            }
        }


        return { baseBuildImage, baseBuildImages, publishDirectory, port }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function getApplicationStatus(request: FastifyRequest<OnlyId>) {
    try {
        const { id } = request.params
        const { teamId } = request.user
        let isRunning = false;
        let isExited = false;

        const application: any = await getApplicationFromDB(id, teamId);
        if (application?.destinationDockerId) {
            isRunning = await checkContainer({ dockerId: application.destinationDocker.id, container: id });
            isExited = await isContainerExited(application.destinationDocker.id, id);
        }
        return {
            isQueueActive: scheduler.workers.has('deployApplication'),
            isRunning,
            isExited,
        };
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function getApplication(request: FastifyRequest<OnlyId>) {
    try {
        const { id } = request.params
        const { teamId } = request.user
        const appId = process.env['COOLIFY_APP_ID'];
        const application: any = await getApplicationFromDB(id, teamId);

        return {
            application,
            appId
        };

    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function newApplication(request: FastifyRequest, reply: FastifyReply) {
    try {
        const name = uniqueName();
        const { teamId } = request.user
        const { id } = await prisma.application.create({
            data: {
                name,
                teams: { connect: { id: teamId } },
                settings: { create: { debug: false, previews: false } }
            }
        });
        return reply.code(201).send({ id });
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
function decryptApplication(application: any) {
    if (application) {
        if (application?.gitSource?.githubApp?.clientSecret) {
            application.gitSource.githubApp.clientSecret = decrypt(application.gitSource.githubApp.clientSecret) || null;
        }
        if (application?.gitSource?.githubApp?.webhookSecret) {
            application.gitSource.githubApp.webhookSecret = decrypt(application.gitSource.githubApp.webhookSecret) || null;
        }
        if (application?.gitSource?.githubApp?.privateKey) {
            application.gitSource.githubApp.privateKey = decrypt(application.gitSource.githubApp.privateKey) || null;
        }
        if (application?.gitSource?.gitlabApp?.appSecret) {
            application.gitSource.gitlabApp.appSecret = decrypt(application.gitSource.gitlabApp.appSecret) || null;
        }
        if (application?.secrets.length > 0) {
            application.secrets = application.secrets.map((s: any) => {
                s.value = decrypt(s.value) || null
                return s;
            });
        }

        return application;
    }
}
export async function getApplicationFromDB(id: string, teamId: string) {
    try {
        let application = await prisma.application.findFirst({
            where: { id, teams: { some: { id: teamId === '0' ? undefined : teamId } } },
            include: {
                destinationDocker: true,
                settings: true,
                gitSource: { include: { githubApp: true, gitlabApp: true } },
                secrets: true,
                persistentStorage: true
            }
        });
        if (!application) {
            throw { status: 404, message: 'Application not found.' };
        }
        application = decryptApplication(application);
        const buildPack = application?.buildPack || null;
        const { baseImage, baseBuildImage, baseBuildImages, baseImages } = setDefaultBaseImage(
            buildPack
        );

        // Set default build images
        if (!application.baseImage) {
            application.baseImage = baseImage;
        }
        if (!application.baseBuildImage) {
            application.baseBuildImage = baseBuildImage;
        }
        return { ...application, baseBuildImages, baseImages };

    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function getApplicationFromDBWebhook(projectId: number, branch: string) {
    try {
        let application = await prisma.application.findFirst({
            where: { projectId, branch, settings: { autodeploy: true } },
            include: {
                destinationDocker: true,
                settings: true,
                gitSource: { include: { githubApp: true, gitlabApp: true } },
                secrets: true,
                persistentStorage: true
            }
        });
        if (!application) {
            throw { status: 500, message: 'Application not configured.' }
        }
        application = decryptApplication(application);
        const { baseImage, baseBuildImage, baseBuildImages, baseImages } = setDefaultBaseImage(
            application.buildPack
        );

        // Set default build images
        if (!application.baseImage) {
            application.baseImage = baseImage;
        }
        if (!application.baseBuildImage) {
            application.baseBuildImage = baseBuildImage;
        }
        return { ...application, baseBuildImages, baseImages };

    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function saveApplication(request: FastifyRequest<SaveApplication>, reply: FastifyReply) {
    try {
        const { id } = request.params
        let {
            name,
            buildPack,
            fqdn,
            port,
            exposePort,
            installCommand,
            buildCommand,
            startCommand,
            baseDirectory,
            publishDirectory,
            pythonWSGI,
            pythonModule,
            pythonVariable,
            dockerFileLocation,
            denoMainFile,
            denoOptions,
            baseImage,
            baseBuildImage,
            deploymentType
        } = request.body

        if (port) port = Number(port);
        if (exposePort) {
            exposePort = Number(exposePort);
        }
        if (denoOptions) denoOptions = denoOptions.trim();
        const defaultConfiguration = await setDefaultConfiguration({
            buildPack,
            port,
            installCommand,
            startCommand,
            buildCommand,
            publishDirectory,
            baseDirectory,
            dockerFileLocation,
            denoMainFile
        });
        await prisma.application.update({
            where: { id },
            data: {
                name,
                fqdn,
                exposePort,
                pythonWSGI,
                pythonModule,
                pythonVariable,
                denoOptions,
                baseImage,
                baseBuildImage,
                deploymentType,
                ...defaultConfiguration
            }
        });
        return reply.code(201).send();
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }

}

export async function saveApplicationSettings(request: FastifyRequest<SaveApplicationSettings>, reply: FastifyReply) {
    try {
        const { id } = request.params
        const { debug, previews, dualCerts, autodeploy, branch, projectId } = request.body
        const isDouble = await checkDoubleBranch(branch, projectId);
        if (isDouble && autodeploy) {
            await prisma.applicationSettings.updateMany({ where: { application: { branch, projectId } }, data: { autodeploy: false } })
            throw { status: 500, message: 'Cannot activate automatic deployments until only one application is defined for this repository / branch.' }
        }
        await prisma.application.update({
            where: { id },
            data: { settings: { update: { debug, previews, dualCerts, autodeploy } } },
            include: { destinationDocker: true }
        });
        return reply.code(201).send();
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function stopApplication(request: FastifyRequest<OnlyId>, reply: FastifyReply) {
    try {
        const { id } = request.params
        const { teamId } = request.user
        const application: any = await getApplicationFromDB(id, teamId);
        if (application?.destinationDockerId) {
            const { id: dockerId } = application.destinationDocker;
            const found = await checkContainer({ dockerId, container: id });
            if (found) {
                await removeContainer({ id, dockerId: application.destinationDocker.id });
            }
        }
        return reply.code(201).send();
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function deleteApplication(request: FastifyRequest<DeleteApplication>, reply: FastifyReply) {
    try {
        const { id } = request.params
        const { teamId } = request.user
        const application = await prisma.application.findUnique({
            where: { id },
            include: { destinationDocker: true }
        });
        if (application?.destinationDockerId && application.destinationDocker?.network) {
            const { stdout: containers } = await executeDockerCmd({
                dockerId: application.destinationDocker.id,
                command: `docker ps -a --filter network=${application.destinationDocker.network} --filter name=${id} --format '{{json .}}'`
            })
            if (containers) {
                const containersArray = containers.trim().split('\n');
                for (const container of containersArray) {
                    const containerObj = JSON.parse(container);
                    const id = containerObj.ID;
                    await removeContainer({ id, dockerId: application.destinationDocker.id });
                }
            }
        }
        await prisma.applicationSettings.deleteMany({ where: { application: { id } } });
        await prisma.buildLog.deleteMany({ where: { applicationId: id } });
        await prisma.build.deleteMany({ where: { applicationId: id } });
        await prisma.secret.deleteMany({ where: { applicationId: id } });
        await prisma.applicationPersistentStorage.deleteMany({ where: { applicationId: id } });
        if (teamId === '0') {
            await prisma.application.deleteMany({ where: { id } });
        } else {
            await prisma.application.deleteMany({ where: { id, teams: { some: { id: teamId } } } });
        }
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function checkDomain(request: FastifyRequest<CheckDomain>) {
    try {
        const { id } = request.params
        const { domain } = request.query
        const { fqdn, settings: { dualCerts } } = await prisma.application.findUnique({ where: { id }, include: { settings: true } })
        return await checkDomainsIsValidInDNS({ hostname: domain, fqdn, dualCerts });
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function checkDNS(request: FastifyRequest<CheckDNS>) {
    try {
        const { id } = request.params

        let { exposePort, fqdn, forceSave, dualCerts } = request.body

        if (fqdn) fqdn = fqdn.toLowerCase();
        if (exposePort) exposePort = Number(exposePort);

        const { destinationDocker: { id: dockerId, remoteIpAddress, remoteEngine }, exposePort: configuredPort } = await prisma.application.findUnique({ where: { id }, include: { destinationDocker: true } })
        const { isDNSCheckEnabled } = await prisma.setting.findFirst({});

        const found = await isDomainConfigured({ id, fqdn });
        if (found) {
            throw { status: 500, message: `Domain ${getDomain(fqdn).replace('www.', '')} is already in use!` }
        }
        if (exposePort) {
            if (exposePort < 1024 || exposePort > 65535) {
                throw { status: 500, message: `Exposed Port needs to be between 1024 and 65535.` }
            }

            if (configuredPort !== exposePort) {
                const availablePort = await getFreeExposedPort(id, exposePort, dockerId, remoteIpAddress);
                if (availablePort.toString() !== exposePort.toString()) {
                    throw { status: 500, message: `Port ${exposePort} is already in use.` }
                }
            }
        }
        if (isDNSCheckEnabled && !isDev && !forceSave) {
            let hostname = request.hostname.split(':')[0];
            if (remoteEngine) hostname = remoteIpAddress;
            return await checkDomainsIsValidInDNS({ hostname, fqdn, dualCerts });
        }
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function getUsage(request) {
    try {
        const { id } = request.params
        const teamId = request.user?.teamId;
        let usage = {};

        const application: any = await getApplicationFromDB(id, teamId);
        if (application.destinationDockerId) {
            [usage] = await Promise.all([getContainerUsage(application.destinationDocker.id, id)]);
        }
        return {
            usage
        }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function deployApplication(request: FastifyRequest<DeployApplication>) {
    try {
        const { id } = request.params
        const teamId = request.user?.teamId;
        const { pullmergeRequestId = null, branch } = request.body
        const buildId = cuid();
        const application = await getApplicationFromDB(id, teamId);
        if (application) {
            if (!application?.configHash) {
                const configHash = crypto.createHash('sha256')
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
                await prisma.application.update({ where: { id }, data: { configHash } });
            }
            await prisma.application.update({ where: { id }, data: { updatedAt: new Date() } });
            await prisma.build.create({
                data: {
                    id: buildId,
                    applicationId: id,
                    branch: application.branch,
                    destinationDockerId: application.destinationDocker?.id,
                    gitSourceId: application.gitSource?.id,
                    githubAppId: application.gitSource?.githubApp?.id,
                    gitlabAppId: application.gitSource?.gitlabApp?.id,
                    status: 'queued',
                    type: 'manual'
                }
            });
            if (pullmergeRequestId) {
                scheduler.workers.get('deployApplication').postMessage({
                    build_id: buildId,
                    type: 'manual',
                    ...application,
                    sourceBranch: branch,
                    pullmergeRequestId
                });
            } else {
                scheduler.workers.get('deployApplication').postMessage({
                    build_id: buildId,
                    type: 'manual',
                    ...application
                });

            }
            return {
                buildId
            };
        }
        throw { status: 500, message: 'Application not found!' }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}


export async function saveApplicationSource(request: FastifyRequest<SaveApplicationSource>, reply: FastifyReply) {
    try {
        const { id } = request.params
        const { gitSourceId } = request.body
        await prisma.application.update({
            where: { id },
            data: { gitSource: { connect: { id: gitSourceId } } }
        });
        return reply.code(201).send()
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function getGitHubToken(request: FastifyRequest<OnlyId>, reply: FastifyReply) {
    try {
        const { id } = request.params
        const { teamId } = request.user
        const application: any = await getApplicationFromDB(id, teamId);
        const payload = {
            iat: Math.round(new Date().getTime() / 1000),
            exp: Math.round(new Date().getTime() / 1000 + 60),
            iss: application.gitSource.githubApp.appId
        };
        const githubToken = jsonwebtoken.sign(payload, application.gitSource.githubApp.privateKey, {
            algorithm: 'RS256'
        });
        const { data } = await axios.post(`${application.gitSource.apiUrl}/app/installations/${application.gitSource.githubApp.installationId}/access_tokens`, {}, {
            headers: {
                Authorization: `Bearer ${githubToken}`
            }
        })
        return reply.code(201).send({
            token: data.token
        })
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function checkRepository(request: FastifyRequest<CheckRepository>) {
    try {
        const { id } = request.params
        const { repository, branch } = request.query
        const application = await prisma.application.findUnique({
            where: { id },
            include: { gitSource: true }
        });
        const found = await prisma.application.findFirst({
            where: { branch, repository, gitSource: { type: application.gitSource.type }, id: { not: id } }
        });
        return {
            used: found ? true : false
        };
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function saveRepository(request, reply) {
    try {
        const { id } = request.params
        let { repository, branch, projectId, autodeploy, webhookToken } = request.body

        repository = repository.toLowerCase();
        branch = branch.toLowerCase();
        projectId = Number(projectId);
        if (webhookToken) {
            await prisma.application.update({
                where: { id },
                data: { repository, branch, projectId, gitSource: { update: { gitlabApp: { update: { webhookToken: webhookToken ? webhookToken : undefined } } } }, settings: { update: { autodeploy } } }
            });
        } else {
            await prisma.application.update({
                where: { id },
                data: { repository, branch, projectId, settings: { update: { autodeploy } } }
            });
        }
        const isDouble = await checkDoubleBranch(branch, projectId);
        if (isDouble) {
            await prisma.applicationSettings.updateMany({ where: { application: { branch, projectId } }, data: { autodeploy: false } })
        }
        return reply.code(201).send()
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function saveDestination(request: FastifyRequest<SaveDestination>, reply: FastifyReply) {
    try {
        const { id } = request.params
        const { destinationId } = request.body
        await prisma.application.update({
            where: { id },
            data: { destinationDocker: { connect: { id: destinationId } } }
        });
        return reply.code(201).send()
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function getBuildPack(request) {
    try {
        const { id } = request.params
        const teamId = request.user?.teamId;
        const application: any = await getApplicationFromDB(id, teamId);
        return {
            type: application.gitSource.type,
            projectId: application.projectId,
            repository: application.repository,
            branch: application.branch,
            apiUrl: application.gitSource.apiUrl
        }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function saveBuildPack(request, reply) {
    try {
        const { id } = request.params
        const { buildPack } = request.body
        await prisma.application.update({ where: { id }, data: { buildPack } });
        return reply.code(201).send()
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function getSecrets(request: FastifyRequest<OnlyId>) {
    try {
        const { id } = request.params
        let secrets = await prisma.secret.findMany({
            where: { applicationId: id },
            orderBy: { createdAt: 'desc' }
        });
        secrets = secrets.map((secret) => {
            secret.value = decrypt(secret.value);
            return secret;
        });
        secrets = secrets.filter((secret) => !secret.isPRMRSecret).sort((a, b) => {
            return ('' + a.name).localeCompare(b.name);
        })
        return {
            secrets
        }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function saveSecret(request: FastifyRequest<SaveSecret>, reply: FastifyReply) {
    try {
        const { id } = request.params
        let { name, value, isBuildSecret, isPRMRSecret, isNew } = request.body

        if (isNew) {
            const found = await prisma.secret.findFirst({ where: { name, applicationId: id, isPRMRSecret } });
            if (found) {
                throw { status: 500, message: `Secret ${name} already exists.` }
            } else {
                value = encrypt(value);
                await prisma.secret.create({
                    data: { name, value, isBuildSecret, isPRMRSecret, application: { connect: { id } } }
                });
            }
        } else {
            value = encrypt(value);
            const found = await prisma.secret.findFirst({ where: { applicationId: id, name, isPRMRSecret } });

            if (found) {
                await prisma.secret.updateMany({
                    where: { applicationId: id, name, isPRMRSecret },
                    data: { value, isBuildSecret, isPRMRSecret }
                });
            } else {
                await prisma.secret.create({
                    data: { name, value, isBuildSecret, isPRMRSecret, application: { connect: { id } } }
                });
            }
        }
        return reply.code(201).send()
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function deleteSecret(request: FastifyRequest<DeleteSecret>) {
    try {
        const { id } = request.params
        const { name } = request.body
        await prisma.secret.deleteMany({ where: { applicationId: id, name } });
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function getStorages(request: FastifyRequest<OnlyId>) {
    try {
        const { id } = request.params
        const persistentStorages = await prisma.applicationPersistentStorage.findMany({ where: { applicationId: id } });
        return {
            persistentStorages
        }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function saveStorage(request: FastifyRequest<SaveStorage>, reply: FastifyReply) {
    try {
        const { id } = request.params
        const { path, newStorage, storageId } = request.body

        if (newStorage) {
            await prisma.applicationPersistentStorage.create({
                data: { path, application: { connect: { id } } }
            });
        } else {
            await prisma.applicationPersistentStorage.update({
                where: { id: storageId },
                data: { path }
            });
        }
        return reply.code(201).send()
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function deleteStorage(request: FastifyRequest<DeleteStorage>) {
    try {
        const { id } = request.params
        const { path } = request.body
        await prisma.applicationPersistentStorage.deleteMany({ where: { applicationId: id, path } });
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function getPreviews(request: FastifyRequest<OnlyId>) {
    try {
        const { id } = request.params
        const { teamId } = request.user
        let secrets = await prisma.secret.findMany({
            where: { applicationId: id },
            orderBy: { createdAt: 'desc' }
        });
        secrets = secrets.map((secret) => {
            secret.value = decrypt(secret.value);
            return secret;
        });
        const applicationSecrets = secrets.filter((secret) => !secret.isPRMRSecret);
        const PRMRSecrets = secrets.filter((secret) => secret.isPRMRSecret);
        const destinationDocker = await prisma.destinationDocker.findFirst({
            where: { application: { some: { id } }, teams: { some: { id: teamId } } }
        });
        const docker = dockerInstance({ destinationDocker });
        const listContainers = await docker.engine.listContainers({
            filters: { network: [destinationDocker.network], name: [id] }
        });
        const containers = listContainers.filter((container) => {
            return (
                container.Labels['coolify.configuration'] &&
                container.Labels['coolify.type'] === 'standalone-application'
            );
        });
        const jsonContainers = containers
            .map((container) =>
                JSON.parse(Buffer.from(container.Labels['coolify.configuration'], 'base64').toString())
            )
            .filter((container) => {
                return container.pullmergeRequestId && container.applicationId === id;
            });
        return {
            containers: jsonContainers,
            applicationSecrets: applicationSecrets.sort((a, b) => {
                return ('' + a.name).localeCompare(b.name);
            }),
            PRMRSecrets: PRMRSecrets.sort((a, b) => {
                return ('' + a.name).localeCompare(b.name);
            })
        }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function getApplicationLogs(request: FastifyRequest<GetApplicationLogs>) {
    try {
        const { id } = request.params;
        let { since = 0 } = request.query
        if (since !== 0) {
            since = day(since).unix();
        }
        const { destinationDockerId, destinationDocker: { id: dockerId } } = await prisma.application.findUnique({
            where: { id },
            include: { destinationDocker: true }
        });
        if (destinationDockerId) {
            try {
                // const found = await checkContainer({ dockerId, container: id })
                // if (found) {
                const { default: ansi } = await import('strip-ansi')
                const { stdout, stderr } = await executeDockerCmd({ dockerId, command: `docker logs --since ${since} --tail 5000 --timestamps ${id}` })
                const stripLogsStdout = stdout.toString().split('\n').map((l) => ansi(l)).filter((a) => a);
                const stripLogsStderr = stderr.toString().split('\n').map((l) => ansi(l)).filter((a) => a);
                const logs = stripLogsStderr.concat(stripLogsStdout)
                const sortedLogs = logs.sort((a, b) => (day(a.split(' ')[0]).isAfter(day(b.split(' ')[0])) ? 1 : -1))
                return { logs: sortedLogs }
                // }
            } catch (error) {
                const { statusCode } = error;
                if (statusCode === 404) {
                    return {
                        logs: []
                    };
                }
            }
        }
        return {
            message: 'No logs found.'
        }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function getBuildLogs(request: FastifyRequest<GetBuildLogs>) {
    try {
        const { id } = request.params
        let { buildId, skip = 0 } = request.query
        if (typeof skip !== 'number') {
            skip = Number(skip)
        }

        let builds = [];

        const buildCount = await prisma.build.count({ where: { applicationId: id } });
        if (buildId) {
            builds = await prisma.build.findMany({ where: { applicationId: id, id: buildId } });
        } else {
            builds = await prisma.build.findMany({
                where: { applicationId: id },
                orderBy: { createdAt: 'desc' },
                take: 5,
                skip
            });
        }

        builds = builds.map((build) => {
            const updatedAt = day(build.updatedAt).utc();
            build.took = updatedAt.diff(day(build.createdAt)) / 1000;
            build.since = updatedAt.fromNow();
            return build;
        });
        return {
            builds,
            buildCount
        };
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function getBuildIdLogs(request: FastifyRequest<GetBuildIdLogs>) {
    try {
        const { buildId } = request.params
        let { sequence = 0 } = request.query
        if (typeof sequence !== 'number') {
            sequence = Number(sequence)
        }
        let logs = await prisma.buildLog.findMany({
            where: { buildId, time: { gt: sequence } },
            orderBy: { time: 'asc' }
        });
        const data = await prisma.build.findFirst({ where: { id: buildId } });
        return {
            logs,
            status: data?.status || 'queued'
        }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function getGitLabSSHKey(request: FastifyRequest<OnlyId>) {
    try {
        const { id } = request.params
        const application = await prisma.application.findUnique({
            where: { id },
            include: { gitSource: { include: { gitlabApp: true } } }
        });
        return { publicKey: application.gitSource.gitlabApp.publicSshKey };
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function saveGitLabSSHKey(request: FastifyRequest<OnlyId>, reply: FastifyReply) {
    try {
        const { id } = request.params
        const application = await prisma.application.findUnique({
            where: { id },
            include: { gitSource: { include: { gitlabApp: true } } }
        });
        if (!application.gitSource?.gitlabApp?.privateSshKey) {
            const keys = await generateSshKeyPair();
            const encryptedPrivateKey = encrypt(keys.privateKey);
            await prisma.gitlabApp.update({
                where: { id: application.gitSource.gitlabApp.id },
                data: { privateSshKey: encryptedPrivateKey, publicSshKey: keys.publicKey }
            });
            return reply.code(201).send({ publicKey: keys.publicKey })
        }
        return { message: 'SSH key already exists' }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function saveDeployKey(request: FastifyRequest<SaveDeployKey>, reply: FastifyReply) {
    try {
        const { id } = request.params
        let { deployKeyId } = request.body;

        deployKeyId = Number(deployKeyId);
        const application = await prisma.application.findUnique({
            where: { id },
            include: { gitSource: { include: { gitlabApp: true } } }
        });
        await prisma.gitlabApp.update({
            where: { id: application.gitSource.gitlabApp.id },
            data: { deployKeyId }
        });
        return reply.code(201).send()
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function cancelDeployment(request: FastifyRequest<CancelDeployment>, reply: FastifyReply) {
    try {
        const { buildId, applicationId } = request.body;
        if (!buildId) {
            throw { status: 500, message: 'buildId is required' }

        }
        await stopBuild(buildId, applicationId);
        return reply.code(201).send()
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}