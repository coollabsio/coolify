const Deployment = require("../../models/Deployment");
const Config = require("../../models/Config");
module.exports = async function (fastify) {
  const getLogSchema = {
    querystring: {
      type: "object",
      properties: {
        repo: { type: "string" },
        org: { type: "string" },
      },
      required: ["repo", "org"],
    },
  };
  fastify.get("/", { schema: getLogSchema }, async (request, reply) => {
    const { repo, org } = request.query;
    const config = await Config.findOne({ fullName: `${org}/${repo}` });
    if(config) {
      return await Deployment.find({ repoId: config.repoId })
      .select("-_id -__v -repoId")
      .sort({ createdAt: "desc" });
    } else {
      throw new Error('No configuration found');
    }

  });
};
