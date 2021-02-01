const fp = require("fastify-plugin");
const User = require("../models/User");
module.exports = fp(async function (fastify, options, next) {
  fastify.register(require("fastify-jwt"), {
    secret: fastify.config.JWT_SIGN_KEY,
  });
  fastify.addHook("onRequest", async (request, reply) => {
    try {
      const { jti } = await request.jwtVerify();
      const found = await User.findOne({ uid: jti });
      if (found) {
        return true;
      } else {
        throw new Error("User not found");
      }
    } catch (err) {
      throw new Error(err);
    }
  });
  next();
});
