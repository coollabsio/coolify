const { execShellAsync } = require('../../../libs/common')
const { saveServerLog } = require('../../../libs/logging')
module.exports = async function (fastify) {
  fastify.get('/', async (request, reply) => {
    reply.code(200).send('I\'m trying...')
    const event = await execShellAsync('bash ./install.sh coolify')
    await saveServerLog({ event, type: 'UPGRADE' })
  })
}
