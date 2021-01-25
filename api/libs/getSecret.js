const { decryptData } = require("./common");
const Secret = require("../models/Secret");
module.exports = async function (config) {
  try {
    const q = await Secret.find({
      repoId: config.repository.id,
      branch: config.repository.branch,
    });
    if (q.length > 0) {
      for (const secret of q) {
        config.publish.secrets.push({
          name: secret.name,
          value: decryptData(secret.value),
        });
      }
    }
  } catch (error) {
    if (error.stack) console.log(error.stack);
    throw new Error(error);
  }
};
