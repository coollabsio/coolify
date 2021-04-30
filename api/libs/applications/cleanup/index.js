const { docker } = require('../../docker')
const { execShellAsync } = require('../../common')
const Deployment = require('../../../models/Deployment')

async function purgeImagesContainers (configuration, deleteAll = false) {
  const { name, tag } = configuration.build.container
  await execShellAsync('docker container prune -f')
  if (deleteAll) {
    const IDsToDelete = (await execShellAsync(`docker images ls --filter=reference='${name}' --format '{{json .ID }}'`)).trim().replace(/"/g, '').split('\n')
    if (IDsToDelete.length > 0) for (const id of IDsToDelete) await execShellAsync(`docker rmi -f ${id}`)
  } else {
    const IDsToDelete = (await execShellAsync(`docker images ls --filter=reference='${name}' --filter=before='${name}:${tag}' --format '{{json .ID }}'`)).trim().replace(/"/g, '').split('\n')
    if (IDsToDelete.length > 1) for (const id of IDsToDelete) await execShellAsync(`docker rmi -f ${id}`)
  }
  await execShellAsync('docker image prune -f')
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
