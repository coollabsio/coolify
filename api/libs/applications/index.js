const dayjs = require('dayjs')

const { cleanupTmp } = require('../common')

const { saveAppLog } = require('../logging')
const copyFiles = require('./deploy/copyFiles')
const buildContainer = require('./build/container')
const deploy = require('./deploy/deploy')
const Deployment = require('../../models/Deployment')
const { purgeImagesContainers } = require('./cleanup')
const { updateServiceLabels } = require('./configuration')

async function queueAndBuild (configuration, imageChanged) {
  const { id, organization, name, branch } = configuration.repository
  const { domain } = configuration.publish
  const { deployId, nickname, workdir } = configuration.general
  await new Deployment({
    repoId: id, branch, deployId, domain, organization, name, nickname
  }).save()
  await saveAppLog(`${dayjs().format('YYYY-MM-DD HH:mm:ss.SSS')} Queued.`, configuration)
  await copyFiles(configuration)
  co
  await buildContainer(configuration)
  await deploy(configuration, imageChanged)
  await Deployment.findOneAndUpdate(
    { repoId: id, branch, deployId, organization, name, domain },
    { repoId: id, branch, deployId, organization, name, domain, progress: 'done' })
  await updateServiceLabels(configuration)
  cleanupTmp(workdir)
  await purgeImagesContainers()
}

module.exports = { queueAndBuild }
