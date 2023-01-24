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
var databases_exports = {};
__export(databases_exports, {
  databasesRouter: () => databasesRouter
});
module.exports = __toCommonJS(databases_exports);
var import_zod = require("zod");
var import_promises = __toESM(require("fs/promises"));
var import_trpc = require("../../trpc");
var import_common = require("../../../lib/common");
var import_prisma = require("../../../prisma");
var import_executeCommand = require("../../../lib/executeCommand");
var import_docker = require("../../../lib/docker");
var import_lib = require("./lib");
var import_js_yaml = __toESM(require("js-yaml"));
var import_lib2 = require("../services/lib");
const databasesRouter = (0, import_trpc.router)({
  usage: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string()
    })
  ).query(async ({ ctx, input }) => {
    const teamId = ctx.user?.teamId;
    const { id } = input;
    let usage = {};
    const database = await import_prisma.prisma.database.findFirst({
      where: { id, teams: { some: { id: teamId === "0" ? void 0 : teamId } } },
      include: { destinationDocker: true, settings: true }
    });
    if (database.dbUserPassword)
      database.dbUserPassword = (0, import_common.decrypt)(database.dbUserPassword);
    if (database.rootUserPassword)
      database.rootUserPassword = (0, import_common.decrypt)(database.rootUserPassword);
    if (database.destinationDockerId) {
      [usage] = await Promise.all([(0, import_common.getContainerUsage)(database.destinationDocker.id, id)]);
    }
    return {
      success: true,
      data: {
        usage
      }
    };
  }),
  save: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string()
    })
  ).mutation(async ({ ctx, input }) => {
    const teamId = ctx.user?.teamId;
    const {
      id,
      name,
      defaultDatabase,
      dbUser,
      dbUserPassword,
      rootUser,
      rootUserPassword,
      version,
      isRunning
    } = input;
    const database = await import_prisma.prisma.database.findFirst({
      where: { id, teams: { some: { id: teamId === "0" ? void 0 : teamId } } },
      include: { destinationDocker: true, settings: true }
    });
    if (database.dbUserPassword)
      database.dbUserPassword = (0, import_common.decrypt)(database.dbUserPassword);
    if (database.rootUserPassword)
      database.rootUserPassword = (0, import_common.decrypt)(database.rootUserPassword);
    if (isRunning) {
      if (database.dbUserPassword !== dbUserPassword) {
        await (0, import_lib.updatePasswordInDb)(database, dbUser, dbUserPassword, false);
      } else if (database.rootUserPassword !== rootUserPassword) {
        await (0, import_lib.updatePasswordInDb)(database, rootUser, rootUserPassword, true);
      }
    }
    const encryptedDbUserPassword = dbUserPassword && (0, import_common.encrypt)(dbUserPassword);
    const encryptedRootUserPassword = rootUserPassword && (0, import_common.encrypt)(rootUserPassword);
    await import_prisma.prisma.database.update({
      where: { id },
      data: {
        name,
        defaultDatabase,
        dbUser,
        dbUserPassword: encryptedDbUserPassword,
        rootUser,
        rootUserPassword: encryptedRootUserPassword,
        version
      }
    });
  }),
  saveSettings: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string(),
      isPublic: import_zod.z.boolean(),
      appendOnly: import_zod.z.boolean().default(true)
    })
  ).mutation(async ({ ctx, input }) => {
    const teamId = ctx.user?.teamId;
    const { id, isPublic, appendOnly = true } = input;
    let publicPort = null;
    const {
      destinationDocker: { remoteEngine, engine, remoteIpAddress }
    } = await import_prisma.prisma.database.findUnique({ where: { id }, include: { destinationDocker: true } });
    if (isPublic) {
      publicPort = await (0, import_lib2.getFreePublicPort)({ id, remoteEngine, engine, remoteIpAddress });
    }
    await import_prisma.prisma.database.update({
      where: { id },
      data: {
        settings: {
          upsert: { update: { isPublic, appendOnly }, create: { isPublic, appendOnly } }
        }
      }
    });
    const database = await import_prisma.prisma.database.findFirst({
      where: { id, teams: { some: { id: teamId === "0" ? void 0 : teamId } } },
      include: { destinationDocker: true, settings: true }
    });
    const { arch } = await (0, import_common.listSettings)();
    if (database.dbUserPassword)
      database.dbUserPassword = (0, import_common.decrypt)(database.dbUserPassword);
    if (database.rootUserPassword)
      database.rootUserPassword = (0, import_common.decrypt)(database.rootUserPassword);
    const { destinationDockerId, destinationDocker, publicPort: oldPublicPort } = database;
    const { privatePort } = (0, import_lib.generateDatabaseConfiguration)(database, arch);
    if (destinationDockerId) {
      if (isPublic) {
        await import_prisma.prisma.database.update({ where: { id }, data: { publicPort } });
        await (0, import_common.startTraefikTCPProxy)(destinationDocker, id, publicPort, privatePort);
      } else {
        await import_prisma.prisma.database.update({ where: { id }, data: { publicPort: null } });
        await (0, import_docker.stopTcpHttpProxy)(id, destinationDocker, oldPublicPort);
      }
    }
    return { publicPort };
  }),
  saveSecret: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string(),
      name: import_zod.z.string(),
      value: import_zod.z.string(),
      isNew: import_zod.z.boolean().default(true)
    })
  ).mutation(async ({ ctx, input }) => {
    let { id, name, value, isNew } = input;
    if (isNew) {
      const found = await import_prisma.prisma.databaseSecret.findFirst({ where: { name, databaseId: id } });
      if (found) {
        throw `Secret ${name} already exists.`;
      } else {
        value = (0, import_common.encrypt)(value.trim());
        await import_prisma.prisma.databaseSecret.create({
          data: { name, value, database: { connect: { id } } }
        });
      }
    } else {
      value = (0, import_common.encrypt)(value.trim());
      const found = await import_prisma.prisma.databaseSecret.findFirst({ where: { databaseId: id, name } });
      if (found) {
        await import_prisma.prisma.databaseSecret.updateMany({
          where: { databaseId: id, name },
          data: { value }
        });
      } else {
        await import_prisma.prisma.databaseSecret.create({
          data: { name, value, database: { connect: { id } } }
        });
      }
    }
  }),
  start: import_trpc.privateProcedure.input(import_zod.z.object({ id: import_zod.z.string() })).mutation(async ({ ctx, input }) => {
    const { id } = input;
    const teamId = ctx.user?.teamId;
    const database = await import_prisma.prisma.database.findFirst({
      where: { id, teams: { some: { id: teamId === "0" ? void 0 : teamId } } },
      include: { destinationDocker: true, settings: true, databaseSecret: true }
    });
    const { arch } = await (0, import_common.listSettings)();
    if (database.dbUserPassword)
      database.dbUserPassword = (0, import_common.decrypt)(database.dbUserPassword);
    if (database.rootUserPassword)
      database.rootUserPassword = (0, import_common.decrypt)(database.rootUserPassword);
    const {
      type,
      destinationDockerId,
      destinationDocker,
      publicPort,
      settings: { isPublic },
      databaseSecret
    } = database;
    const { privatePort, command, environmentVariables, image, volume, ulimits } = (0, import_lib.generateDatabaseConfiguration)(database, arch);
    const network = destinationDockerId && destinationDocker.network;
    const volumeName = volume.split(":")[0];
    const labels = await (0, import_lib.makeLabelForStandaloneDatabase)({ id, image, volume });
    const { workdir } = await (0, import_common.createDirectories)({ repository: type, buildId: id });
    if (databaseSecret.length > 0) {
      databaseSecret.forEach((secret) => {
        environmentVariables[secret.name] = (0, import_common.decrypt)(secret.value);
      });
    }
    const composeFile = {
      version: "3.8",
      services: {
        [id]: {
          container_name: id,
          image,
          command,
          environment: environmentVariables,
          volumes: [volume],
          ulimits,
          labels,
          ...(0, import_docker.defaultComposeConfiguration)(network)
        }
      },
      networks: {
        [network]: {
          external: true
        }
      },
      volumes: {
        [volumeName]: {
          name: volumeName
        }
      }
    };
    const composeFileDestination = `${workdir}/docker-compose.yaml`;
    await import_promises.default.writeFile(composeFileDestination, import_js_yaml.default.dump(composeFile));
    await (0, import_executeCommand.executeCommand)({
      dockerId: destinationDocker.id,
      command: `docker compose -f ${composeFileDestination} up -d`
    });
    if (isPublic)
      await (0, import_common.startTraefikTCPProxy)(destinationDocker, id, publicPort, privatePort);
  }),
  stop: import_trpc.privateProcedure.input(import_zod.z.object({ id: import_zod.z.string() })).mutation(async ({ ctx, input }) => {
    const { id } = input;
    const teamId = ctx.user?.teamId;
    const database = await import_prisma.prisma.database.findFirst({
      where: { id, teams: { some: { id: teamId === "0" ? void 0 : teamId } } },
      include: { destinationDocker: true, settings: true }
    });
    if (database.dbUserPassword)
      database.dbUserPassword = (0, import_common.decrypt)(database.dbUserPassword);
    if (database.rootUserPassword)
      database.rootUserPassword = (0, import_common.decrypt)(database.rootUserPassword);
    const everStarted = await (0, import_docker.stopDatabaseContainer)(database);
    if (everStarted)
      await (0, import_docker.stopTcpHttpProxy)(id, database.destinationDocker, database.publicPort);
    await import_prisma.prisma.database.update({
      where: { id },
      data: {
        settings: { upsert: { update: { isPublic: false }, create: { isPublic: false } } }
      }
    });
    await import_prisma.prisma.database.update({ where: { id }, data: { publicPort: null } });
  }),
  getDatabaseById: import_trpc.privateProcedure.input(import_zod.z.object({ id: import_zod.z.string() })).query(async ({ ctx, input }) => {
    const { id } = input;
    const teamId = ctx.user?.teamId;
    const database = await import_prisma.prisma.database.findFirst({
      where: { id, teams: { some: { id: teamId === "0" ? void 0 : teamId } } },
      include: { destinationDocker: true, settings: true }
    });
    if (!database) {
      throw { status: 404, message: "Database not found." };
    }
    const settings = await (0, import_common.listSettings)();
    if (database.dbUserPassword)
      database.dbUserPassword = (0, import_common.decrypt)(database.dbUserPassword);
    if (database.rootUserPassword)
      database.rootUserPassword = (0, import_common.decrypt)(database.rootUserPassword);
    const configuration = (0, import_lib.generateDatabaseConfiguration)(database, settings.arch);
    return {
      success: true,
      data: {
        privatePort: configuration?.privatePort,
        database,
        versions: await (0, import_lib.getDatabaseVersions)(database.type, settings.arch),
        settings
      }
    };
  }),
  status: import_trpc.privateProcedure.input(import_zod.z.object({ id: import_zod.z.string() })).query(async ({ ctx, input }) => {
    const id = input.id;
    const teamId = ctx.user?.teamId;
    let isRunning = false;
    const database = await import_prisma.prisma.database.findFirst({
      where: { id, teams: { some: { id: teamId === "0" ? void 0 : teamId } } },
      include: { destinationDocker: true, settings: true }
    });
    if (database) {
      const { destinationDockerId, destinationDocker } = database;
      if (destinationDockerId) {
        try {
          const { stdout } = await (0, import_executeCommand.executeCommand)({
            dockerId: destinationDocker.id,
            command: `docker inspect --format '{{json .State}}' ${id}`
          });
          if (JSON.parse(stdout).Running) {
            isRunning = true;
          }
        } catch (error) {
        }
      }
    }
    return {
      success: true,
      data: {
        isRunning
      }
    };
  }),
  cleanup: import_trpc.privateProcedure.query(async ({ ctx }) => {
    const teamId = ctx.user?.teamId;
    let databases = await import_prisma.prisma.database.findMany({
      where: { teams: { some: { id: teamId === "0" ? void 0 : teamId } } },
      include: { settings: true, destinationDocker: true, teams: true }
    });
    for (const database of databases) {
      if (!database?.version) {
        const { id } = database;
        if (database.destinationDockerId) {
          const everStarted = await (0, import_docker.stopDatabaseContainer)(database);
          if (everStarted)
            await (0, import_docker.stopTcpHttpProxy)(id, database.destinationDocker, database.publicPort);
        }
        await import_prisma.prisma.databaseSettings.deleteMany({ where: { databaseId: id } });
        await import_prisma.prisma.databaseSecret.deleteMany({ where: { databaseId: id } });
        await import_prisma.prisma.database.delete({ where: { id } });
      }
    }
    return {};
  }),
  delete: import_trpc.privateProcedure.input(import_zod.z.object({ id: import_zod.z.string(), force: import_zod.z.boolean().default(false) })).mutation(async ({ ctx, input }) => {
    const { id, force } = input;
    const teamId = ctx.user?.teamId;
    const database = await import_prisma.prisma.database.findFirst({
      where: { id, teams: { some: { id: teamId === "0" ? void 0 : teamId } } },
      include: { destinationDocker: true, settings: true }
    });
    if (!force) {
      if (database.dbUserPassword)
        database.dbUserPassword = (0, import_common.decrypt)(database.dbUserPassword);
      if (database.rootUserPassword)
        database.rootUserPassword = (0, import_common.decrypt)(database.rootUserPassword);
      if (database.destinationDockerId) {
        const everStarted = await (0, import_docker.stopDatabaseContainer)(database);
        if (everStarted)
          await (0, import_docker.stopTcpHttpProxy)(id, database.destinationDocker, database.publicPort);
      }
    }
    await import_prisma.prisma.databaseSettings.deleteMany({ where: { databaseId: id } });
    await import_prisma.prisma.databaseSecret.deleteMany({ where: { databaseId: id } });
    await import_prisma.prisma.database.delete({ where: { id } });
    return {};
  })
});
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  databasesRouter
});
