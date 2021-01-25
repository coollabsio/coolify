const Config = require("../models/Config");
const Secret = require("../models/Secret");
const Deploy = require("../models/Deploy");
const { execShellAsync } = require("../libs/common");
module.exports = async function (fastify) {
  const getSchema = {
    querystring: {
      type: "object",
      properties: {
        repoId: { type: "number" },
        branch: { type: "string" },
      },
      required: ["repoId", "branch"],
    },
  };
  const postSchema = {
    body: {
      type: "object",
      properties: {
        repoId: { type: "number" },
        branch: { type: "string" },
      },
      required: ["repoId", "branch"],
    },
  };
  fastify.get("/all", async (request, reply) => {
    return await Config.find().select("-_id -__v");
  });
  fastify.get("/", { schema: getSchema }, async (request, reply) => {
    const { repoId, branch } = request.query;
    return await Config.findOne({ repoId, branch }).select("-_id -__v");
  });
  fastify.post("/", { schema: postSchema }, async (request, reply) => {
    const { repoId, branch } = request.body;
    delete request.body.createdAt;
    delete request.body.updatedAt;
    try {
      const found = await Config.findOne({
        "publish.domain": request.body.publish.domain,
        repoId: { $ne: repoId },
        branch: { $ne: branch },
      });
      if (found) {
        throw new Error("Domain already configured.");
      } else {
        const config = await Config.findOneAndUpdate(
          { repoId: repoId, branch: branch },
          { ...request.body },
          { upsert: true, new: true }
        ).select("-_id -__v");
        reply.code(201).send(config);
      }
    } catch (error) {
      throw new Error(error);
    }
  });
  fastify.delete("/", async (request, reply) => {
    const { repoId, branch } = request.body;
    const { publish } = await Config.findOneAndDelete({ repoId, branch })
    const deploys = await Deploy.find({ repoId, branch })
    for (const deploy of deploys) {
      await Deploy.findByIdAndRemove(deploy._id);
    }
    const secrets = await Secret.find({ repoId, branch });
    for (const secret of secrets) {
      await Secret.findByIdAndRemove(secret._id);
    }

    await execShellAsync(`docker stack rm ${publish.domain.replace(/\//g, "-").replace(/\./g, "-")}`);
    return { OK: "OK" };
  });
};
