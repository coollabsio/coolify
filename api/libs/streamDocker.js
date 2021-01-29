const { saveLogs } = require("./saveLogs");
async function streamDocker(engine, stream, config) {
  try {
    await new Promise((resolve, reject) => {
      engine.modem.followProgress(stream, onFinished, onProgress);
      function onFinished(err, res) {
        if (err) reject(err);
        resolve(res);
      }
      function onProgress(event) {
        saveLogs([event], config)
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
