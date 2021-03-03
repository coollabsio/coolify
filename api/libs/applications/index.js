const dayjs = require('dayjs')

const { saveServerLog } = require('../logging')
const { docker } = require('../docker')
const { execShellAsync } = require('../common');

const cloneRepository = require("./github/cloneRepository");
const { generateConfiguration, getConfigFromDatabase } = require("./configuration");
const { saveAppLog } = require('../logging')
const copyFiles = require("./deploy/copyFiles");
const buildContainer = require("./build/container");
const deploy = require("./deploy/deploy");
const Deployment = require('../../models/Deployment');
const Config = require('../../models/Config');
const ApplicationLog = require('../../models/ApplicationLog');
const { cleanup } = require('./cleanup')


async function queueAndBuild(configuration) {
    try {
        const repoId = configuration.repository.id
        const branch = configuration.repository.branch
        const deployId = configuration.general.name
        const domain = configuration.publish.domain

        await new Deployment({
            repoId, branch, deployId, domain
        }).save()

        await cloneRepository(configuration)

        configuration.build.container.tag = (
            await execShellAsync(`cd ${configuration.general.workdir}/ && git rev-parse HEAD`)
        )
            .replace("\n", "")
            .slice(0, 7);

        await saveAppLog(`${dayjs().format('YYYY-MM-DD HH:mm:ss.SSS')} Queued.`, configuration)
        await copyFiles(configuration);
        await buildContainer(configuration);
        await deploy(configuration);
        await cleanup(configuration)
    } catch (error) {
        console.log(error, type)
        if (type === 'app') {
            await saveAppLog(error, configuration, true)
        } else {
            await saveServerLog(error, configuration)
        }
    }
}

module.exports = { queueAndBuild }
