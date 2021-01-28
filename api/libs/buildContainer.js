const buildPacks = require("../buildPacks");
const { saveLogs } = require("./saveLogs");
const Deploy = require('../models/Deploy')
/* const { checkImageAvailable, execShellAsync } = require("./common"); */

module.exports = async function (config, engine) {
  const repoId = config.repository.id
  const branch = config.repository.branch
  const deployId = config.general.random
  const execute = buildPacks[config.buildPack];
  if (execute) {
    try {
      await Deploy.findOneAndUpdate(
        { repoId, branch, deployId },
        { repoId, branch, deployId, progress: 'building' },
        { upsert: true, new: true }
      );

      await saveLogs(
        [
          { stream: "Started working on your application." },
        ],
        config
      );
      await saveLogs(
        [
          { stream: "######### Building started #########" }
        ],
        config
      );
      await execute(config, engine);
      await saveLogs(
        [
          { stream: "######### Building done #########" }
        ],
        config
      );
      await Deploy.findOneAndUpdate(
        { repoId, branch, deployId },
        { repoId, branch, deployId, progress: 'done' },
        { upsert: true, new: true }
      );
    } catch (error) {
      await Deploy.findOneAndUpdate(
        { repoId, branch, deployId },
        { repoId, branch, deployId, progress: 'failed' },
        { upsert: true, new: true }
      );
      throw new Error(error);
    }
  } else {
    await Deploy.findOneAndUpdate(
      { repoId, branch, deployId },
      { repoId, branch, deployId, progress: 'failed' },
      { upsert: true, new: true }
    );
    console.error("No buildpack found.");
    throw new Error("No buildpack found.");
  }
};
