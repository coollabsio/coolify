const Dockerode = require('dockerode')
const { saveAppLog } = require('./logging')

const docker = {
  engine: new Dockerode({
    socketPath: process.env.DOCKER_ENGINE
  }),
  network: process.env.DOCKER_NETWORK
}
async function streamEvents (stream, configuration) {
  try {
    await new Promise((resolve, reject) => {
      docker.engine.modem.followProgress(stream, onFinished, onProgress)
      function onFinished (err, res) {
        if (err) reject(err)
        resolve(res)
      }
      function onProgress (event) {
        if (event.error) {
          reject(event.error)
          return
        }
        saveAppLog(event.stream, configuration)
      }
    })
  } catch (error) {
    throw { error, type: 'app' }
  }
}

module.exports = { streamEvents, docker }
