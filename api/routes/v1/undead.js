module.exports = async function (fastify) {
  fastify.get('/', async (request, reply) => {
    reply.code(200).send('NO')
  })
}
