const { execShellAsync } = require('../../../libs/common')
const { saveServerLog } = require('../../../libs/logging')

module.exports = async function (fastify) {
  fastify.get('/', async (request, reply) => {
    const asd = await execShellAsync('GIT_SSH_COMMAND="ssh -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no" git pull')
    console.log(asd)
    const upgradeP1 = await execShellAsync('bash /usr/src/app/install.sh upgrade-phase-1')
    await saveServerLog({ event: upgradeP1, type: 'UPGRADE-P-1' })
    reply.code(200).send('I\'m trying, okay?')
    const upgradeP2 = await execShellAsync('bash /usr/src/app/install.sh upgrade-phase-2')
    await saveServerLog({ event: upgradeP2, type: 'UPGRADE-P-2' })
  })
}
