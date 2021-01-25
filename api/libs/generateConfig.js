const { execShellAsync } = require("./common");

module.exports = async function (config) {
  const { domain, path, port } = config.publish;
  const { workdir } = config.general;

  // Default path
  if (!path) config.publish.path = '/'

  // Generate valid name for the container
  config.build.container.name = domain.replace(/\//g, "-").replace(/\./g, "-");

  // Default ports
  if (!port) config.publish.port = config.buildPack === 'static' ? 80 : 3000

  // Generate a tag for the container (git commit sha)
  try {
    config.build.container.tag = (
      await execShellAsync(`cd ${workdir}/ && git rev-parse HEAD`)
    )
      .replace("\n", "")
      .slice(0, 7);
  } catch (error) {
    throw new Error(error);
  }
};
