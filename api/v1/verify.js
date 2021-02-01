const User = require("../models/User");
const jwt = require("jsonwebtoken");
module.exports = async function (fastify) {
  fastify.get("/", async (request, reply) => {
    const { authorization } = request.headers;
    if (authorization) {
      const token = authorization.split(" ")[1];
      const verify = jwt.verify(token, fastify.config._KEY);
      const found = await User.findOne({ uid: verify.jti });
      if (found) {
        reply.code(200).send({});
      } else {
        reply.code(401).send({});
      }
    } else {
      reply.code(401).send({});
    }
  });
};
