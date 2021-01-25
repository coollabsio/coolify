const Config = require("../models/Config");
const merge = require("deepmerge");
module.exports = async function (config) {
  try {
    const q = await Config.findOne({
      repoId: config.repository.id,
      branch: config.repository.branch,
    });
    if (q && Object.keys(q).length !== 0) {
      config.build = merge(config.build, q.build);
      config.publish = merge(config.publish, q.publish);
      config.buildPack = q.buildPack;
    } else {
      throw new Error("No configuration found!");
    }
  } catch (error) {
    if (error.stack) console.log(error.stack);
    throw new Error(error);
  }
};
