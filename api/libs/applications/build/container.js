const buildPacks = require("../../../buildPacks");
const { saveAppLog } = require("../../logging");
const Deployment = require('../../../models/Deployment')
/* const { checkImageAvailable, execShellAsync } = require("./common"); */

module.exports = async function (configuration) {
  const repoId = configuration.repository.id
  const branch = configuration.repository.branch
  const deployId = configuration.general.name
  const execute = buildPacks[configuration.build.pack];
  if (execute) {
    try {
      await Deployment.findOneAndUpdate(
        { repoId, branch, deployId },
        { repoId, branch, deployId, progress: 'inprogress' })
      await saveAppLog("Work-work.", configuration);
      await execute(configuration);
      await saveAppLog("Work-work done.", configuration);
      await Deployment.findOneAndUpdate(
        { repoId, branch, deployId },
        { repoId, branch, deployId, progress: 'done' })
    } catch (error) {
      await Deployment.findOneAndUpdate(
        { repoId, branch, deployId },
        { repoId, branch, deployId, progress: 'failed' })
      if (error.stack) throw { error: error.stack, type: 'server' }
      throw { error, type: 'app' }
    }
  } else {
    await Deployment.findOneAndUpdate(
      { repoId, branch, deployId },
      { repoId, branch, deployId, progress: 'failed' })
    throw { error: "No buildpack found.", type: 'app' }
  }
};
