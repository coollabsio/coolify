const yaml = require('js-yaml')
const fs = require('fs').promises
const { execShellAsync } = require('../../common')
const { docker } = require('../../docker')
const { saveAppLog } = require('../../logging')
const { deleteSameDeployments } = require('../cleanup')

module.exports = async function (configuration, configChanged, imageChanged) {
  try {
    const generateEnvs = {}
    for (const secret of configuration.publish.secrets) {
      generateEnvs[secret.name] = secret.value
    }
    const containerName = configuration.build.container.name

    // Only save SHA256 of it in the configuration label
    const baseServiceConfiguration = configuration.baseServiceConfiguration
    delete configuration.baseServiceConfiguration

    const stack = {
      version: '3.8',
      services: {
        [containerName]: {
          image: `${configuration.build.container.name}:${configuration.build.container.tag}`,
          networks: [`${docker.network}`],
          environment: generateEnvs,
          deploy: {
            ...baseServiceConfiguration,
            labels: [
              'managedBy=coolify',
              'type=application',
              'configuration=' + JSON.stringify(configuration),
              'traefik.enable=true',
              'traefik.http.services.' +
              configuration.build.container.name +
              `.loadbalancer.server.port=${configuration.publish.port}`,
              'traefik.http.routers.' +
              configuration.build.container.name +
              '.entrypoints=websecure',
              'traefik.http.routers.' +
              configuration.build.container.name +
              '.rule=Host(`' +
              configuration.publish.domain +
              '`) && PathPrefix(`' +
              configuration.publish.path +
              '`)',
              'traefik.http.routers.' +
              configuration.build.container.name +
              '.tls.certresolver=letsencrypt',
              'traefik.http.routers.' +
              configuration.build.container.name +
              '.middlewares=global-compress'
            ]
          }
        }
      },
      networks: {
        [`${docker.network}`]: {
          external: true
        }
      }
    }
    await saveAppLog('### Publishing.', configuration)
    await fs.writeFile(`${configuration.general.workdir}/stack.yml`, yaml.dump(stack))
    if (imageChanged) {
      // console.log('image changed')
      await execShellAsync(`docker service update --image ${configuration.build.container.name}:${configuration.build.container.tag} ${configuration.build.container.name}_${configuration.build.container.name}`)
    } else {
      // console.log('new deployment or force deployment or config changed')
      await deleteSameDeployments(configuration)
      await execShellAsync(
        `cat ${configuration.general.workdir}/stack.yml | docker stack deploy --prune -c - ${containerName}`
      )
    }

    await saveAppLog('### Published done!', configuration)
  } catch (error) {
    console.log(error)
    await saveAppLog(`Error occured during deployment: ${error.message}`, configuration)
    throw { error, type: 'server' }
  }
}
