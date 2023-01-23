"use strict";
var __create = Object.create;
var __defProp = Object.defineProperty;
var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
var __getOwnPropNames = Object.getOwnPropertyNames;
var __getProtoOf = Object.getPrototypeOf;
var __hasOwnProp = Object.prototype.hasOwnProperty;
var __export = (target, all) => {
  for (var name in all)
    __defProp(target, name, { get: all[name], enumerable: true });
};
var __copyProps = (to, from, except, desc) => {
  if (from && typeof from === "object" || typeof from === "function") {
    for (let key of __getOwnPropNames(from))
      if (!__hasOwnProp.call(to, key) && key !== except)
        __defProp(to, key, { get: () => from[key], enumerable: !(desc = __getOwnPropDesc(from, key)) || desc.enumerable });
  }
  return to;
};
var __toESM = (mod, isNodeMode, target) => (target = mod != null ? __create(__getProtoOf(mod)) : {}, __copyProps(
  isNodeMode || !mod || !mod.__esModule ? __defProp(target, "default", { value: mod, enumerable: true }) : target,
  mod
));
var __toCommonJS = (mod) => __copyProps(__defProp({}, "__esModule", { value: true }), mod);
var server_exports = {};
__export(server_exports, {
  createServer: () => createServer
});
module.exports = __toCommonJS(server_exports);
var import_fastify = require("@trpc/server/adapters/fastify");
var import_fastify2 = __toESM(require("fastify"));
var import_trpc = require("./trpc");
var import_context = require("./trpc/context");
var import_cors = __toESM(require("@fastify/cors"));
var path = __toESM(require("node:path"));
var import_static = __toESM(require("@fastify/static"));
var import_autoload = __toESM(require("@fastify/autoload"));
var import_graceful = __toESM(require("@ladjs/graceful"));
var import_scheduler = require("./scheduler");
const isDev = process.env["NODE_ENV"] === "development";
function createServer(opts) {
  const dev = opts.dev ?? true;
  const port = opts.port ?? 3e3;
  const prefix = opts.prefix ?? "/trpc";
  const server = (0, import_fastify2.default)({ logger: dev, trustProxy: true });
  server.register(import_cors.default);
  server.register(import_fastify.fastifyTRPCPlugin, {
    prefix,
    trpcOptions: {
      router: import_trpc.appRouter,
      createContext: import_context.createContext,
      onError({ error, type, path: path2, input, ctx, req }) {
        console.error("Error:", error);
        if (error.code === "INTERNAL_SERVER_ERROR") {
        }
      }
    }
  });
  if (!isDev) {
    server.register(import_static.default, {
      root: path.join(__dirname, "./public"),
      preCompressed: true
    });
    server.setNotFoundHandler(async function(request, reply) {
      if (request.raw.url && request.raw.url.startsWith("/api")) {
        return reply.status(404).send({
          success: false
        });
      }
      return reply.status(200).sendFile("index.html");
    });
  }
  server.register(import_autoload.default, {
    dir: path.join(__dirname, "api"),
    options: { prefix: "/api" }
  });
  const stop = () => server.close();
  const start = async () => {
    try {
      await server.listen({ host: "0.0.0.0", port });
      console.log("Coolify server is listening on port", port, "at 0.0.0.0 \u{1F680}");
      const graceful = new import_graceful.default({ brees: [import_scheduler.scheduler] });
      graceful.listen();
      setInterval(async () => {
        if (!import_scheduler.scheduler.workers.has("applicationBuildQueue")) {
          import_scheduler.scheduler.run("applicationBuildQueue");
        }
      }, 2e3);
    } catch (err) {
      server.log.error(err);
      process.exit(1);
    }
  };
  return { server, start, stop };
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  createServer
});
