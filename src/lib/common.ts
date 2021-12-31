import child from 'child_process'
import util from 'util'
import { dev } from '$app/env';
import * as Sentry from '@sentry/node';
import { uniqueNamesGenerator, Config, adjectives, colors, animals, names, starWars } from 'unique-names-generator';

import * as db from '$lib/database';
import { buildLogQueue } from './queues'

import { version as currentVersion } from '../../package.json';
import { dockerInstance } from './docker';
import { removeProxyConfiguration } from './haproxy';

try {
    if (!dev) {
        Sentry.init({
            dsn: process.env['COOLIFY_SENTRY_DSN'],
            tracesSampleRate: 0,
        });
    }
} catch (err) {
    console.log('Could not initialize Sentry')
    console.log(err)
}


const customConfig: Config = {
    dictionaries: [adjectives, colors, animals],
    style: 'capital',
    separator: ' ',
    length: 3,
};

export const version = currentVersion;
export const asyncExecShell = util.promisify(child.exec)
export const asyncSleep = (delay) => new Promise((resolve) => setTimeout(resolve, delay))
export const sentry = Sentry

export const uniqueName = () => uniqueNamesGenerator(customConfig);

export const saveBuildLog = async ({ line, buildId, applicationId }) => {
    await buildLogQueue.add(buildId, { buildId, line, applicationId })
}

export const isTeamIdTokenAvailable = (request) => {
    const cookie = request.headers.cookie?.split(';').map(s => s.trim()).find(s => s.startsWith('teamId='))?.split('=')[1]
    if (!cookie) {
        return getTeam(request)
    } else {
        return cookie
    }
}

export const getTeam = (request) => {
    const teamIdCookie = request.headers.cookie?.split(';').map(s => s.trim()).find(s => s.startsWith('teamId='))?.split('=')[1]
    if (teamIdCookie) { return teamIdCookie }

    const teamIdSession = request.locals.session.data.teamId
    if (teamIdSession) { return teamIdSession }

    return null
}

export const getUserDetails = async (request, isAdminRequired = true) => {
    try {
        const teamId = getTeam(request)
        const userId = request.locals.session.data.uid || null
        const { permission = 'read' } = await db.prisma.permission.findFirst({ where: { teamId, userId }, select: { permission: true }, rejectOnNotFound: true })
        const payload = {
            teamId,
            userId,
            permission,
            status: 200,
            body: {
                message: 'OK'
            }
        }
        if (isAdminRequired && permission !== 'admin' && permission !== 'owner') {
            payload.status = 401
            payload.body.message = 'You do not have permission to do this. \nAsk an admin to modify your permissions.'
        }

        return payload
    } catch (err) {
        console.log(err)
        return {
            teamId: null,
            userId: null,
            permission: 'read',
            status: 401,
            body: {
                message: 'You do not have permission to do this. \nAsk an admin to modify your permissions.'
            }
        }
    }

}

export function getHost({ engine }) {
    return engine === '/var/run/docker.sock' ? 'unix:///var/run/docker.sock' : `tcp://${engine}:2375`
}

export const removeDestinationDocker = async ({ id, destinationDocker }) => {
    const docker = dockerInstance({ destinationDocker })
    await docker.engine.getContainer(id).stop()
    await docker.engine.getContainer(id).remove()
}

export const removePreviewDestinationDocker = async ({ id, destinationDocker, pullmergeRequestId }) => {
    try {
        const docker = dockerInstance({ destinationDocker })
        await docker.engine.getContainer(`${id}-${pullmergeRequestId}`).stop()
        await docker.engine.getContainer(`${id}-${pullmergeRequestId}`).remove()
    } catch(error) {
        if (error.statusCode === 404) {
            throw {
                    message: 'Nothing to do.'
            }
        }
        throw error
    }

}

export const removeAllPreviewsDestinationDocker = async ({ id, destinationDocker }) => {
    const docker = dockerInstance({ destinationDocker })
    const listContainers = await docker.engine.listContainers({ filters: { network: [destinationDocker.network] } })
    const containers = listContainers.filter((container) => {
        return container.Image.startsWith(id)
    })
    const previews = []
    for (const container of containers) {
        const preview = container.Image.split('-')[1]
        if (preview) previews.push(preview)
        await docker.engine.getContainer(container.Id).stop()
        await docker.engine.getContainer(container.Id).remove()
    }
    return previews
}

export const createDirectories = async ({ repository, buildId }) => {
    const repodir = `/tmp/build-sources/${repository}/`
    const workdir = `/tmp/build-sources/${repository}/${buildId}`

    await asyncExecShell(`mkdir -p ${workdir}`)

    return {
        workdir, repodir
    }
}

export const setDefaultConfiguration = async ({ buildPack, port, installCommand, startCommand }) => {
    if (!port) port = 3000

    if (buildPack === 'node') {
        if (!installCommand) installCommand = 'yarn install'
        if (!startCommand) startCommand = 'yarn start'
    }
    if (buildPack === 'static') {
        port = 80
    }
    return {
        buildPack,
        port,
        installCommand,
        startCommand
    }
}