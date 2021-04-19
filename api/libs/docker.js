const Dockerode = require('dockerode')
const { saveAppLog } = require('./logging')

const docker = {
  engine: new Dockerode({
    socketPath: process.env.DOCKER_ENGINE
  }),
  network: process.env.DOCKER_NETWORK
}
async function streamEvents (stream, configuration) {
  await new Promise((resolve, reject) => {
    docker.engine.modem.followProgress(stream, onFinished, onProgress)
    function onFinished (err, res) {
      if (err) reject(err)
      resolve(res)
    }
    function onProgress (event) {
      if (event.error) {
        saveAppLog(event.error, configuration, true)
        reject(event.error)
      } else if (event.stream) {
        saveAppLog(event.stream, configuration)
      }
    }
  })
}

module.exports = { streamEvents, docker }
