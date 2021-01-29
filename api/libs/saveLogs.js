const Log = require("../models/Log");
const dayjs = require('dayjs')
function getTimestamp() {
  return `[INFO] ${dayjs().format('YYYY-MM-DD HH:mm:ss.SSS')} `
}
async function saveLogs(event, config) {
  try {
    const deployId = config.general.random;
    const repoId = config.repository.id;
    const branch = config.repository.branch;
    const clearedEvent = event
      .filter((e) => {
        if (e && e.stream) {
          return e.stream !== "\n";
        }
        if (e && e.error) {
          return e.error !== "\n";
        }
      })
      .map((e) => {
        if (e && e.stream) {
          e.stream = getTimestamp() + e.stream.replace(/(\r\n|\n|\r)/gm, "");
        }
        if (e && e.error) {
          e.error = getTimestamp() + e.error.replace(/(\r\n|\n|\r)/gm, "");
        }
        return e;
      });
    try {
      new Log({ repoId, branch, deployId, events: clearedEvent.map((e) => e.error || e.stream) }).save()
    } catch (error) {
      console.log(error);
    }
  } catch (error) {
    console.log(error);
    return error;
  }
}
module.exports = {
  saveLogs,
};
