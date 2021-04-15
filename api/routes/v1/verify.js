const User = require('../../models/User')
const jwt = require('jsonwebtoken')

module.exports = async function (fastify) {
  fastify.get('/', async (request, reply) => {
    try {
      const { authorization } = request.headers
      if (!authorization) {
        reply.code(401).send({})
        return
      }
      const token = authorization.split(' ')[1]
      const verify = jwt.verify(token, fastify.config.JWT_SIGN_KEY)
      const found = await User.findOne({ uid: verify.jti })
      found ? reply.code(200).send({}) : reply.code(401).send({})
    } catch (error) {
      reply.code(401).send({})
    }
  })
}
