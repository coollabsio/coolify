const ApplicationLog = require("../models/Logs/Application");
const ServerLog = require("../models/Logs/Server");
const dayjs = require('dayjs')

function generateTimestamp() {
  return `${dayjs().format('YYYY-MM-DD HH:mm:ss.SSS')} `
}

async function saveAppLog(event, configuration, isError) {
  try {
    const deployId = configuration.general.deployId;
    const repoId = configuration.repository.id;
    const branch = configuration.repository.branch;
    if (isError) {
      // console.log(event, config, isError)
      let clearedEvent = null
      
      if (event.error) clearedEvent = '[ERROR] ' + generateTimestamp() + event.error.replace(/(\r\n|\n|\r)/gm, "")
      else if (event) clearedEvent = '[ERROR] ' + generateTimestamp() + event.replace(/(\r\n|\n|\r)/gm, "")

      try {
        await new ApplicationLog({ repoId, branch, deployId, event: clearedEvent }).save()
      } catch (error) {
        console.log(error);
      }
    } else {
      if (event && event !== "\n") {
        const clearedEvent = '[INFO] ' + generateTimestamp() + event.replace(/(\r\n|\n|\r)/gm, "")
        try {
          await new ApplicationLog({ repoId, branch, deployId, event: clearedEvent }).save()
        } catch (error) {
          console.log(error);
        }
      }
    }

  } catch (error) {
    console.log(error);
    return error;
  }
}

async function saveServerLog(log, configuration) {
  console.log('-------')
  console.log(log)
  if (configuration) {
    const deployId = configuration.general.deployId;
    const repoId = configuration.repository.id;
    const branch = configuration.repository.branch;
    await new ApplicationLog({ repoId, branch, deployId, event: `[SERVER ERROR 😖]: ${log}`}).save()
  }
  await new ServerLog({ event: log }).save()
  console.log('-------')
}

module.exports = {
  saveAppLog,
  saveServerLog
};
