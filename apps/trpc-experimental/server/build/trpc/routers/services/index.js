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
var services_exports = {};
__export(services_exports, {
  getServiceFromDB: () => getServiceFromDB,
  servicesRouter: () => servicesRouter
});
module.exports = __toCommonJS(services_exports);
var import_zod = require("zod");
var import_js_yaml = __toESM(require("js-yaml"));
var import_promises = __toESM(require("fs/promises"));
var import_path = __toESM(require("path"));
var import_trpc = require("../../trpc");
var import_common = require("../../../lib/common");
var import_prisma = require("../../../prisma");
var import_executeCommand = require("../../../lib/executeCommand");
var import_lib = require("./lib");
var import_docker = require("../../../lib/docker");
var import_cuid = __toESM(require("cuid"));
var import_dayjs = require("../../../lib/dayjs");
const servicesRouter = (0, import_trpc.router)({
  getLogs: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string(),
      containerId: import_zod.z.string(),
      since: import_zod.z.number().optional().default(0)
    })
  ).query(async ({ input, ctx }) => {
    let { id, containerId, since } = input;
    if (since !== 0) {
      since = (0, import_dayjs.day)(since).unix();
    }
    const {
      destinationDockerId,
      destinationDocker: { id: dockerId }
    } = await import_prisma.prisma.service.findUnique({
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
        return {
          data: {
            logs: sortedLogs
          }
        };
      } catch (error) {
        const { statusCode, stderr } = error;
        if (stderr.startsWith("Error: No such container")) {
          return {
            data: {
              logs: [],
              noContainer: true
            }
          };
        }
        if (statusCode === 404) {
          return {
            data: {
              logs: []
            }
          };
        }
      }
    }
    return {
      message: "No logs found."
    };
  }),
  deleteStorage: import_trpc.privateProcedure.input(
    import_zod.z.object({
      storageId: import_zod.z.string()
    })
  ).mutation(async ({ input, ctx }) => {
    const { storageId } = input;
    await import_prisma.prisma.servicePersistentStorage.deleteMany({ where: { id: storageId } });
  }),
  saveStorage: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string(),
      path: import_zod.z.string(),
      isNewStorage: import_zod.z.boolean(),
      storageId: import_zod.z.string().optional().nullable(),
      containerId: import_zod.z.string().optional()
    })
  ).mutation(async ({ input, ctx }) => {
    const { id, path: path2, isNewStorage, storageId, containerId } = input;
    if (isNewStorage) {
      const volumeName = `${id}-custom${path2.replace(/\//gi, "-")}`;
      const found = await import_prisma.prisma.servicePersistentStorage.findFirst({
        where: { path: path2, containerId }
      });
      if (found) {
        throw {
          status: 500,
          message: "Persistent storage already exists for this container and path."
        };
      }
      await import_prisma.prisma.servicePersistentStorage.create({
        data: { path: path2, volumeName, containerId, service: { connect: { id } } }
      });
    } else {
      await import_prisma.prisma.servicePersistentStorage.update({
        where: { id: storageId },
        data: { path: path2, containerId }
      });
    }
  }),
  getStorages: import_trpc.privateProcedure.input(import_zod.z.object({ id: import_zod.z.string() })).query(async ({ input, ctx }) => {
    const { id } = input;
    const persistentStorages = await import_prisma.prisma.servicePersistentStorage.findMany({
      where: { serviceId: id }
    });
    return {
      success: true,
      data: {
        persistentStorages
      }
    };
  }),
  deleteSecret: import_trpc.privateProcedure.input(import_zod.z.object({ id: import_zod.z.string(), name: import_zod.z.string() })).mutation(async ({ input, ctx }) => {
    const { id, name } = input;
    await import_prisma.prisma.serviceSecret.deleteMany({ where: { serviceId: id, name } });
  }),
  saveService: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string(),
      name: import_zod.z.string(),
      fqdn: import_zod.z.string().optional(),
      exposePort: import_zod.z.string().optional(),
      type: import_zod.z.string(),
      serviceSetting: import_zod.z.any(),
      version: import_zod.z.string().optional()
    })
  ).mutation(async ({ input, ctx }) => {
    const teamId = ctx.user?.teamId;
    let { id, name, fqdn, exposePort, type, serviceSetting, version } = input;
    if (fqdn)
      fqdn = fqdn.toLowerCase();
    if (exposePort)
      exposePort = Number(exposePort);
    type = (0, import_common.fixType)(type);
    const data = {
      fqdn,
      name,
      exposePort,
      version
    };
    const templates = await (0, import_common.getTemplates)();
    const service = await import_prisma.prisma.service.findUnique({ where: { id } });
    const foundTemplate = templates.find((t) => (0, import_common.fixType)(t.type) === (0, import_common.fixType)(service.type));
    for (const setting of serviceSetting) {
      let { id: settingId, name: name2, value, changed = false, isNew = false, variableName } = setting;
      if (value) {
        if (changed) {
          await import_prisma.prisma.serviceSetting.update({ where: { id: settingId }, data: { value } });
        }
        if (isNew) {
          if (!variableName) {
            variableName = foundTemplate?.variables.find((v) => v.name === name2).id;
          }
          await import_prisma.prisma.serviceSetting.create({
            data: { name: name2, value, variableName, service: { connect: { id } } }
          });
        }
      }
    }
    await import_prisma.prisma.service.update({
      where: { id },
      data
    });
  }),
  createSecret: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string(),
      name: import_zod.z.string(),
      value: import_zod.z.string(),
      isBuildSecret: import_zod.z.boolean().optional(),
      isPRMRSecret: import_zod.z.boolean().optional(),
      isNew: import_zod.z.boolean().optional()
    })
  ).mutation(async ({ input }) => {
    let { id, name, value, isNew } = input;
    if (isNew) {
      const found = await import_prisma.prisma.serviceSecret.findFirst({ where: { name, serviceId: id } });
      if (found) {
        throw `Secret ${name} already exists.`;
      } else {
        value = (0, import_common.encrypt)(value.trim());
        await import_prisma.prisma.serviceSecret.create({
          data: { name, value, service: { connect: { id } } }
        });
      }
    } else {
      value = (0, import_common.encrypt)(value.trim());
      const found = await import_prisma.prisma.serviceSecret.findFirst({ where: { serviceId: id, name } });
      if (found) {
        await import_prisma.prisma.serviceSecret.updateMany({
          where: { serviceId: id, name },
          data: { value }
        });
      } else {
        await import_prisma.prisma.serviceSecret.create({
          data: { name, value, service: { connect: { id } } }
        });
      }
    }
  }),
  getSecrets: import_trpc.privateProcedure.input(import_zod.z.object({ id: import_zod.z.string() })).query(async ({ input, ctx }) => {
    const { id } = input;
    const teamId = ctx.user?.teamId;
    const service = await getServiceFromDB({ id, teamId });
    let secrets = await import_prisma.prisma.serviceSecret.findMany({
      where: { serviceId: id },
      orderBy: { createdAt: "desc" }
    });
    const templates = await (0, import_common.getTemplates)();
    if (!templates)
      throw new Error("No templates found. Please contact support.");
    const foundTemplate = templates.find((t) => (0, import_common.fixType)(t.type) === service.type);
    secrets = secrets.map((secret) => {
      const foundVariable = foundTemplate?.variables?.find((v) => v.name === secret.name) || null;
      if (foundVariable) {
        secret.readOnly = foundVariable.readOnly;
      }
      secret.value = (0, import_common.decrypt)(secret.value);
      return secret;
    });
    return {
      success: true,
      data: {
        secrets
      }
    };
  }),
  wordpress: import_trpc.privateProcedure.input(import_zod.z.object({ id: import_zod.z.string(), ftpEnabled: import_zod.z.boolean() })).mutation(async ({ input, ctx }) => {
    const { id } = input;
    const teamId = ctx.user?.teamId;
    const {
      service: {
        destinationDocker: { engine, remoteEngine, remoteIpAddress }
      }
    } = await import_prisma.prisma.wordpress.findUnique({
      where: { serviceId: id },
      include: { service: { include: { destinationDocker: true } } }
    });
    const publicPort = await (0, import_lib.getFreePublicPort)({ id, remoteEngine, engine, remoteIpAddress });
    let ftpUser = (0, import_cuid.default)();
    let ftpPassword = (0, import_lib.generatePassword)({});
    const hostkeyDir = import_common.isDev ? "/tmp/hostkeys" : "/app/ssl/hostkeys";
    try {
      const data = await import_prisma.prisma.wordpress.update({
        where: { serviceId: id },
        data: { ftpEnabled },
        include: { service: { include: { destinationDocker: true } } }
      });
      const {
        service: { destinationDockerId, destinationDocker },
        ftpPublicPort,
        ftpUser: user,
        ftpPassword: savedPassword,
        ftpHostKey,
        ftpHostKeyPrivate
      } = data;
      const { network, engine: engine2 } = destinationDocker;
      if (ftpEnabled) {
        if (user)
          ftpUser = user;
        if (savedPassword)
          ftpPassword = (0, import_common.decrypt)(savedPassword);
        const { stdout: password } = await (0, import_executeCommand.executeCommand)({
          command: `echo ${ftpPassword} | openssl passwd -1 -stdin`,
          shell: true
        });
        if (destinationDockerId) {
          try {
            await import_promises.default.stat(hostkeyDir);
          } catch (error) {
            await (0, import_executeCommand.executeCommand)({ command: `mkdir -p ${hostkeyDir}` });
          }
          if (!ftpHostKey) {
            await (0, import_executeCommand.executeCommand)({
              command: `ssh-keygen -t ed25519 -f ssh_host_ed25519_key -N "" -q -f ${hostkeyDir}/${id}.ed25519`
            });
            const { stdout: ftpHostKey2 } = await (0, import_executeCommand.executeCommand)({
              command: `cat ${hostkeyDir}/${id}.ed25519`
            });
            await import_prisma.prisma.wordpress.update({
              where: { serviceId: id },
              data: { ftpHostKey: (0, import_common.encrypt)(ftpHostKey2) }
            });
          } else {
            await (0, import_executeCommand.executeCommand)({
              command: `echo "${(0, import_common.decrypt)(ftpHostKey)}" > ${hostkeyDir}/${id}.ed25519`,
              shell: true
            });
          }
          if (!ftpHostKeyPrivate) {
            await (0, import_executeCommand.executeCommand)({
              command: `ssh-keygen -t rsa -b 4096 -N "" -f ${hostkeyDir}/${id}.rsa`
            });
            const { stdout: ftpHostKeyPrivate2 } = await (0, import_executeCommand.executeCommand)({
              command: `cat ${hostkeyDir}/${id}.rsa`
            });
            await import_prisma.prisma.wordpress.update({
              where: { serviceId: id },
              data: { ftpHostKeyPrivate: (0, import_common.encrypt)(ftpHostKeyPrivate2) }
            });
          } else {
            await (0, import_executeCommand.executeCommand)({
              command: `echo "${(0, import_common.decrypt)(ftpHostKeyPrivate)}" > ${hostkeyDir}/${id}.rsa`,
              shell: true
            });
          }
          await import_prisma.prisma.wordpress.update({
            where: { serviceId: id },
            data: {
              ftpPublicPort: publicPort,
              ftpUser: user ? void 0 : ftpUser,
              ftpPassword: savedPassword ? void 0 : (0, import_common.encrypt)(ftpPassword)
            }
          });
          try {
            const { found: isRunning } = await (0, import_docker.checkContainer)({
              dockerId: destinationDocker.id,
              container: `${id}-ftp`
            });
            if (isRunning) {
              await (0, import_executeCommand.executeCommand)({
                dockerId: destinationDocker.id,
                command: `docker stop -t 0 ${id}-ftp && docker rm ${id}-ftp`,
                shell: true
              });
            }
          } catch (error) {
          }
          const volumes = [
            `${id}-wordpress-data:/home/${ftpUser}/wordpress`,
            `${import_common.isDev ? hostkeyDir : "/var/lib/docker/volumes/coolify-ssl-certs/_data/hostkeys"}/${id}.ed25519:/etc/ssh/ssh_host_ed25519_key`,
            `${import_common.isDev ? hostkeyDir : "/var/lib/docker/volumes/coolify-ssl-certs/_data/hostkeys"}/${id}.rsa:/etc/ssh/ssh_host_rsa_key`,
            `${import_common.isDev ? hostkeyDir : "/var/lib/docker/volumes/coolify-ssl-certs/_data/hostkeys"}/${id}.sh:/etc/sftp.d/chmod.sh`
          ];
          const compose = {
            version: "3.8",
            services: {
              [`${id}-ftp`]: {
                image: `atmoz/sftp:alpine`,
                command: `'${ftpUser}:${password.replace("\n", "").replace(/\$/g, "$$$")}:e:33'`,
                extra_hosts: ["host.docker.internal:host-gateway"],
                container_name: `${id}-ftp`,
                volumes,
                networks: [network],
                depends_on: [],
                restart: "always"
              }
            },
            networks: {
              [network]: {
                external: true
              }
            },
            volumes: {
              [`${id}-wordpress-data`]: {
                external: true,
                name: `${id}-wordpress-data`
              }
            }
          };
          await import_promises.default.writeFile(
            `${hostkeyDir}/${id}.sh`,
            `#!/bin/bash
chmod 600 /etc/ssh/ssh_host_ed25519_key /etc/ssh/ssh_host_rsa_key
userdel -f xfs
chown -R 33:33 /home/${ftpUser}/wordpress/`
          );
          await (0, import_executeCommand.executeCommand)({ command: `chmod +x ${hostkeyDir}/${id}.sh` });
          await import_promises.default.writeFile(`${hostkeyDir}/${id}-docker-compose.yml`, import_js_yaml.default.dump(compose));
          await (0, import_executeCommand.executeCommand)({
            dockerId: destinationDocker.id,
            command: `docker compose -f ${hostkeyDir}/${id}-docker-compose.yml up -d`
          });
        }
        return {
          publicPort,
          ftpUser,
          ftpPassword
        };
      } else {
        await import_prisma.prisma.wordpress.update({
          where: { serviceId: id },
          data: { ftpPublicPort: null }
        });
        try {
          await (0, import_executeCommand.executeCommand)({
            dockerId: destinationDocker.id,
            command: `docker stop -t 0 ${id}-ftp && docker rm ${id}-ftp`,
            shell: true
          });
        } catch (error) {
        }
        await (0, import_docker.stopTcpHttpProxy)(id, destinationDocker, ftpPublicPort);
      }
    } catch ({ status, message }) {
      throw message;
    } finally {
      try {
        await (0, import_executeCommand.executeCommand)({
          command: `rm -fr ${hostkeyDir}/${id}-docker-compose.yml ${hostkeyDir}/${id}.ed25519 ${hostkeyDir}/${id}.ed25519.pub ${hostkeyDir}/${id}.rsa ${hostkeyDir}/${id}.rsa.pub ${hostkeyDir}/${id}.sh`
        });
      } catch (error) {
      }
    }
  }),
  start: import_trpc.privateProcedure.input(import_zod.z.object({ id: import_zod.z.string() })).mutation(async ({ input, ctx }) => {
    const { id } = input;
    const teamId = ctx.user?.teamId;
    const service = await getServiceFromDB({ id, teamId });
    const arm = (0, import_common.isARM)(service.arch);
    const { type, destinationDockerId, destinationDocker, persistentStorage, exposePort } = service;
    const { workdir } = await (0, import_common.createDirectories)({ repository: type, buildId: id });
    const template = await (0, import_lib.parseAndFindServiceTemplates)(service, workdir, true);
    const network = destinationDockerId && destinationDocker.network;
    const config = {};
    for (const s in template.services) {
      let newEnvironments = [];
      if (arm) {
        if (template.services[s]?.environmentArm?.length > 0) {
          for (const environment of template.services[s].environmentArm) {
            let [env, ...value] = environment.split("=");
            value = value.join("=");
            if (!value.startsWith("$$secret") && value !== "") {
              newEnvironments.push(`${env}=${value}`);
            }
          }
        }
      } else {
        if (template.services[s]?.environment?.length > 0) {
          for (const environment of template.services[s].environment) {
            let [env, ...value] = environment.split("=");
            value = value.join("=");
            if (!value.startsWith("$$secret") && value !== "") {
              newEnvironments.push(`${env}=${value}`);
            }
          }
        }
      }
      const secrets = await (0, import_lib.verifyAndDecryptServiceSecrets)(id);
      for (const secret of secrets) {
        const { name, value } = secret;
        if (value) {
          const foundEnv = !!template.services[s].environment?.find(
            (env) => env.startsWith(`${name}=`)
          );
          const foundNewEnv = !!newEnvironments?.find((env) => env.startsWith(`${name}=`));
          if (foundEnv && !foundNewEnv) {
            newEnvironments.push(`${name}=${value}`);
          }
          if (!foundEnv && !foundNewEnv && s === id) {
            newEnvironments.push(`${name}=${value}`);
          }
        }
      }
      const customVolumes = await import_prisma.prisma.servicePersistentStorage.findMany({
        where: { serviceId: id }
      });
      let volumes = /* @__PURE__ */ new Set();
      if (arm) {
        template.services[s]?.volumesArm && template.services[s].volumesArm.length > 0 && template.services[s].volumesArm.forEach((v) => volumes.add(v));
      } else {
        template.services[s]?.volumes && template.services[s].volumes.length > 0 && template.services[s].volumes.forEach((v) => volumes.add(v));
      }
      if (service.type === "plausibleanalytics" && service.plausibleAnalytics?.id) {
        let temp = Array.from(volumes);
        temp.forEach((a) => {
          const t = a.replace(service.id, service.plausibleAnalytics.id);
          volumes.delete(a);
          volumes.add(t);
        });
      }
      if (customVolumes.length > 0) {
        for (const customVolume of customVolumes) {
          const { volumeName, path: path2, containerId } = customVolume;
          if (volumes && volumes.size > 0 && !volumes.has(`${volumeName}:${path2}`) && containerId === service) {
            volumes.add(`${volumeName}:${path2}`);
          }
        }
      }
      let ports = [];
      if (template.services[s].proxy?.length > 0) {
        for (const proxy of template.services[s].proxy) {
          if (proxy.hostPort) {
            ports.push(`${proxy.hostPort}:${proxy.port}`);
          }
        }
      } else {
        if (template.services[s].ports?.length === 1) {
          for (const port of template.services[s].ports) {
            if (exposePort) {
              ports.push(`${exposePort}:${port}`);
            }
          }
        }
      }
      let image = template.services[s].image;
      if (arm && template.services[s].imageArm) {
        image = template.services[s].imageArm;
      }
      config[s] = {
        container_name: s,
        build: template.services[s].build || void 0,
        command: template.services[s].command,
        entrypoint: template.services[s]?.entrypoint,
        image,
        expose: template.services[s].ports,
        ports: ports.length > 0 ? ports : void 0,
        volumes: Array.from(volumes),
        environment: newEnvironments,
        depends_on: template.services[s]?.depends_on,
        ulimits: template.services[s]?.ulimits,
        cap_drop: template.services[s]?.cap_drop,
        cap_add: template.services[s]?.cap_add,
        labels: (0, import_common.makeLabelForServices)(type),
        ...(0, import_docker.defaultComposeConfiguration)(network)
      };
      if (template.services[s]?.files?.length > 0) {
        if (!config[s].build) {
          config[s].build = {
            context: workdir,
            dockerfile: `Dockerfile.${s}`
          };
        }
        let Dockerfile = `
                    FROM ${template.services[s].image}`;
        for (const file of template.services[s].files) {
          const { location, content } = file;
          const source = import_path.default.join(workdir, location);
          await import_promises.default.mkdir(import_path.default.dirname(source), { recursive: true });
          await import_promises.default.writeFile(source, content);
          Dockerfile += `
                        COPY .${location} ${location}`;
        }
        await import_promises.default.writeFile(`${workdir}/Dockerfile.${s}`, Dockerfile);
      }
    }
    const { volumeMounts } = (0, import_lib.persistentVolumes)(id, persistentStorage, config);
    const composeFile = {
      version: "3.8",
      services: config,
      networks: {
        [network]: {
          external: true
        }
      },
      volumes: volumeMounts
    };
    const composeFileDestination = `${workdir}/docker-compose.yaml`;
    await import_promises.default.writeFile(composeFileDestination, import_js_yaml.default.dump(composeFile));
    let fastify = null;
    await (0, import_lib.startServiceContainers)(fastify, id, teamId, destinationDocker.id, composeFileDestination);
    if (service.type === "minio") {
      try {
        const { stdout: containers } = await (0, import_executeCommand.executeCommand)({
          dockerId: destinationDocker.id,
          command: `docker container ls -a --filter 'name=${id}-' --format {{.ID}}`
        });
        if (containers) {
          const containerArray = containers.split("\n");
          if (containerArray.length > 0) {
            for (const container of containerArray) {
              await (0, import_executeCommand.executeCommand)({
                dockerId: destinationDockerId,
                command: `docker stop -t 0 ${container}`
              });
              await (0, import_executeCommand.executeCommand)({
                dockerId: destinationDockerId,
                command: `docker rm --force ${container}`
              });
            }
          }
        }
      } catch (error) {
      }
      try {
        const { stdout: containers } = await (0, import_executeCommand.executeCommand)({
          dockerId: destinationDocker.id,
          command: `docker container ls -a --filter 'name=${id}-' --format {{.ID}}`
        });
        if (containers) {
          const containerArray = containers.split("\n");
          if (containerArray.length > 0) {
            for (const container of containerArray) {
              await (0, import_executeCommand.executeCommand)({
                dockerId: destinationDockerId,
                command: `docker stop -t 0 ${container}`
              });
              await (0, import_executeCommand.executeCommand)({
                dockerId: destinationDockerId,
                command: `docker rm --force ${container}`
              });
            }
          }
        }
      } catch (error) {
      }
    }
  }),
  stop: import_trpc.privateProcedure.input(import_zod.z.object({ id: import_zod.z.string() })).mutation(async ({ input, ctx }) => {
    const { id } = input;
    const teamId = ctx.user?.teamId;
    const { destinationDockerId } = await getServiceFromDB({ id, teamId });
    if (destinationDockerId) {
      const { stdout: containers } = await (0, import_executeCommand.executeCommand)({
        dockerId: destinationDockerId,
        command: `docker ps -a --filter 'label=com.docker.compose.project=${id}' --format {{.ID}}`
      });
      if (containers) {
        const containerArray = containers.split("\n");
        if (containerArray.length > 0) {
          for (const container of containerArray) {
            await (0, import_executeCommand.executeCommand)({
              dockerId: destinationDockerId,
              command: `docker stop -t 0 ${container}`
            });
            await (0, import_executeCommand.executeCommand)({
              dockerId: destinationDockerId,
              command: `docker rm --force ${container}`
            });
          }
        }
      }
      return {};
    }
  }),
  getServices: import_trpc.privateProcedure.input(import_zod.z.object({ id: import_zod.z.string() })).query(async ({ input, ctx }) => {
    const { id } = input;
    const teamId = ctx.user?.teamId;
    const service = await getServiceFromDB({ id, teamId });
    if (!service) {
      throw { status: 404, message: "Service not found." };
    }
    let template = {};
    let tags = [];
    if (service.type) {
      template = await (0, import_lib.parseAndFindServiceTemplates)(service);
      tags = await (0, import_common.getTags)(service.type);
    }
    return {
      success: true,
      data: {
        settings: await (0, import_common.listSettings)(),
        service,
        template,
        tags
      }
    };
  }),
  status: import_trpc.privateProcedure.input(import_zod.z.object({ id: import_zod.z.string() })).query(async ({ ctx, input }) => {
    const id = input.id;
    const teamId = ctx.user?.teamId;
    if (!teamId) {
      throw { status: 400, message: "Team not found." };
    }
    const service = await getServiceFromDB({ id, teamId });
    const { destinationDockerId } = service;
    let payload = {};
    if (destinationDockerId) {
      const { stdout: containers } = await (0, import_executeCommand.executeCommand)({
        dockerId: service.destinationDocker.id,
        command: `docker ps -a --filter "label=com.docker.compose.project=${id}" --format '{{json .}}'`
      });
      if (containers) {
        const containersArray = containers.trim().split("\n");
        if (containersArray.length > 0 && containersArray[0] !== "") {
          const templates = await (0, import_common.getTemplates)();
          let template = templates.find((t) => t.type === service.type);
          const templateStr = JSON.stringify(template);
          if (templateStr) {
            template = JSON.parse(templateStr.replaceAll("$$id", service.id));
          }
          for (const container of containersArray) {
            let isRunning = false;
            let isExited = false;
            let isRestarting = false;
            let isExcluded = false;
            const containerObj = JSON.parse(container);
            const exclude = template?.services[containerObj.Names]?.exclude;
            if (exclude) {
              payload[containerObj.Names] = {
                status: {
                  isExcluded: true,
                  isRunning: false,
                  isExited: false,
                  isRestarting: false
                }
              };
              continue;
            }
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
            payload[containerObj.Names] = {
              status: {
                isExcluded,
                isRunning,
                isExited,
                isRestarting
              }
            };
          }
        }
      }
    }
    return payload;
  }),
  cleanup: import_trpc.privateProcedure.query(async ({ ctx }) => {
    const teamId = ctx.user?.teamId;
    let services = await import_prisma.prisma.service.findMany({
      where: { teams: { some: { id: teamId === "0" ? void 0 : teamId } } },
      include: { destinationDocker: true, teams: true }
    });
    for (const service of services) {
      if (!service.fqdn) {
        if (service.destinationDockerId) {
          const { stdout: containers } = await (0, import_executeCommand.executeCommand)({
            dockerId: service.destinationDockerId,
            command: `docker ps -a --filter 'label=com.docker.compose.project=${service.id}' --format {{.ID}}`
          });
          if (containers) {
            const containerArray = containers.split("\n");
            if (containerArray.length > 0) {
              for (const container of containerArray) {
                await (0, import_executeCommand.executeCommand)({
                  dockerId: service.destinationDockerId,
                  command: `docker stop -t 0 ${container}`
                });
                await (0, import_executeCommand.executeCommand)({
                  dockerId: service.destinationDockerId,
                  command: `docker rm --force ${container}`
                });
              }
            }
          }
        }
        await (0, import_common.removeService)({ id: service.id });
      }
    }
  }),
  delete: import_trpc.privateProcedure.input(import_zod.z.object({ force: import_zod.z.boolean(), id: import_zod.z.string() })).mutation(async ({ input }) => {
    const { id } = input;
    await import_prisma.prisma.serviceSecret.deleteMany({ where: { serviceId: id } });
    await import_prisma.prisma.serviceSetting.deleteMany({ where: { serviceId: id } });
    await import_prisma.prisma.servicePersistentStorage.deleteMany({ where: { serviceId: id } });
    await import_prisma.prisma.meiliSearch.deleteMany({ where: { serviceId: id } });
    await import_prisma.prisma.fider.deleteMany({ where: { serviceId: id } });
    await import_prisma.prisma.ghost.deleteMany({ where: { serviceId: id } });
    await import_prisma.prisma.umami.deleteMany({ where: { serviceId: id } });
    await import_prisma.prisma.hasura.deleteMany({ where: { serviceId: id } });
    await import_prisma.prisma.plausibleAnalytics.deleteMany({ where: { serviceId: id } });
    await import_prisma.prisma.minio.deleteMany({ where: { serviceId: id } });
    await import_prisma.prisma.vscodeserver.deleteMany({ where: { serviceId: id } });
    await import_prisma.prisma.wordpress.deleteMany({ where: { serviceId: id } });
    await import_prisma.prisma.glitchTip.deleteMany({ where: { serviceId: id } });
    await import_prisma.prisma.moodle.deleteMany({ where: { serviceId: id } });
    await import_prisma.prisma.appwrite.deleteMany({ where: { serviceId: id } });
    await import_prisma.prisma.searxng.deleteMany({ where: { serviceId: id } });
    await import_prisma.prisma.weblate.deleteMany({ where: { serviceId: id } });
    await import_prisma.prisma.taiga.deleteMany({ where: { serviceId: id } });
    await import_prisma.prisma.service.delete({ where: { id } });
    return {};
  })
});
async function getServiceFromDB({
  id,
  teamId
}) {
  const settings = await import_prisma.prisma.setting.findFirst();
  const body = await import_prisma.prisma.service.findFirst({
    where: { id, teams: { some: { id: teamId === "0" ? void 0 : teamId } } },
    include: {
      destinationDocker: true,
      persistentStorage: true,
      serviceSecret: true,
      serviceSetting: true,
      wordpress: true,
      plausibleAnalytics: true
    }
  });
  if (!body) {
    return null;
  }
  if (body?.serviceSecret.length > 0) {
    body.serviceSecret = body.serviceSecret.map((s) => {
      s.value = (0, import_common.decrypt)(s.value);
      return s;
    });
  }
  if (body.wordpress) {
    body.wordpress.ftpPassword = (0, import_common.decrypt)(body.wordpress.ftpPassword);
  }
  return { ...body, settings };
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  getServiceFromDB,
  servicesRouter
});
