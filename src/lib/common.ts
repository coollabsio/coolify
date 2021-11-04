import child from 'child_process'
import util from 'util'
import { buildLogQueue } from './queues'

export const asyncExecShell = util.promisify(child.exec)
export const asyncSleep = (delay) => new Promise((resolve) => setTimeout(resolve, delay))

export const saveBuildLog = async ({ line, buildId, applicationId }) => {
    await buildLogQueue.add(buildId, { buildId, line, applicationId })
}