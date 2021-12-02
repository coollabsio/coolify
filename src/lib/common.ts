import child from 'child_process'
import util from 'util'
import { buildLogQueue } from './queues'
import * as db from '$lib/database';
import * as Sentry from '@sentry/node';

Sentry.init({
    dsn: process.env['SENTRY_DSN'],
    tracesSampleRate: 0,
});

export const asyncExecShell = util.promisify(child.exec)
export const asyncSleep = (delay) => new Promise((resolve) => setTimeout(resolve, delay))
export const sentry = Sentry

export const saveBuildLog = async ({ line, buildId, applicationId }) => {
    await buildLogQueue.add(buildId, { buildId, line, applicationId })
}

export const isTeamIdTokenAvailable = (request) => {
    if (!request.headers.cookie?.split(';').map(s => s.trim()).find(s => s.startsWith('teamId='))?.split('=')[1]) {
        return getTeam(request)
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
        if (isAdminRequired && permission !== 'admin') {
            payload.status = 401
            payload.body.message = 'You do not have permission to do this. \nAsk an admin to modify your permissions.'
        }

        return payload
    } catch (err) {
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