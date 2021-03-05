const buildPacks = require("../../../buildPacks");
const { saveAppLog } = require("../../logging");
const Deployment = require('../../../models/Deployment')

module.exports = async function (configuration) {
  const { id, organization, name, branch } = configuration.repository
  const { domain } = configuration.publish
  const deployId = configuration.general.deployId

  const execute = buildPacks[configuration.build.pack];
  if (execute) {
    try {
      await Deployment.findOneAndUpdate(
        { repoId: id, branch, deployId, organization, name, domain },
        { repoId: id, branch, deployId, organization, name, domain, progress: 'inprogress' })
      await saveAppLog("Work-work.", configuration);
      
      await execute(configuration);

      await saveAppLog("Work-work done.", configuration);
      await Deployment.findOneAndUpdate(
        { repoId: id, branch, deployId, organization, name, domain },
        { repoId: id, branch, deployId, organization, name, domain, progress: 'done' })
    } catch (error) {
      await Deployment.findOneAndUpdate(
        { repoId: id, branch, deployId, organization, name, domain },
        { repoId: id, branch, deployId, organization, name, domain, progress: 'failed' })
      if (error.stack) throw { error: error.stack, type: 'server' }
      throw { error, type: 'app' }
    }
  } else {
    await Deployment.findOneAndUpdate(
      { repoId: id, branch, deployId, organization, name, domain },
      { repoId: id, branch, deployId, organization, name, domain, progress: 'failed' })
    throw { error: "No buildpack found.", type: 'app' }
  }
};
