const cuid = require("cuid");
const merge = require("deepmerge");
const crypto = require('crypto');
const { uniqueNamesGenerator, adjectives, colors, animals } = require('unique-names-generator');

const { execShellAsync, decryptData } = require("../common");
const cloneRepository = require('./github/cloneRepository')
const Config = require("../../models/Config");
const Secret = require("../../models/Secret");

function getUniq() {
  return uniqueNamesGenerator({ dictionaries: [adjectives, animals, colors], length: 2 })
}

function setDefaultConfiguration(configuration) {
  try { 
    const name = getUniq()
    
    configuration.build.container.name = name
  
    configuration.general.name = name
    configuration.general.workdir = `/tmp/${configuration.general.name}`
  
    if (!configuration.publish.path) configuration.publish.path = '/'
    if (!configuration.publish.port) configuration.publish.port = configuration.build.pack === 'static' ? 80 : 3000
  
    return configuration
  } catch(error) {
    throw { error, type: 'server' }
  }

}

async function generateConfiguration(configuration) {
  await cloneRepository(configuration)
  await getSecretsFromDatabase(configuration)
  await setConfiguration(configuration)
};

// function setDefaultConfiguration(request) {
//   const random = cuid();
//   const ref = request.body.ref.split("/")
//   let branch = null
//   if (ref[1] === "heads") {
//     branch = ref.slice(2).join('/')
//   } else {
//     throw Error('oops')
//   }
  
//   return {
//     previewDeploy: false,
//     repository: {
//       installationId: request.body.installation.id,
//       id: request.body.repository.id,
//       name: request.body.repository.full_name,
//       branch,
//     },
//     general: {
//       random,
//       workdir: `/tmp/${random}`,
//       githubAppId: request.headers["x-github-hook-installation-target-id"]
//     },
//     build: {
//       publishDir: "",
//       container: {
//         name: "",
//         tag: "",
//       },
//     },
//     publish: {
//       previewDomain: null,
//       secrets: [],
//     },
//   };
// }
// async function setConfiguration(config) {
//   try {
//     const { domain, pathPrefix, port } = config.publish;
//     const { workdir, random } = config.general;

//     const shaBase = JSON.stringify({ repository: config.repository, publish: config.publish })
//     const sha256 = crypto.createHash('sha256').update(shaBase).digest('hex');

//     // Generate valid name for the container
//     config.build.container.name = sha256.slice(0, 15)

//     // Default pathPrefix
//     if (!pathPrefix) {
//       config.publish.pathPrefix = '/'
//     }

//     // TODO ADD pathprefix
//     if (config.previewDeploy) config.publish.previewDomain = `${random}.${domain}`

//     // Default ports
//     if (!port) config.publish.port = config.buildPack === 'static' ? 80 : 3000

//     // Generate a tag for the container (git commit sha)
//     config.build.container.tag = (
//       await execShellAsync(`cd ${workdir}/ && git rev-parse HEAD`)
//     )
//       .replace("\n", "")
//       .slice(0, 7);
//     // console.log(config)
//   } catch (error) {
//     throw { error, type: 'server' }
//   }
// }

async function getConfigFromDatabase(config) {
  try {
    const q = await Config.findOne({
      repoId: config.repository.id,
      branch: config.repository.branch,
    });
    if (q && Object.keys(q).length !== 0) {
      config.build = merge(config.build, q.build);
      config.publish = merge(config.publish, q.publish);
      config.buildPack = q.buildPack;
      config.previewDeploy = q.previewDeploy;
    } else {
      throw new Error("No configuration found!");
    }
  } catch (error) {
    // if (error.stack) console.log(error.stack);
    throw { error, type: 'server' }
  }
};

async function getSecretsFromDatabase(config) {
  try {
    const q = await Secret.find({
      repoId: config.repository.id,
      branch: config.repository.branch,
    });
    if (q.length > 0) {
      for (const secret of q) {
        config.publish.secrets.push({
          name: secret.name,
          value: decryptData(secret.value),
        });
      }
    }
  } catch (error) {
    throw { error, type: 'server' }
  }
}

module.exports = { generateConfiguration, getConfigFromDatabase, setDefaultConfiguration }