import child from 'child_process'
import util from 'util'
import { buildLogQueue } from './queues'
import * as db from '$lib/database';

export const asyncExecShell = util.promisify(child.exec)
export const asyncSleep = (delay) => new Promise((resolve) => setTimeout(resolve, delay))

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
    const teamId = getTeam(request)
    const userId = request.locals.session.data.uid || null
    const { permission = 'read' } = await db.prisma.permission.findFirst({ where: { teamId, userId }, select: { permission: true } })
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
}