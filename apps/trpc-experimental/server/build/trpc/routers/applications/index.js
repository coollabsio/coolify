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
var applications_exports = {};
__export(applications_exports, {
  applicationsRouter: () => applicationsRouter
});
module.exports = __toCommonJS(applications_exports);
var import_zod = require("zod");
var import_promises = __toESM(require("fs/promises"));
var import_js_yaml = __toESM(require("js-yaml"));
var import_trpc = require("../../trpc");
var import_prisma = require("../../../prisma");
var import_executeCommand = require("../../../lib/executeCommand");
var import_docker = require("../../../lib/docker");
var import_lib = require("./lib");
var import_cuid = __toESM(require("cuid"));
var import_common = require("../../../lib/common");
var import_dayjs = require("../../../lib/dayjs");
var import_csvtojson = __toESM(require("csvtojson"));
const applicationsRouter = (0, import_trpc.router)({
  deleteApplication: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string(),
      force: import_zod.z.boolean().default(false)
    })
  ).mutation(async ({ input, ctx }) => {
    const { id, force } = input;
    const teamId = ctx.user.teamId;
    const application = await import_prisma.prisma.application.findUnique({
      where: { id },
      include: { destinationDocker: true }
    });
    if (!force && application?.destinationDockerId && application.destinationDocker?.network) {
      const { stdout: containers } = await (0, import_executeCommand.executeCommand)({
        dockerId: application.destinationDocker.id,
        command: `docker ps -a --filter network=${application.destinationDocker.network} --filter name=${id} --format '{{json .}}'`
      });
      if (containers) {
        const containersArray = containers.trim().split("\n");
        for (const container of containersArray) {
          const containerObj = JSON.parse(container);
          const id2 = containerObj.ID;
          await (0, import_docker.removeContainer)({ id: id2, dockerId: application.destinationDocker.id });
        }
      }
    }
    await import_prisma.prisma.applicationSettings.deleteMany({ where: { application: { id } } });
    await import_prisma.prisma.buildLog.deleteMany({ where: { applicationId: id } });
    await import_prisma.prisma.build.deleteMany({ where: { applicationId: id } });
    await import_prisma.prisma.secret.deleteMany({ where: { applicationId: id } });
    await import_prisma.prisma.applicationPersistentStorage.deleteMany({ where: { applicationId: id } });
    await import_prisma.prisma.applicationConnectedDatabase.deleteMany({ where: { applicationId: id } });
    if (teamId === "0") {
      await import_prisma.prisma.application.deleteMany({ where: { id } });
    } else {
      await import_prisma.prisma.application.deleteMany({ where: { id, teams: { some: { id: teamId } } } });
    }
    return {};
  }),
  restartPreview: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string(),
      pullmergeRequestId: import_zod.z.string()
    })
  ).mutation(async ({ input, ctx }) => {
    const { id, pullmergeRequestId } = input;
    const teamId = ctx.user.teamId;
    let application = await (0, import_lib.getApplicationFromDB)(id, teamId);
    if (application?.destinationDockerId) {
      const buildId = (0, import_cuid.default)();
      const { id: dockerId, network } = application.destinationDocker;
      const {
        secrets,
        port,
        repository,
        persistentStorage,
        id: applicationId,
        buildPack,
        exposePort
      } = application;
      let envs = [];
      if (secrets.length > 0) {
        envs = [...envs, ...(0, import_common.generateSecrets)(secrets, pullmergeRequestId, false, port)];
      }
      const { workdir } = await (0, import_common.createDirectories)({ repository, buildId });
      const labels = [];
      let image = null;
      const { stdout: container } = await (0, import_executeCommand.executeCommand)({
        dockerId,
        command: `docker container ls --filter 'label=com.docker.compose.service=${id}-${pullmergeRequestId}' --format '{{json .}}'`
      });
      const containersArray = container.trim().split("\n");
      for (const container2 of containersArray) {
        const containerObj = (0, import_docker.formatLabelsOnDocker)(container2);
        image = containerObj[0].Image;
        Object.keys(containerObj[0].Labels).forEach(function(key) {
          if (key.startsWith("coolify")) {
            labels.push(`${key}=${containerObj[0].Labels[key]}`);
          }
        });
      }
      let imageFound = false;
      try {
        await (0, import_executeCommand.executeCommand)({
          dockerId,
          command: `docker image inspect ${image}`
        });
        imageFound = true;
      } catch (error) {
      }
      if (!imageFound) {
        throw { status: 500, message: "Image not found, cannot restart application." };
      }
      const volumes = persistentStorage?.map((storage) => {
        return `${applicationId}${storage.path.replace(/\//gi, "-")}:${buildPack !== "docker" ? "/app" : ""}${storage.path}`;
      }) || [];
      const composeVolumes = volumes.map((volume) => {
        return {
          [`${volume.split(":")[0]}`]: {
            name: volume.split(":")[0]
          }
        };
      });
      const composeFile = {
        version: "3.8",
        services: {
          [`${applicationId}-${pullmergeRequestId}`]: {
            image,
            container_name: `${applicationId}-${pullmergeRequestId}`,
            volumes,
            environment: envs,
            labels,
            depends_on: [],
            expose: [port],
            ...exposePort ? { ports: [`${exposePort}:${port}`] } : {},
            ...(0, import_docker.defaultComposeConfiguration)(network)
          }
        },
        networks: {
          [network]: {
            external: true
          }
        },
        volumes: Object.assign({}, ...composeVolumes)
      };
      await import_promises.default.writeFile(`${workdir}/docker-compose.yml`, import_js_yaml.default.dump(composeFile));
      await (0, import_executeCommand.executeCommand)({ dockerId, command: `docker stop -t 0 ${id}-${pullmergeRequestId}` });
      await (0, import_executeCommand.executeCommand)({ dockerId, command: `docker rm ${id}-${pullmergeRequestId}` });
      await (0, import_executeCommand.executeCommand)({
        dockerId,
        command: `docker compose --project-directory ${workdir} up -d`
      });
    }
  }),
  getPreviewStatus: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string(),
      pullmergeRequestId: import_zod.z.string()
    })
  ).query(async ({ input, ctx }) => {
    const { id, pullmergeRequestId } = input;
    const teamId = ctx.user.teamId;
    let isRunning = false;
    let isExited = false;
    let isRestarting = false;
    let isBuilding = false;
    const application = await (0, import_lib.getApplicationFromDB)(id, teamId);
    if (application?.destinationDockerId) {
      const status = await (0, import_docker.checkContainer)({
        dockerId: application.destinationDocker.id,
        container: `${id}-${pullmergeRequestId}`
      });
      if (status?.found) {
        isRunning = status.status.isRunning;
        isExited = status.status.isExited;
        isRestarting = status.status.isRestarting;
      }
      const building = await import_prisma.prisma.build.findMany({
        where: { applicationId: id, pullmergeRequestId, status: { in: ["queued", "running"] } }
      });
      isBuilding = building.length > 0;
    }
    return {
      success: true,
      data: {
        isBuilding,
        isRunning,
        isRestarting,
        isExited
      }
    };
  }),
  loadPreviews: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string()
    })
  ).mutation(async ({ input, ctx }) => {
    const { id } = input;
    const application = await import_prisma.prisma.application.findUnique({
      where: { id },
      include: { destinationDocker: true }
    });
    const { stdout } = await (0, import_executeCommand.executeCommand)({
      dockerId: application.destinationDocker.id,
      command: `docker container ls --filter 'name=${id}-' --format "{{json .}}"`
    });
    if (stdout === "") {
      throw { status: 500, message: "No previews found." };
    }
    const containers = (0, import_docker.formatLabelsOnDocker)(stdout).filter(
      (container) => container.Labels["coolify.configuration"] && container.Labels["coolify.type"] === "standalone-application"
    );
    const jsonContainers = containers.map(
      (container) => JSON.parse(Buffer.from(container.Labels["coolify.configuration"], "base64").toString())
    ).filter((container) => {
      return container.pullmergeRequestId && container.applicationId === id;
    });
    for (const container of jsonContainers) {
      const found = await import_prisma.prisma.previewApplication.findMany({
        where: {
          applicationId: container.applicationId,
          pullmergeRequestId: container.pullmergeRequestId
        }
      });
      if (found.length === 0) {
        await import_prisma.prisma.previewApplication.create({
          data: {
            pullmergeRequestId: container.pullmergeRequestId,
            sourceBranch: container.branch,
            customDomain: container.fqdn,
            application: { connect: { id: container.applicationId } }
          }
        });
      }
    }
    return {
      success: true,
      data: {
        previews: await import_prisma.prisma.previewApplication.findMany({ where: { applicationId: id } })
      }
    };
  }),
  stopPreview: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string(),
      pullmergeRequestId: import_zod.z.string()
    })
  ).mutation(async ({ input, ctx }) => {
    const { id, pullmergeRequestId } = input;
    const teamId = ctx.user.teamId;
    const application = await (0, import_lib.getApplicationFromDB)(id, teamId);
    if (application?.destinationDockerId) {
      const container = `${id}-${pullmergeRequestId}`;
      const { id: dockerId } = application.destinationDocker;
      const { found } = await (0, import_docker.checkContainer)({ dockerId, container });
      if (found) {
        await (0, import_docker.removeContainer)({ id: container, dockerId: application.destinationDocker.id });
      }
      await import_prisma.prisma.previewApplication.deleteMany({
        where: { applicationId: application.id, pullmergeRequestId }
      });
    }
    return {};
  }),
  getUsage: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string(),
      containerId: import_zod.z.string()
    })
  ).query(async ({ input, ctx }) => {
    const { id, containerId } = input;
    const teamId = ctx.user.teamId;
    let usage = {};
    const application = await (0, import_lib.getApplicationFromDB)(id, teamId);
    if (application.destinationDockerId) {
      [usage] = await Promise.all([
        (0, import_common.getContainerUsage)(application.destinationDocker.id, containerId)
      ]);
    }
    return {
      success: true,
      data: {
        usage
      }
    };
  }),
  getLocalImages: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string()
    })
  ).query(async ({ input, ctx }) => {
    const { id } = input;
    const teamId = ctx.user.teamId;
    const application = await (0, import_lib.getApplicationFromDB)(id, teamId);
    let imagesAvailables = [];
    const { stdout } = await (0, import_executeCommand.executeCommand)({
      dockerId: application.destinationDocker.id,
      command: `docker images --format '{{.Repository}}#{{.Tag}}#{{.CreatedAt}}'`
    });
    const { stdout: runningImage } = await (0, import_executeCommand.executeCommand)({
      dockerId: application.destinationDocker.id,
      command: `docker ps -a --filter 'label=com.docker.compose.service=${id}' --format {{.Image}}`
    });
    const images = stdout.trim().split("\n").filter((image) => image.includes(id) && !image.includes("-cache"));
    for (const image of images) {
      const [repository, tag, createdAt] = image.split("#");
      if (tag.includes("-")) {
        continue;
      }
      const [year, time] = createdAt.split(" ");
      imagesAvailables.push({
        repository,
        tag,
        createdAt: (0, import_dayjs.day)(year + time).unix()
      });
    }
    imagesAvailables = imagesAvailables.sort((a, b) => b.tag - a.tag);
    return {
      success: true,
      data: {
        imagesAvailables,
        runningImage
      }
    };
  }),
  resetQueue: import_trpc.privateProcedure.mutation(async ({ ctx }) => {
    const teamId = ctx.user.teamId;
    if (teamId === "0") {
      await import_prisma.prisma.build.updateMany({
        where: { status: { in: ["queued", "running"] } },
        data: { status: "canceled" }
      });
    }
  }),
  cancelBuild: import_trpc.privateProcedure.input(
    import_zod.z.object({
      buildId: import_zod.z.string(),
      applicationId: import_zod.z.string()
    })
  ).mutation(async ({ input }) => {
    const { buildId, applicationId } = input;
    let count = 0;
    await new Promise(async (resolve, reject) => {
      const { destinationDockerId, status } = await import_prisma.prisma.build.findFirst({
        where: { id: buildId }
      });
      const { id: dockerId } = await import_prisma.prisma.destinationDocker.findFirst({
        where: { id: destinationDockerId }
      });
      const interval = setInterval(async () => {
        try {
          if (status === "failed" || status === "canceled") {
            clearInterval(interval);
            return resolve();
          }
          if (count > 15) {
            clearInterval(interval);
            await (0, import_common.cleanupDB)(buildId, applicationId);
            return reject(new Error("Canceled."));
          }
          const { stdout: buildContainers } = await (0, import_executeCommand.executeCommand)({
            dockerId,
            command: `docker container ls --filter "label=coolify.buildId=${buildId}" --format '{{json .}}'`
          });
          if (buildContainers) {
            const containersArray = buildContainers.trim().split("\n");
            for (const container of containersArray) {
              const containerObj = JSON.parse(container);
              const id = containerObj.ID;
              if (!containerObj.Names.startsWith(`${applicationId} `)) {
                await (0, import_docker.removeContainer)({ id, dockerId });
                clearInterval(interval);
                await (0, import_common.cleanupDB)(buildId, applicationId);
                return resolve();
              }
            }
          }
          count++;
        } catch (error) {
        }
      }, 100);
    });
  }),
  getBuildLogs: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string(),
      buildId: import_zod.z.string(),
      sequence: import_zod.z.number()
    })
  ).query(async ({ input }) => {
    let { id, buildId, sequence } = input;
    let file = `/app/logs/${id}_buildlog_${buildId}.csv`;
    if (import_common.isDev) {
      file = `${process.cwd()}/../../logs/${id}_buildlog_${buildId}.csv`;
    }
    const data = await import_prisma.prisma.build.findFirst({ where: { id: buildId } });
    const createdAt = (0, import_dayjs.day)(data.createdAt).utc();
    try {
      await import_promises.default.stat(file);
    } catch (error) {
      let logs2 = await import_prisma.prisma.buildLog.findMany({
        where: { buildId, time: { gt: sequence } },
        orderBy: { time: "asc" }
      });
      const data2 = await import_prisma.prisma.build.findFirst({ where: { id: buildId } });
      const createdAt2 = (0, import_dayjs.day)(data2.createdAt).utc();
      return {
        logs: logs2.map((log) => {
          log.time = Number(log.time);
          return log;
        }),
        fromDb: true,
        took: (0, import_dayjs.day)().diff(createdAt2) / 1e3,
        status: data2?.status || "queued"
      };
    }
    let fileLogs = (await import_promises.default.readFile(file)).toString();
    let decryptedLogs = await (0, import_csvtojson.default)({ noheader: true }).fromString(fileLogs);
    let logs = decryptedLogs.map((log) => {
      const parsed = {
        time: log["field1"],
        line: (0, import_common.decrypt)(log["field2"] + '","' + log["field3"])
      };
      return parsed;
    }).filter((log) => log.time > sequence);
    return {
      logs,
      fromDb: false,
      took: (0, import_dayjs.day)().diff(createdAt) / 1e3,
      status: data?.status || "queued"
    };
  }),
  getBuilds: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string(),
      buildId: import_zod.z.string().optional(),
      skip: import_zod.z.number()
    })
  ).query(async ({ input }) => {
    let { id, buildId, skip } = input;
    let builds = [];
    const buildCount = await import_prisma.prisma.build.count({ where: { applicationId: id } });
    if (buildId) {
      builds = await import_prisma.prisma.build.findMany({ where: { applicationId: id, id: buildId } });
    } else {
      builds = await import_prisma.prisma.build.findMany({
        where: { applicationId: id },
        orderBy: { createdAt: "desc" },
        take: 5 + skip
      });
    }
    builds = builds.map((build) => {
      if (build.status === "running") {
        build.elapsed = ((0, import_dayjs.day)().utc().diff((0, import_dayjs.day)(build.createdAt)) / 1e3).toFixed(0);
      }
      return build;
    });
    return {
      builds,
      buildCount
    };
  }),
  loadLogs: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string(),
      containerId: import_zod.z.string(),
      since: import_zod.z.number()
    })
  ).query(async ({ input }) => {
    let { id, containerId, since } = input;
    if (since !== 0) {
      since = (0, import_dayjs.day)(since).unix();
    }
    const {
      destinationDockerId,
      destinationDocker: { id: dockerId }
    } = await import_prisma.prisma.application.findUnique({
      where: { id },
      include: { destinationDocker: true }
    });
    if (destinationDockerId) {
      try {
        const { default: ansi } = await import("strip-ansi");
        const { stdout, stderr } = await (0, import_executeCommand.executeCommand)({
          dockerId,
          command: `docker logs --since ${since} --tail 5000 --timestamps ${containerId}`
        });
        const stripLogsStdout = stdout.toString().split("\n").map((l) => ansi(l)).filter((a) => a);
        const stripLogsStderr = stderr.toString().split("\n").map((l) => ansi(l)).filter((a) => a);
        const logs = stripLogsStderr.concat(stripLogsStdout);
        const sortedLogs = logs.sort(
          (a, b) => (0, import_dayjs.day)(a.split(" ")[0]).isAfter((0, import_dayjs.day)(b.split(" ")[0])) ? 1 : -1
        );
        return { logs: sortedLogs };
      } catch (error) {
        const { statusCode, stderr } = error;
        if (stderr.startsWith("Error: No such container")) {
          return { logs: [], noContainer: true };
        }
        if (statusCode === 404) {
          return {
            logs: []
          };
        }
      }
    }
    return {
      message: "No logs found."
    };
  }),
  getStorages: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string()
    })
  ).query(async ({ input }) => {
    const { id } = input;
    const persistentStorages = await import_prisma.prisma.applicationPersistentStorage.findMany({
      where: { applicationId: id }
    });
    return {
      success: true,
      data: {
        persistentStorages
      }
    };
  }),
  deleteStorage: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string(),
      path: import_zod.z.string()
    })
  ).mutation(async ({ input }) => {
    const { id, path } = input;
    await import_prisma.prisma.applicationPersistentStorage.deleteMany({ where: { applicationId: id, path } });
  }),
  updateStorage: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string(),
      path: import_zod.z.string(),
      storageId: import_zod.z.string(),
      newStorage: import_zod.z.boolean().optional().default(false)
    })
  ).mutation(async ({ input }) => {
    const { id, path, newStorage, storageId } = input;
    if (newStorage) {
      await import_prisma.prisma.applicationPersistentStorage.create({
        data: { path, application: { connect: { id } } }
      });
    } else {
      await import_prisma.prisma.applicationPersistentStorage.update({
        where: { id: storageId },
        data: { path }
      });
    }
  }),
  deleteSecret: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string(),
      name: import_zod.z.string()
    })
  ).mutation(async ({ input }) => {
    const { id, name } = input;
    await import_prisma.prisma.secret.deleteMany({ where: { applicationId: id, name } });
  }),
  updateSecret: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string(),
      name: import_zod.z.string(),
      value: import_zod.z.string(),
      isBuildSecret: import_zod.z.boolean().optional().default(false),
      isPreview: import_zod.z.boolean().optional().default(false)
    })
  ).mutation(async ({ input }) => {
    const { id, name, value, isBuildSecret, isPreview } = input;
    console.log({ isBuildSecret });
    await import_prisma.prisma.secret.updateMany({
      where: { applicationId: id, name, isPRMRSecret: isPreview },
      data: { value: (0, import_common.encrypt)(value.trim()), isBuildSecret }
    });
  }),
  newSecret: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string(),
      name: import_zod.z.string(),
      value: import_zod.z.string(),
      isBuildSecret: import_zod.z.boolean().optional().default(false)
    })
  ).mutation(async ({ input }) => {
    const { id, name, value, isBuildSecret } = input;
    const found = await import_prisma.prisma.secret.findMany({ where: { applicationId: id, name } });
    if (found.length > 0) {
      throw { message: "Secret already exists." };
    }
    await import_prisma.prisma.secret.create({
      data: {
        name,
        value: (0, import_common.encrypt)(value.trim()),
        isBuildSecret,
        isPRMRSecret: false,
        application: { connect: { id } }
      }
    });
    await import_prisma.prisma.secret.create({
      data: {
        name,
        value: (0, import_common.encrypt)(value.trim()),
        isBuildSecret,
        isPRMRSecret: true,
        application: { connect: { id } }
      }
    });
  }),
  getSecrets: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string()
    })
  ).query(async ({ input }) => {
    const { id } = input;
    let secrets = await import_prisma.prisma.secret.findMany({
      where: { applicationId: id, isPRMRSecret: false },
      orderBy: { createdAt: "asc" }
    });
    let previewSecrets = await import_prisma.prisma.secret.findMany({
      where: { applicationId: id, isPRMRSecret: true },
      orderBy: { createdAt: "asc" }
    });
    secrets = secrets.map((secret) => {
      secret.value = (0, import_common.decrypt)(secret.value);
      return secret;
    });
    previewSecrets = previewSecrets.map((secret) => {
      secret.value = (0, import_common.decrypt)(secret.value);
      return secret;
    });
    return {
      success: true,
      data: {
        previewSecrets: previewSecrets.sort((a, b) => {
          return ("" + a.name).localeCompare(b.name);
        }),
        secrets: secrets.sort((a, b) => {
          return ("" + a.name).localeCompare(b.name);
        })
      }
    };
  }),
  checkDomain: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string(),
      domain: import_zod.z.string()
    })
  ).query(async ({ input, ctx }) => {
    const { id, domain } = input;
    const {
      fqdn,
      settings: { dualCerts }
    } = await import_prisma.prisma.application.findUnique({ where: { id }, include: { settings: true } });
    return await (0, import_common.checkDomainsIsValidInDNS)({ hostname: domain, fqdn, dualCerts });
  }),
  checkDNS: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string(),
      fqdn: import_zod.z.string(),
      forceSave: import_zod.z.boolean(),
      dualCerts: import_zod.z.boolean(),
      exposePort: import_zod.z.number().nullable().optional()
    })
  ).mutation(async ({ input, ctx }) => {
    let { id, exposePort, fqdn, forceSave, dualCerts } = input;
    if (!fqdn) {
      return {};
    } else {
      fqdn = fqdn.toLowerCase();
    }
    if (exposePort)
      exposePort = Number(exposePort);
    const {
      destinationDocker: { engine, remoteIpAddress, remoteEngine },
      exposePort: configuredPort
    } = await import_prisma.prisma.application.findUnique({
      where: { id },
      include: { destinationDocker: true }
    });
    const { isDNSCheckEnabled } = await import_prisma.prisma.setting.findFirst({});
    const found = await (0, import_common.isDomainConfigured)({ id, fqdn, remoteIpAddress });
    if (found) {
      throw {
        status: 500,
        message: `Domain ${(0, import_common.getDomain)(fqdn).replace("www.", "")} is already in use!`
      };
    }
    if (exposePort)
      await (0, import_common.checkExposedPort)({
        id,
        configuredPort,
        exposePort,
        engine,
        remoteEngine,
        remoteIpAddress
      });
    if (isDNSCheckEnabled && !import_common.isDev && !forceSave) {
      let hostname = ctx.hostname.split(":")[0];
      if (remoteEngine)
        hostname = remoteIpAddress;
      return await (0, import_common.checkDomainsIsValidInDNS)({ hostname, fqdn, dualCerts });
    }
  }),
  saveSettings: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string(),
      previews: import_zod.z.boolean().optional(),
      debug: import_zod.z.boolean().optional(),
      dualCerts: import_zod.z.boolean().optional(),
      isBot: import_zod.z.boolean().optional(),
      autodeploy: import_zod.z.boolean().optional(),
      isDBBranching: import_zod.z.boolean().optional(),
      isCustomSSL: import_zod.z.boolean().optional()
    })
  ).mutation(async ({ ctx, input }) => {
    const { id, debug, previews, dualCerts, autodeploy, isBot, isDBBranching, isCustomSSL } = input;
    await import_prisma.prisma.application.update({
      where: { id },
      data: {
        fqdn: isBot ? null : void 0,
        settings: {
          update: { debug, previews, dualCerts, autodeploy, isBot, isDBBranching, isCustomSSL }
        }
      },
      include: { destinationDocker: true }
    });
  }),
  getImages: import_trpc.privateProcedure.input(import_zod.z.object({ buildPack: import_zod.z.string(), deploymentType: import_zod.z.string().nullable() })).query(async ({ ctx, input }) => {
    const { buildPack, deploymentType } = input;
    let publishDirectory = void 0;
    let port = void 0;
    const { baseImage, baseBuildImage, baseBuildImages, baseImages } = (0, import_lib.setDefaultBaseImage)(
      buildPack,
      deploymentType
    );
    if (buildPack === "nextjs") {
      if (deploymentType === "static") {
        publishDirectory = "out";
        port = "80";
      } else {
        publishDirectory = "";
        port = "3000";
      }
    }
    if (buildPack === "nuxtjs") {
      if (deploymentType === "static") {
        publishDirectory = "dist";
        port = "80";
      } else {
        publishDirectory = "";
        port = "3000";
      }
    }
    return {
      success: true,
      data: { baseImage, baseImages, baseBuildImage, baseBuildImages, publishDirectory, port }
    };
  }),
  getApplicationById: import_trpc.privateProcedure.input(import_zod.z.object({ id: import_zod.z.string() })).query(async ({ ctx, input }) => {
    const id = input.id;
    const teamId = ctx.user?.teamId;
    if (!teamId) {
      throw { status: 400, message: "Team not found." };
    }
    const application = await (0, import_lib.getApplicationFromDB)(id, teamId);
    return {
      success: true,
      data: { ...application }
    };
  }),
  save: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string(),
      name: import_zod.z.string(),
      buildPack: import_zod.z.string(),
      fqdn: import_zod.z.string().nullable().optional(),
      port: import_zod.z.number(),
      exposePort: import_zod.z.number().nullable().optional(),
      installCommand: import_zod.z.string(),
      buildCommand: import_zod.z.string(),
      startCommand: import_zod.z.string(),
      baseDirectory: import_zod.z.string().nullable().optional(),
      publishDirectory: import_zod.z.string().nullable().optional(),
      pythonWSGI: import_zod.z.string().nullable().optional(),
      pythonModule: import_zod.z.string().nullable().optional(),
      pythonVariable: import_zod.z.string().nullable().optional(),
      dockerFileLocation: import_zod.z.string(),
      denoMainFile: import_zod.z.string().nullable().optional(),
      denoOptions: import_zod.z.string().nullable().optional(),
      gitCommitHash: import_zod.z.string(),
      baseImage: import_zod.z.string(),
      baseBuildImage: import_zod.z.string(),
      deploymentType: import_zod.z.string().nullable().optional(),
      baseDatabaseBranch: import_zod.z.string().nullable().optional(),
      dockerComposeFile: import_zod.z.string().nullable().optional(),
      dockerComposeFileLocation: import_zod.z.string().nullable().optional(),
      dockerComposeConfiguration: import_zod.z.string().nullable().optional(),
      simpleDockerfile: import_zod.z.string().nullable().optional(),
      dockerRegistryImageName: import_zod.z.string().nullable().optional()
    })
  ).mutation(async ({ input }) => {
    let {
      id,
      name,
      buildPack,
      fqdn,
      port,
      exposePort,
      installCommand,
      buildCommand,
      startCommand,
      baseDirectory,
      publishDirectory,
      pythonWSGI,
      pythonModule,
      pythonVariable,
      dockerFileLocation,
      denoMainFile,
      denoOptions,
      gitCommitHash,
      baseImage,
      baseBuildImage,
      deploymentType,
      baseDatabaseBranch,
      dockerComposeFile,
      dockerComposeFileLocation,
      dockerComposeConfiguration,
      simpleDockerfile,
      dockerRegistryImageName
    } = input;
    const {
      destinationDocker: { engine, remoteEngine, remoteIpAddress },
      exposePort: configuredPort
    } = await import_prisma.prisma.application.findUnique({
      where: { id },
      include: { destinationDocker: true }
    });
    if (exposePort)
      await (0, import_common.checkExposedPort)({
        id,
        configuredPort,
        exposePort,
        engine,
        remoteEngine,
        remoteIpAddress
      });
    if (denoOptions)
      denoOptions = denoOptions.trim();
    const defaultConfiguration = await (0, import_common.setDefaultConfiguration)({
      buildPack,
      port,
      installCommand,
      startCommand,
      buildCommand,
      publishDirectory,
      baseDirectory,
      dockerFileLocation,
      dockerComposeFileLocation,
      denoMainFile
    });
    if (baseDatabaseBranch) {
      await import_prisma.prisma.application.update({
        where: { id },
        data: {
          name,
          fqdn,
          exposePort,
          pythonWSGI,
          pythonModule,
          pythonVariable,
          denoOptions,
          baseImage,
          gitCommitHash,
          baseBuildImage,
          deploymentType,
          dockerComposeFile,
          dockerComposeConfiguration,
          simpleDockerfile,
          dockerRegistryImageName,
          ...defaultConfiguration,
          connectedDatabase: { update: { hostedDatabaseDBName: baseDatabaseBranch } }
        }
      });
    } else {
      await import_prisma.prisma.application.update({
        where: { id },
        data: {
          name,
          fqdn,
          exposePort,
          pythonWSGI,
          pythonModule,
          gitCommitHash,
          pythonVariable,
          denoOptions,
          baseImage,
          baseBuildImage,
          deploymentType,
          dockerComposeFile,
          dockerComposeConfiguration,
          simpleDockerfile,
          dockerRegistryImageName,
          ...defaultConfiguration
        }
      });
    }
  }),
  status: import_trpc.privateProcedure.input(import_zod.z.object({ id: import_zod.z.string() })).query(async ({ ctx, input }) => {
    const id = input.id;
    const teamId = ctx.user?.teamId;
    if (!teamId) {
      throw { status: 400, message: "Team not found." };
    }
    let payload = [];
    const application = await (0, import_lib.getApplicationFromDB)(id, teamId);
    if (application?.destinationDockerId) {
      if (application.buildPack === "compose") {
        const { stdout: containers } = await (0, import_executeCommand.executeCommand)({
          dockerId: application.destinationDocker.id,
          command: `docker ps -a --filter "label=coolify.applicationId=${id}" --format '{{json .}}'`
        });
        const containersArray = containers.trim().split("\n");
        if (containersArray.length > 0 && containersArray[0] !== "") {
          for (const container of containersArray) {
            let isRunning = false;
            let isExited = false;
            let isRestarting = false;
            const containerObj = JSON.parse(container);
            const status = containerObj.State;
            if (status === "running") {
              isRunning = true;
            }
            if (status === "exited") {
              isExited = true;
            }
            if (status === "restarting") {
              isRestarting = true;
            }
            payload.push({
              name: containerObj.Names,
              status: {
                isRunning,
                isExited,
                isRestarting
              }
            });
          }
        }
      } else {
        let isRunning = false;
        let isExited = false;
        let isRestarting = false;
        const status = await (0, import_docker.checkContainer)({
          dockerId: application.destinationDocker.id,
          container: id
        });
        if (status?.found) {
          isRunning = status.status.isRunning;
          isExited = status.status.isExited;
          isRestarting = status.status.isRestarting;
          payload.push({
            name: id,
            status: {
              isRunning,
              isExited,
              isRestarting
            }
          });
        }
      }
    }
    return payload;
  }),
  cleanup: import_trpc.privateProcedure.query(async ({ ctx }) => {
    const teamId = ctx.user?.teamId;
    let applications = await import_prisma.prisma.application.findMany({
      where: { teams: { some: { id: teamId === "0" ? void 0 : teamId } } },
      include: { settings: true, destinationDocker: true, teams: true }
    });
    for (const application of applications) {
      if (!application.buildPack || !application.destinationDockerId || !application.branch || !application.settings?.isBot && !application?.fqdn) {
        if (application?.destinationDockerId && application.destinationDocker?.network) {
          const { stdout: containers } = await (0, import_executeCommand.executeCommand)({
            dockerId: application.destinationDocker.id,
            command: `docker ps -a --filter network=${application.destinationDocker.network} --filter name=${application.id} --format '{{json .}}'`
          });
          if (containers) {
            const containersArray = containers.trim().split("\n");
            for (const container of containersArray) {
              const containerObj = JSON.parse(container);
              const id = containerObj.ID;
              await (0, import_docker.removeContainer)({ id, dockerId: application.destinationDocker.id });
            }
          }
        }
        await import_prisma.prisma.applicationSettings.deleteMany({ where: { applicationId: application.id } });
        await import_prisma.prisma.buildLog.deleteMany({ where: { applicationId: application.id } });
        await import_prisma.prisma.build.deleteMany({ where: { applicationId: application.id } });
        await import_prisma.prisma.secret.deleteMany({ where: { applicationId: application.id } });
        await import_prisma.prisma.applicationPersistentStorage.deleteMany({
          where: { applicationId: application.id }
        });
        await import_prisma.prisma.applicationConnectedDatabase.deleteMany({
          where: { applicationId: application.id }
        });
        await import_prisma.prisma.application.deleteMany({ where: { id: application.id } });
      }
    }
    return {};
  }),
  stop: import_trpc.privateProcedure.input(import_zod.z.object({ id: import_zod.z.string() })).mutation(async ({ ctx, input }) => {
    const { id } = input;
    const teamId = ctx.user?.teamId;
    const application = await (0, import_lib.getApplicationFromDB)(id, teamId);
    if (application?.destinationDockerId) {
      const { id: dockerId } = application.destinationDocker;
      if (application.buildPack === "compose") {
        const { stdout: containers } = await (0, import_executeCommand.executeCommand)({
          dockerId: application.destinationDocker.id,
          command: `docker ps -a --filter "label=coolify.applicationId=${id}" --format '{{json .}}'`
        });
        const containersArray = containers.trim().split("\n");
        if (containersArray.length > 0 && containersArray[0] !== "") {
          for (const container of containersArray) {
            const containerObj = JSON.parse(container);
            await (0, import_docker.removeContainer)({
              id: containerObj.ID,
              dockerId: application.destinationDocker.id
            });
          }
        }
        return;
      }
      const { found } = await (0, import_docker.checkContainer)({ dockerId, container: id });
      if (found) {
        await (0, import_docker.removeContainer)({ id, dockerId: application.destinationDocker.id });
      }
    }
    return {};
  }),
  restart: import_trpc.privateProcedure.input(import_zod.z.object({ id: import_zod.z.string(), imageId: import_zod.z.string().nullable() })).mutation(async ({ ctx, input }) => {
    const { id, imageId } = input;
    const teamId = ctx.user?.teamId;
    let application = await (0, import_lib.getApplicationFromDB)(id, teamId);
    if (application?.destinationDockerId) {
      const buildId = (0, import_cuid.default)();
      const { id: dockerId, network } = application.destinationDocker;
      const {
        dockerRegistry,
        secrets,
        pullmergeRequestId,
        port,
        repository,
        persistentStorage,
        id: applicationId,
        buildPack,
        exposePort
      } = application;
      let location = null;
      const labels = [];
      let image = null;
      const envs = [`PORT=${port}`, "NODE_ENV=production"];
      if (secrets.length > 0) {
        secrets.forEach((secret) => {
          if (pullmergeRequestId) {
            const isSecretFound = secrets.filter((s) => s.name === secret.name && s.isPRMRSecret);
            if (isSecretFound.length > 0) {
              if (isSecretFound[0].value.includes("\\n") || isSecretFound[0].value.includes("'")) {
                envs.push(`${secret.name}=${isSecretFound[0].value}`);
              } else {
                envs.push(`${secret.name}='${isSecretFound[0].value}'`);
              }
            } else {
              if (secret.value.includes("\\n") || secret.value.includes("'")) {
                envs.push(`${secret.name}=${secret.value}`);
              } else {
                envs.push(`${secret.name}='${secret.value}'`);
              }
            }
          } else {
            if (!secret.isPRMRSecret) {
              if (secret.value.includes("\\n") || secret.value.includes("'")) {
                envs.push(`${secret.name}=${secret.value}`);
              } else {
                envs.push(`${secret.name}='${secret.value}'`);
              }
            }
          }
        });
      }
      const { workdir } = await (0, import_common.createDirectories)({ repository, buildId });
      if (imageId) {
        image = imageId;
      } else {
        const { stdout: container } = await (0, import_executeCommand.executeCommand)({
          dockerId,
          command: `docker container ls --filter 'label=com.docker.compose.service=${id}' --format '{{json .}}'`
        });
        const containersArray = container.trim().split("\n");
        for (const container2 of containersArray) {
          const containerObj = (0, import_docker.formatLabelsOnDocker)(container2);
          image = containerObj[0].Image;
          Object.keys(containerObj[0].Labels).forEach(function(key) {
            if (key.startsWith("coolify")) {
              labels.push(`${key}=${containerObj[0].Labels[key]}`);
            }
          });
        }
      }
      if (dockerRegistry) {
        const { url, username, password } = dockerRegistry;
        location = await (0, import_common.saveDockerRegistryCredentials)({ url, username, password, workdir });
      }
      let imageFoundLocally = false;
      try {
        await (0, import_executeCommand.executeCommand)({
          dockerId,
          command: `docker image inspect ${image}`
        });
        imageFoundLocally = true;
      } catch (error) {
      }
      let imageFoundRemotely = false;
      try {
        await (0, import_executeCommand.executeCommand)({
          dockerId,
          command: `docker ${location ? `--config ${location}` : ""} pull ${image}`
        });
        imageFoundRemotely = true;
      } catch (error) {
      }
      if (!imageFoundLocally && !imageFoundRemotely) {
        throw { status: 500, message: "Image not found, cannot restart application." };
      }
      await import_promises.default.writeFile(`${workdir}/.env`, envs.join("\n"));
      let envFound = false;
      try {
        envFound = !!await import_promises.default.stat(`${workdir}/.env`);
      } catch (error) {
      }
      const volumes = persistentStorage?.map((storage) => {
        return `${applicationId}${storage.path.replace(/\//gi, "-")}:${buildPack !== "docker" ? "/app" : ""}${storage.path}`;
      }) || [];
      const composeVolumes = volumes.map((volume) => {
        return {
          [`${volume.split(":")[0]}`]: {
            name: volume.split(":")[0]
          }
        };
      });
      const composeFile = {
        version: "3.8",
        services: {
          [applicationId]: {
            image,
            container_name: applicationId,
            volumes,
            env_file: envFound ? [`${workdir}/.env`] : [],
            labels,
            depends_on: [],
            expose: [port],
            ...exposePort ? { ports: [`${exposePort}:${port}`] } : {},
            ...(0, import_docker.defaultComposeConfiguration)(network)
          }
        },
        networks: {
          [network]: {
            external: true
          }
        },
        volumes: Object.assign({}, ...composeVolumes)
      };
      await import_promises.default.writeFile(`${workdir}/docker-compose.yml`, import_js_yaml.default.dump(composeFile));
      try {
        await (0, import_executeCommand.executeCommand)({ dockerId, command: `docker stop -t 0 ${id}` });
        await (0, import_executeCommand.executeCommand)({ dockerId, command: `docker rm ${id}` });
      } catch (error) {
      }
      await (0, import_executeCommand.executeCommand)({
        dockerId,
        command: `docker compose --project-directory ${workdir} up -d`
      });
    }
    return {};
  }),
  deploy: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string(),
      forceRebuild: import_zod.z.boolean().default(false),
      pullmergeRequestId: import_zod.z.string().nullable().optional(),
      branch: import_zod.z.string().nullable().optional()
    })
  ).mutation(async ({ ctx, input }) => {
    const { id, pullmergeRequestId, branch, forceRebuild } = input;
    const teamId = ctx.user?.teamId;
    const buildId = await (0, import_lib.deployApplication)(id, teamId, forceRebuild, pullmergeRequestId, branch);
    return {
      buildId
    };
  }),
  forceRedeploy: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string()
    })
  ).mutation(async ({ ctx, input }) => {
    const { id } = input;
    const teamId = ctx.user?.teamId;
    const buildId = await (0, import_lib.deployApplication)(id, teamId, true);
    return {
      buildId
    };
  }),
  delete: import_trpc.privateProcedure.input(import_zod.z.object({ force: import_zod.z.boolean(), id: import_zod.z.string() })).mutation(async ({ ctx, input }) => {
    const { id, force } = input;
    const teamId = ctx.user?.teamId;
    const application = await import_prisma.prisma.application.findUnique({
      where: { id },
      include: { destinationDocker: true }
    });
    if (!force && application?.destinationDockerId && application.destinationDocker?.network) {
      const { stdout: containers } = await (0, import_executeCommand.executeCommand)({
        dockerId: application.destinationDocker.id,
        command: `docker ps -a --filter network=${application.destinationDocker.network} --filter name=${id} --format '{{json .}}'`
      });
      if (containers) {
        const containersArray = containers.trim().split("\n");
        for (const container of containersArray) {
          const containerObj = JSON.parse(container);
          const id2 = containerObj.ID;
          await (0, import_docker.removeContainer)({ id: id2, dockerId: application.destinationDocker.id });
        }
      }
    }
    await import_prisma.prisma.applicationSettings.deleteMany({ where: { application: { id } } });
    await import_prisma.prisma.buildLog.deleteMany({ where: { applicationId: id } });
    await import_prisma.prisma.build.deleteMany({ where: { applicationId: id } });
    await import_prisma.prisma.secret.deleteMany({ where: { applicationId: id } });
    await import_prisma.prisma.applicationPersistentStorage.deleteMany({ where: { applicationId: id } });
    await import_prisma.prisma.applicationConnectedDatabase.deleteMany({ where: { applicationId: id } });
    if (teamId === "0") {
      await import_prisma.prisma.application.deleteMany({ where: { id } });
    } else {
      await import_prisma.prisma.application.deleteMany({ where: { id, teams: { some: { id: teamId } } } });
    }
    return {};
  })
});
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  applicationsRouter
});
