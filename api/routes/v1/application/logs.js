const ApplicationLog = require("../../../models/ApplicationLog");
const Deployment = require("../../../models/Deployment");
module.exports = async function (fastify) {
    fastify.get("/:deployId", async (request, reply) => {
        const { deployId } = request.params;
        try {
            return await ApplicationLog.find({ deployId })
                .select("-_id -__v")
                .sort({ createdAt: "asc" });
        } catch (e) {
            throw new Error('No logs found');
        }

    });
    const getLogSchema = {
        querystring: {
            type: "object",
            properties: {
                repoId: { type: "string" }
            },
            required: ["repoId"],
        },
    };
    fastify.get("/", { schema: getLogSchema }, async (request, reply) => {
        const { repoId } = request.query;
        return await Deployment.find({ repoId })
            .select("-_id -__v -repoId")
            .sort({ createdAt: "desc" });

    });
};
