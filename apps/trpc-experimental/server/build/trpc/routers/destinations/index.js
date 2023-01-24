"use strict";
var __defProp = Object.defineProperty;
var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
var __getOwnPropNames = Object.getOwnPropertyNames;
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
var __toCommonJS = (mod) => __copyProps(__defProp({}, "__esModule", { value: true }), mod);
var destinations_exports = {};
__export(destinations_exports, {
  destinationsRouter: () => destinationsRouter
});
module.exports = __toCommonJS(destinations_exports);
var import_zod = require("zod");
var import_trpc = require("../../trpc");
var import_common = require("../../../lib/common");
var import_prisma = require("../../../prisma");
var import_executeCommand = require("../../../lib/executeCommand");
var import_docker = require("../../../lib/docker");
const destinationsRouter = (0, import_trpc.router)({
  restartProxy: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string()
    })
  ).mutation(async ({ input, ctx }) => {
    const { id } = input;
    await (0, import_common.stopTraefikProxy)(id);
    await (0, import_common.startTraefikProxy)(id);
    await import_prisma.prisma.destinationDocker.update({
      where: { id },
      data: { isCoolifyProxyUsed: true }
    });
  }),
  startProxy: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string()
    })
  ).mutation(async ({ input, ctx }) => {
    const { id } = input;
    await (0, import_common.startTraefikProxy)(id);
  }),
  stopProxy: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string()
    })
  ).mutation(async ({ input, ctx }) => {
    const { id } = input;
    await (0, import_common.stopTraefikProxy)(id);
  }),
  saveSettings: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string(),
      engine: import_zod.z.string(),
      isCoolifyProxyUsed: import_zod.z.boolean()
    })
  ).mutation(async ({ input, ctx }) => {
    const { id, engine, isCoolifyProxyUsed } = input;
    await import_prisma.prisma.destinationDocker.updateMany({
      where: { engine },
      data: { isCoolifyProxyUsed }
    });
  }),
  status: import_trpc.privateProcedure.input(import_zod.z.object({ id: import_zod.z.string() })).query(async ({ input, ctx }) => {
    const { id } = input;
    const destination = await import_prisma.prisma.destinationDocker.findUnique({ where: { id } });
    const { found: isRunning } = await (0, import_docker.checkContainer)({
      dockerId: destination.id,
      container: "coolify-proxy",
      remove: true
    });
    return {
      isRunning
    };
  }),
  save: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string(),
      name: import_zod.z.string(),
      htmlUrl: import_zod.z.string(),
      apiUrl: import_zod.z.string(),
      customPort: import_zod.z.number(),
      customUser: import_zod.z.string(),
      isSystemWide: import_zod.z.boolean().default(false)
    })
  ).mutation(async ({ input, ctx }) => {
    const { teamId } = ctx.user;
    let {
      id,
      name,
      network,
      engine,
      isCoolifyProxyUsed,
      remoteIpAddress,
      remoteUser,
      remotePort
    } = input;
    if (id === "new") {
      if (engine) {
        const { stdout } = await await (0, import_executeCommand.executeCommand)({
          command: `docker network ls --filter 'name=^${network}$' --format '{{json .}}'`
        });
        if (stdout === "") {
          await await (0, import_executeCommand.executeCommand)({
            command: `docker network create --attachable ${network}`
          });
        }
        await import_prisma.prisma.destinationDocker.create({
          data: { name, teams: { connect: { id: teamId } }, engine, network, isCoolifyProxyUsed }
        });
        const destinations = await import_prisma.prisma.destinationDocker.findMany({ where: { engine } });
        const destination = destinations.find((destination2) => destination2.network === network);
        if (destinations.length > 0) {
          const proxyConfigured = destinations.find(
            (destination2) => destination2.network !== network && destination2.isCoolifyProxyUsed === true
          );
          if (proxyConfigured) {
            isCoolifyProxyUsed = !!proxyConfigured.isCoolifyProxyUsed;
          }
          await import_prisma.prisma.destinationDocker.updateMany({
            where: { engine },
            data: { isCoolifyProxyUsed }
          });
        }
        if (isCoolifyProxyUsed) {
          await (0, import_common.startTraefikProxy)(destination.id);
        }
        return { id: destination.id };
      } else {
        const destination = await import_prisma.prisma.destinationDocker.create({
          data: {
            name,
            teams: { connect: { id: teamId } },
            engine,
            network,
            isCoolifyProxyUsed,
            remoteEngine: true,
            remoteIpAddress,
            remoteUser,
            remotePort: Number(remotePort)
          }
        });
        return { id: destination.id };
      }
    } else {
      await import_prisma.prisma.destinationDocker.update({ where: { id }, data: { name, engine, network } });
      return {};
    }
  }),
  check: import_trpc.privateProcedure.input(
    import_zod.z.object({
      network: import_zod.z.string()
    })
  ).query(async ({ input, ctx }) => {
    const { network } = input;
    const found = await import_prisma.prisma.destinationDocker.findFirst({ where: { network } });
    if (found) {
      throw {
        message: `Network already exists: ${network}`
      };
    }
  }),
  delete: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string()
    })
  ).mutation(async ({ input, ctx }) => {
    const { id } = input;
    const { network, remoteVerified, engine, isCoolifyProxyUsed } = await import_prisma.prisma.destinationDocker.findUnique({ where: { id } });
    if (isCoolifyProxyUsed) {
      if (engine || remoteVerified) {
        const { stdout: found } = await (0, import_executeCommand.executeCommand)({
          dockerId: id,
          command: `docker ps -a --filter network=${network} --filter name=coolify-proxy --format '{{.}}'`
        });
        if (found) {
          await (0, import_executeCommand.executeCommand)({
            dockerId: id,
            command: `docker network disconnect ${network} coolify-proxy`
          });
          await (0, import_executeCommand.executeCommand)({ dockerId: id, command: `docker network rm ${network}` });
        }
      }
    }
    await import_prisma.prisma.destinationDocker.delete({ where: { id } });
  }),
  getDestinationById: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string()
    })
  ).query(async ({ input, ctx }) => {
    const { id } = input;
    const { teamId } = ctx.user;
    const destination = await import_prisma.prisma.destinationDocker.findFirst({
      where: { id, teams: { some: { id: teamId === "0" ? void 0 : teamId } } },
      include: { sshKey: true, application: true, service: true, database: true }
    });
    if (!destination && id !== "new") {
      throw { status: 404, message: `Destination not found.` };
    }
    const settings = await (0, import_common.listSettings)();
    return {
      destination,
      settings
    };
  })
});
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  destinationsRouter
});
