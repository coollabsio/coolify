const ApplicationLog = require('../models/Logs/Application')
const ServerLog = require('../models/Logs/Server')
const dayjs = require('dayjs')
function generateTimestamp () {
  return `${dayjs().format('YYYY-MM-DD HH:mm:ss.SSS')} `
}
const patterns = [
  '[\\u001B\\u009B][[\\]()#;?]*(?:(?:(?:[a-zA-Z\\d]*(?:;[-a-zA-Z\\d\\/#&.:=?%@~_]*)*)?\\u0007)',
  '(?:(?:\\d{1,4}(?:;\\d{0,4})*)?[\\dA-PR-TZcf-ntqry=><~]))'
].join('|')

async function saveAppLog (event, configuration, isError) {
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

async function saveServerLog (error) {
  const payload = { message: error.message, stack: error.stack, type: error.type || 'spaghetticode' }
  const found = await ServerLog.find(payload)
  if (found.length === 0) {
    if (error.message) await new ServerLog(payload).save()
  }
}
module.exports = {
  saveAppLog,
  saveServerLog
}
