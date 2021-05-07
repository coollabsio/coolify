
import Dockerode from 'dockerode'
const { DOCKER_ENGINE, DOCKER_NETWORK } = process.env
export const docker = {
    engine: new Dockerode({
        socketPath: DOCKER_ENGINE
    }),
    network: DOCKER_NETWORK
}
export async function streamEvents(stream, configuration) {
    await new Promise((resolve, reject) => {
        docker.engine.modem.followProgress(stream, onFinished, onProgress)
        function onFinished(err, res) {
            if (err) reject(err)
            resolve(res)
        }
        function onProgress(event) {
            if (event.error) {
                // saveAppLog(event.error, configuration, true)
                reject(event.error)
            } else if (event.stream) {
                // saveAppLog(event.stream, configuration)
            }
        }
    })
}


