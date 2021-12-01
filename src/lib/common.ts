import child from 'child_process'
import util from 'util'
import { buildLogQueue } from './queues'

export const asyncExecShell = util.promisify(child.exec)
export const asyncSleep = (delay) => new Promise((resolve) => setTimeout(resolve, delay))

export const saveBuildLog = async ({ line, buildId, applicationId }) => {
    await buildLogQueue.add(buildId, { buildId, line, applicationId })
}

export const selectTeam = (request) => {
    let selectedTeam = request.headers.cookie?.split(';').map(s => s.trim()).find(s => s.startsWith('selectedTeam='))?.split('=')[1]
    if (!selectedTeam && request.locals.session.data?.teams?.length > 0) {
        selectedTeam = request.locals.session.data.teams[0].id
    }
    return selectedTeam
}