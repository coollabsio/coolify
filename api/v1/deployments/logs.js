const Log = require("../../models/Log");
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
    fastify.get("/", async (request, reply) => {
        const { deployId } = request.query;
        try {
            return await Log.find({ deployId })
                .select("-_id -__v")
                .sort({ createdAt: "asc" });
        } catch (e) {
            throw new Error('No Logs found');
        }

    });
};
