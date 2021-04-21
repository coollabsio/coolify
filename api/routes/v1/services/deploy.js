const { plausible, activateAdminUser } = require('../../../libs/services/plausible')

module.exports = async function (fastify) {
  fastify.post('/plausible', async (request, reply) => {
    let { email, userName, userPassword, baseURL } = request.body
    const traefikURL = baseURL
    baseURL = `https://${baseURL}`
    await plausible({ email, userName, userPassword, baseURL, traefikURL })
    return {}
  })
  fastify.patch('/plausible/activate', async (request, reply) => {
    await activateAdminUser()
    return 'OK'
  })
}
