const { docker } = require('../../docker')
const { execShellAsync } = require('../../common')
const Deployment = require('../../../models/Deployment')

async function purgeImagesContainers () {
  await execShellAsync('docker container prune -f')
  await execShellAsync('docker image prune -f --filter=label!=coolify-reserve=true')
}

async function cleanupStuckedDeploymentsInDB () {
  // Cleanup stucked deployments.
  await Deployment.updateMany(
    { progress: { $in: ['queued', 'inprogress'] } },
    { progress: 'failed' }
  )
}

async function deleteSameDeployments (configuration) {
  await (await docker.engine.listServices()).filter(r => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'application').map(async s => {
    const running = JSON.parse(s.Spec.Labels.configuration)
    if (running.repository.id === configuration.repository.id && running.repository.branch === configuration.repository.branch) {
      await execShellAsync(`docker stack rm ${s.Spec.Labels['com.docker.stack.namespace']}`)
    }
  })
}

module.exports = { cleanupStuckedDeploymentsInDB, deleteSameDeployments, purgeImagesContainers }
