const { plausible, activateAdminUser } = require('../../../libs/services/plausible')

module.exports = async function (fastify) {
  fastify.post('/plausible', async (request, reply) => {
    let { email, userName, userPassword, baseURL } = request.body
    baseURL = `https://${baseURL}`
    await plausible({ email, userName, userPassword, baseURL })
    return {}
  })
  fastify.patch('/plausible/activate', async (request, reply) => {
    await activateAdminUser()
    return 'OK'
  })
}
