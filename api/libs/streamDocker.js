const { saveLogs } = require("./saveLogs");
async function streamDocker(engine, stream, config) {
  try {
    await new Promise((resolve, reject) => {
      engine.modem.followProgress(stream, onFinished, onProgress);
      function onFinished(err, res) {
        if (err) reject(err);
        saveLogs(res, config);
        resolve(res);
      }
      function onProgress(event) {
        if (event.error) {
          reject(event.error);
        }
      }
    });
  } catch (e) {
    throw new Error(e);
  }
}
module.exports = { streamDocker };
