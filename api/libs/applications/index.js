const dayjs = require('dayjs')

const { saveServerLog } = require('../logging')
const { execShellAsync } = require('../common');

const cloneRepository = require("./github/cloneRepository");
const { saveAppLog } = require('../logging')
const copyFiles = require("./deploy/copyFiles");
const buildContainer = require("./build/container");
const deploy = require("./deploy/deploy");
const Deployment = require('../../models/Deployment');
const { cleanup } = require('./cleanup')


async function queueAndBuild(configuration) {
    try {
        const { id, organization, name, branch } = configuration.repository
        const { domain } = configuration.publish
        const { deployId, nickname } = configuration.general

        await new Deployment({
            repoId: id, branch, deployId, domain, organization, name, nickname
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
