import Settings from "$models/Settings"
import ServerLog from "$models/Logs/Server"
import ApplicationLog from "$models/Logs/Application"
import dayjs from "dayjs"
import { version } from '../../../../package.json'

function generateTimestamp() {
    return `${dayjs().format('YYYY-MM-DD HH:mm:ss.SSS')} `
}
const patterns = [
    '[\\u001B\\u009B][[\\]()#;?]*(?:(?:(?:[a-zA-Z\\d]*(?:;[-a-zA-Z\\d\\/#&.:=?%@~_]*)*)?\\u0007)',
    '(?:(?:\\d{1,4}(?:;\\d{0,4})*)?[\\dA-PR-TZcf-ntqry=><~]))'
].join('|')

export async function saveAppLog(event, configuration, isError?: Boolean) {
    try {
        const deployId = configuration.general.deployId
        const repoId = configuration.repository.id
        const branch = configuration.repository.branch
        if (isError) {
            const clearedEvent = '[ERROR 😱] ' + generateTimestamp() + event.replace(new RegExp(patterns, 'g'), '').replace(/(\r\n|\n|\r)/gm, '')
            await new ApplicationLog({ repoId, branch, deployId, event: clearedEvent }).save()
        } else {
            if (event && event !== '\n') {
                const clearedEvent = '[INFO] ' + generateTimestamp() + event.replace(new RegExp(patterns, 'g'), '').replace(/(\r\n|\n|\r)/gm, '')
                await new ApplicationLog({ repoId, branch, deployId, event: clearedEvent }).save()
            }
        }
    } catch (error) {
        console.log(error)
        return error
    }
}

export async function saveServerLog(error) {
    const settings = await Settings.findOne({ applicationName: 'coolify' })
    const payload = { message: error.message, stack: error.stack, type: error.type || 'spaghetticode', version }

    const found = await ServerLog.find(payload)
    if (found.length === 0 && error.message) await new ServerLog(payload).save()
    if (settings && settings.sendErrors && process.env.NODE_ENV === 'production') {
        await fetch('https://errors.coollabs.io/api/error', { method: 'POST', body: JSON.stringify({ ...payload }) })
    }
}
