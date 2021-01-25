const Deploy = require("../models/Deploy");
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
          e.stream = e.stream.replace(/(\r\n|\n|\r)/gm, "");
        }
        if (e && e.error) {
          e.error = e.error.replace(/(\r\n|\n|\r)/gm, "");
        }
        return e;
      });

    try {
      const found = await Deploy.findOne({ repoId, branch, deployId });
      if (found) {
        const events = found.events.concat(
          clearedEvent.map((e) => e.error || e.stream)
        );
        await Deploy.findOneAndUpdate(
          { repoId, branch, deployId },
          { repoId, branch, deployId, events },
          { upsert: true, new: true }
        );
      } else {
        const events = event.map((e) => e.error || e.stream);
        await Deploy.findOneAndUpdate(
          { repoId, branch, deployId },
          { repoId, branch, deployId, events },
          { upsert: true, new: true }
        );
      }
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
