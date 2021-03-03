const yaml = require("js-yaml");
const { execShellAsync } = require("../../common");
const { docker } = require('../../docker')
const { saveAppLog } = require("../../logging");
const fs = require('fs').promises
const { deleteSameDeployments } = require('../cleanup')

module.exports = async function (configuration) {
  try {
    const generateEnvs = {};
    for (const secret of configuration.publish.secrets) {
      generateEnvs[secret.name] = secret.value;
    }
    const containerName = configuration.build.container.name
    // const previewDomain = config.publish.previewDomain || config.publish.domain
    const stack = {
      version: "3.8",
      services: {
        [containerName]: {
          image: `${configuration.build.container.name}:${configuration.build.container.tag}`,
          networks: [`${docker.network}`],
          environment: generateEnvs,
          deploy: {
            replicas: 1,
            update_config: {
              parallelism: 1,
              delay: "10s",
              order: "start-first",
            },
            rollback_config: {
              parallelism: 1,
              delay: "10s",
              order: "start-first",
            },
            labels: [
              "managedBy=coolify",
              "type=application",
              "config=" + JSON.stringify(configuration),
              "traefik.enable=true",
              "traefik.http.services." +
              configuration.build.container.name +
              `.loadbalancer.server.port=${configuration.publish.port}`,
              "traefik.http.routers." +
              configuration.build.container.name +
              ".entrypoints=websecure",
              "traefik.http.routers." +
              configuration.build.container.name +
              ".rule=Host(`" +
              configuration.publish.domain +
              "`) && PathPrefix(`" +
              configuration.publish.path +
              "`)",
              "traefik.http.routers." +
              configuration.build.container.name +
              ".tls.certresolver=letsencrypt",
              "traefik.http.routers." +
              configuration.build.container.name +
              ".middlewares=global-compress",
            ],
          },
        },
      },
      networks: {
        [`${docker.network}`]: {
          external: true,
        },
      },
    };
    await fs.writeFile(`${configuration.general.workdir}/stack.yml`, yaml.dump(stack))
    await deleteSameDeployments(configuration)
    await execShellAsync(
      `cat ${configuration.general.workdir}/stack.yml | docker stack deploy -c - ${containerName}`
    );
    await saveAppLog("Published!", configuration);
  } catch (error) {
    await saveAppLog(`Error occured during deployment: ${error.message}`, configuration)
    throw { error, type: 'server' }
  }
};
