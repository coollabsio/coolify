const { execShellAsync } = require('../../../libs/common')
const { saveServerLog } = require('../../../libs/logging')

module.exports = async function (fastify) {
  fastify.get('/', async (request, reply) => {
    const upgradeP1 = await execShellAsync('bash -c "$(curl -fsSL https://get.coollabs.io/coolify/upgrade-p1.sh)"')
    await saveServerLog({ message: upgradeP1, type: 'UPGRADE-P-1' })
    reply.code(200).send('I\'m trying, okay?')
    const upgradeP2 = await execShellAsync('docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -u root coolify bash -c "$(curl -fsSL https://get.coollabs.io/coolify/upgrade-p2.sh)"')
    await saveServerLog({ message: upgradeP2, type: 'UPGRADE-P-2' })
  })
}
