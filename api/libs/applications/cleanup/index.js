const { docker } = require('../../docker')
const { execShellAsync } = require('../../common')
const Deployment = require('../../../models/Deployment')

async function purgeOldThings () {
  try {
    // TODO: Tweak this, because it deletes coolify-base, so the upgrade will be slow
    await docker.engine.pruneImages()
    await docker.engine.pruneContainers()
  } catch (error) {
    throw { error, type: 'server' }
  }
}

async function cleanup (configuration) {
  const { id } = configuration.repository
  const deployId = configuration.general.deployId
  try {
    // Cleanup stucked deployments.
    const deployments = await Deployment.find({ repoId: id, deployId: { $ne: deployId }, progress: { $in: ['queued', 'inprogress'] } })
    for (const deployment of deployments) {
      await Deployment.findByIdAndUpdate(deployment._id, { $set: { progress: 'failed' } })
    }
  } catch (error) {
    throw { error, type: 'server' }
  }
}

async function deleteSameDeployments (configuration) {
  try {
    await (await docker.engine.listServices()).filter(r => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'application').map(async s => {
      const running = JSON.parse(s.Spec.Labels.configuration)
      if (running.repository.id === configuration.repository.id && running.repository.branch === configuration.repository.branch) {
        await execShellAsync(`docker stack rm ${s.Spec.Labels['com.docker.stack.namespace']}`)
      }
    })
  } catch (error) {
    throw { error, type: 'server' }
  }
}

module.exports = { cleanup, deleteSameDeployments, purgeOldThings }
