"use strict";
var __create = Object.create;
var __defProp = Object.defineProperty;
var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
var __getOwnPropNames = Object.getOwnPropertyNames;
var __getProtoOf = Object.getPrototypeOf;
var __hasOwnProp = Object.prototype.hasOwnProperty;
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
var import_node_worker_threads = require("node:worker_threads");
var import_crypto = __toESM(require("crypto"));
var import_promises = __toESM(require("fs/promises"));
var import_js_yaml = __toESM(require("js-yaml"));
var import_common = require("../lib/buildPacks/common");
var import_common2 = require("../lib/common");
var importers = __toESM(require("../lib/importers"));
var buildpacks = __toESM(require("../lib/buildPacks"));
var import_prisma = require("../prisma");
var import_executeCommand = require("../lib/executeCommand");
var import_docker = require("../lib/docker");
(async () => {
  if (import_node_worker_threads.parentPort) {
    import_node_worker_threads.parentPort.on("message", async (message) => {
      if (message === "error")
        throw new Error("oops");
      if (message === "cancel") {
        import_node_worker_threads.parentPort.postMessage("cancelled");
        await import_prisma.prisma.$disconnect();
        process.exit(0);
      }
    });
    const pThrottle = await import("p-throttle");
    const throttle = pThrottle.default({
      limit: 1,
      interval: 2e3
    });
    const th = throttle(async () => {
      try {
        const queuedBuilds = await import_prisma.prisma.build.findMany({
          where: { status: { in: ["queued", "running"] } },
          orderBy: { createdAt: "asc" }
        });
        const { concurrentBuilds } = await import_prisma.prisma.setting.findFirst({});
        if (queuedBuilds.length > 0) {
          import_node_worker_threads.parentPort.postMessage({ deploying: true });
          const concurrency = concurrentBuilds;
          const pAll = await import("p-all");
          const actions = [];
          for (const queueBuild of queuedBuilds) {
            actions.push(async () => {
              let application = await import_prisma.prisma.application.findUnique({
                where: { id: queueBuild.applicationId },
                include: {
                  dockerRegistry: true,
                  destinationDocker: true,
                  gitSource: { include: { githubApp: true, gitlabApp: true } },
                  persistentStorage: true,
                  secrets: true,
                  settings: true,
                  teams: true
                }
              });
              let {
                id: buildId,
                type,
                gitSourceId,
                sourceBranch = null,
                pullmergeRequestId = null,
                previewApplicationId = null,
                forceRebuild,
                sourceRepository = null
              } = queueBuild;
              application = (0, import_common2.decryptApplication)(application);
              if (!gitSourceId && application.simpleDockerfile) {
                const {
                  id: applicationId2,
                  destinationDocker: destinationDocker2,
                  destinationDockerId: destinationDockerId2,
                  secrets: secrets2,
                  port: port2,
                  persistentStorage: persistentStorage2,
                  exposePort: exposePort2,
                  simpleDockerfile,
                  dockerRegistry: dockerRegistry2
                } = application;
                const { workdir: workdir2 } = await (0, import_common2.createDirectories)({ repository: applicationId2, buildId });
                try {
                  if (queueBuild.status === "running") {
                    await (0, import_common.saveBuildLog)({
                      line: "Building halted, restarting...",
                      buildId,
                      applicationId: application.id
                    });
                  }
                  const volumes = persistentStorage2?.map((storage) => {
                    if (storage.oldPath) {
                      return `${applicationId2}${storage.path.replace(/\//gi, "-").replace("-app", "")}:${storage.path}`;
                    }
                    return `${applicationId2}${storage.path.replace(/\//gi, "-")}:${storage.path}`;
                  }) || [];
                  if (destinationDockerId2) {
                    await import_prisma.prisma.build.update({
                      where: { id: buildId },
                      data: { status: "running" }
                    });
                    try {
                      const { stdout: containers } = await (0, import_executeCommand.executeCommand)({
                        dockerId: destinationDockerId2,
                        command: `docker ps -a --filter 'label=com.docker.compose.service=${applicationId2}' --format {{.ID}}`
                      });
                      if (containers) {
                        const containerArray = containers.split("\n");
                        if (containerArray.length > 0) {
                          for (const container of containerArray) {
                            await (0, import_executeCommand.executeCommand)({
                              dockerId: destinationDockerId2,
                              command: `docker stop -t 0 ${container}`
                            });
                            await (0, import_executeCommand.executeCommand)({
                              dockerId: destinationDockerId2,
                              command: `docker rm --force ${container}`
                            });
                          }
                        }
                      }
                    } catch (error) {
                    }
                    let envs = [];
                    if (secrets2.length > 0) {
                      envs = [
                        ...envs,
                        ...(0, import_common2.generateSecrets)(secrets2, pullmergeRequestId, false, port2)
                      ];
                    }
                    await import_promises.default.writeFile(`${workdir2}/Dockerfile`, simpleDockerfile);
                    if (dockerRegistry2) {
                      const { url, username, password } = dockerRegistry2;
                      await (0, import_common.saveDockerRegistryCredentials)({ url, username, password, workdir: workdir2 });
                    }
                    const labels = (0, import_common.makeLabelForSimpleDockerfile)({
                      applicationId: applicationId2,
                      type,
                      port: exposePort2 ? `${exposePort2}:${port2}` : port2
                    });
                    try {
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
                          [applicationId2]: {
                            build: {
                              context: workdir2
                            },
                            image: `${applicationId2}:${buildId}`,
                            container_name: applicationId2,
                            volumes,
                            labels,
                            environment: envs,
                            depends_on: [],
                            expose: [port2],
                            ...exposePort2 ? { ports: [`${exposePort2}:${port2}`] } : {},
                            ...(0, import_docker.defaultComposeConfiguration)(destinationDocker2.network)
                          }
                        },
                        networks: {
                          [destinationDocker2.network]: {
                            external: true
                          }
                        },
                        volumes: Object.assign({}, ...composeVolumes)
                      };
                      await import_promises.default.writeFile(`${workdir2}/docker-compose.yml`, import_js_yaml.default.dump(composeFile));
                      await (0, import_executeCommand.executeCommand)({
                        debug: true,
                        dockerId: destinationDocker2.id,
                        command: `docker compose --project-directory ${workdir2} up -d`
                      });
                      await (0, import_common.saveBuildLog)({ line: "Deployed \u{1F389}", buildId, applicationId: applicationId2 });
                    } catch (error) {
                      await (0, import_common.saveBuildLog)({ line: error, buildId, applicationId: applicationId2 });
                      const foundBuild = await import_prisma.prisma.build.findUnique({ where: { id: buildId } });
                      if (foundBuild) {
                        await import_prisma.prisma.build.update({
                          where: { id: buildId },
                          data: {
                            status: "failed"
                          }
                        });
                      }
                      throw new Error(error);
                    }
                  }
                } catch (error) {
                  const foundBuild = await import_prisma.prisma.build.findUnique({ where: { id: buildId } });
                  if (foundBuild) {
                    await import_prisma.prisma.build.update({
                      where: { id: buildId },
                      data: {
                        status: "failed"
                      }
                    });
                  }
                  if (error !== 1) {
                    await (0, import_common.saveBuildLog)({ line: error, buildId, applicationId: application.id });
                  }
                  if (error instanceof Error) {
                    await (0, import_common.saveBuildLog)({
                      line: error.message,
                      buildId,
                      applicationId: application.id
                    });
                  }
                  await import_promises.default.rm(workdir2, { recursive: true, force: true });
                  return;
                }
                try {
                  if (application.dockerRegistryImageName) {
                    const customTag2 = application.dockerRegistryImageName.split(":")[1] || buildId;
                    const imageName2 = application.dockerRegistryImageName.split(":")[0];
                    await (0, import_common.saveBuildLog)({
                      line: `Pushing ${imageName2}:${customTag2} to Docker Registry... It could take a while...`,
                      buildId,
                      applicationId: application.id
                    });
                    await (0, import_common2.pushToRegistry)(application, workdir2, buildId, imageName2, customTag2);
                    await (0, import_common.saveBuildLog)({ line: "Success", buildId, applicationId: application.id });
                  }
                } catch (error) {
                  if (error.stdout) {
                    await (0, import_common.saveBuildLog)({ line: error.stdout, buildId, applicationId: applicationId2 });
                  }
                  if (error.stderr) {
                    await (0, import_common.saveBuildLog)({ line: error.stderr, buildId, applicationId: applicationId2 });
                  }
                } finally {
                  await import_promises.default.rm(workdir2, { recursive: true, force: true });
                  await import_prisma.prisma.build.update({
                    where: { id: buildId },
                    data: { status: "success" }
                  });
                }
                return;
              }
              const originalApplicationId = application.id;
              const {
                id: applicationId,
                name,
                destinationDocker,
                destinationDockerId,
                gitSource,
                configHash,
                fqdn,
                projectId,
                secrets,
                phpModules,
                settings,
                persistentStorage,
                pythonWSGI,
                pythonModule,
                pythonVariable,
                denoOptions,
                exposePort,
                baseImage,
                baseBuildImage,
                deploymentType,
                gitCommitHash,
                dockerRegistry
              } = application;
              let {
                branch,
                repository,
                buildPack,
                port,
                installCommand,
                buildCommand,
                startCommand,
                baseDirectory,
                publishDirectory,
                dockerFileLocation,
                dockerComposeFileLocation,
                dockerComposeConfiguration,
                denoMainFile
              } = application;
              let imageId = applicationId;
              let domain = (0, import_common2.getDomain)(fqdn);
              let location = null;
              let tag = null;
              let customTag = null;
              let imageName = null;
              let imageFoundLocally = false;
              let imageFoundRemotely = false;
              if (pullmergeRequestId) {
                const previewApplications = await import_prisma.prisma.previewApplication.findMany({
                  where: { applicationId: originalApplicationId, pullmergeRequestId }
                });
                if (previewApplications.length > 0) {
                  previewApplicationId = previewApplications[0].id;
                }
                branch = sourceBranch;
                domain = `${pullmergeRequestId}.${domain}`;
                imageId = `${applicationId}-${pullmergeRequestId}`;
                repository = sourceRepository || repository;
              }
              const { workdir, repodir } = await (0, import_common2.createDirectories)({ repository, buildId });
              try {
                if (queueBuild.status === "running") {
                  await (0, import_common.saveBuildLog)({
                    line: "Building halted, restarting...",
                    buildId,
                    applicationId: application.id
                  });
                }
                const currentHash = import_crypto.default.createHash("sha256").update(
                  JSON.stringify({
                    pythonWSGI,
                    pythonModule,
                    pythonVariable,
                    deploymentType,
                    denoOptions,
                    baseImage,
                    baseBuildImage,
                    buildPack,
                    port,
                    exposePort,
                    installCommand,
                    buildCommand,
                    startCommand,
                    secrets,
                    branch,
                    repository,
                    fqdn
                  })
                ).digest("hex");
                const { debug } = settings;
                if (!debug) {
                  await (0, import_common.saveBuildLog)({
                    line: `Debug logging is disabled. Enable it above if necessary!`,
                    buildId,
                    applicationId
                  });
                }
                const volumes = persistentStorage?.map((storage) => {
                  if (storage.oldPath) {
                    return `${applicationId}${storage.path.replace(/\//gi, "-").replace("-app", "")}:${storage.path}`;
                  }
                  return `${applicationId}${storage.path.replace(/\//gi, "-")}:${storage.path}`;
                }) || [];
                try {
                  dockerComposeConfiguration = JSON.parse(dockerComposeConfiguration);
                } catch (error) {
                }
                let deployNeeded = true;
                let destinationType;
                if (destinationDockerId) {
                  destinationType = "docker";
                }
                if (destinationType === "docker") {
                  await import_prisma.prisma.build.update({
                    where: { id: buildId },
                    data: { status: "running" }
                  });
                  const configuration = await (0, import_common.setDefaultConfiguration)(application);
                  buildPack = configuration.buildPack;
                  port = configuration.port;
                  installCommand = configuration.installCommand;
                  startCommand = configuration.startCommand;
                  buildCommand = configuration.buildCommand;
                  publishDirectory = configuration.publishDirectory;
                  baseDirectory = configuration.baseDirectory || "";
                  dockerFileLocation = configuration.dockerFileLocation;
                  dockerComposeFileLocation = configuration.dockerComposeFileLocation;
                  denoMainFile = configuration.denoMainFile;
                  const commit = await importers[gitSource.type]({
                    applicationId,
                    debug,
                    workdir,
                    repodir,
                    githubAppId: gitSource.githubApp?.id,
                    gitlabAppId: gitSource.gitlabApp?.id,
                    customPort: gitSource.customPort,
                    gitCommitHash,
                    configuration,
                    repository,
                    branch,
                    buildId,
                    apiUrl: gitSource.apiUrl,
                    htmlUrl: gitSource.htmlUrl,
                    projectId,
                    deployKeyId: gitSource.gitlabApp?.deployKeyId || null,
                    privateSshKey: (0, import_common2.decrypt)(gitSource.gitlabApp?.privateSshKey) || null,
                    forPublic: gitSource.forPublic
                  });
                  if (!commit) {
                    throw new Error("No commit found?");
                  }
                  tag = commit.slice(0, 7);
                  if (pullmergeRequestId) {
                    tag = `${commit.slice(0, 7)}-${pullmergeRequestId}`;
                  }
                  if (application.dockerRegistryImageName) {
                    imageName = application.dockerRegistryImageName.split(":")[0];
                    customTag = application.dockerRegistryImageName.split(":")[1] || tag;
                  } else {
                    customTag = tag;
                    imageName = applicationId;
                  }
                  if (pullmergeRequestId) {
                    customTag = `${customTag}-${pullmergeRequestId}`;
                  }
                  try {
                    await import_prisma.prisma.build.update({ where: { id: buildId }, data: { commit } });
                  } catch (err) {
                  }
                  if (!pullmergeRequestId) {
                    if (configHash !== currentHash) {
                      deployNeeded = true;
                      if (configHash) {
                        await (0, import_common.saveBuildLog)({
                          line: "Configuration changed",
                          buildId,
                          applicationId
                        });
                      }
                    } else {
                      deployNeeded = false;
                    }
                  } else {
                    deployNeeded = true;
                  }
                  try {
                    await (0, import_executeCommand.executeCommand)({
                      dockerId: destinationDocker.id,
                      command: `docker image inspect ${applicationId}:${tag}`
                    });
                    imageFoundLocally = true;
                  } catch (error) {
                  }
                  if (dockerRegistry) {
                    const { url, username, password } = dockerRegistry;
                    location = await (0, import_common.saveDockerRegistryCredentials)({
                      url,
                      username,
                      password,
                      workdir
                    });
                  }
                  try {
                    await (0, import_executeCommand.executeCommand)({
                      dockerId: destinationDocker.id,
                      command: `docker ${location ? `--config ${location}` : ""} pull ${imageName}:${customTag}`
                    });
                    imageFoundRemotely = true;
                  } catch (error) {
                  }
                  let imageFound = `${applicationId}:${tag}`;
                  if (imageFoundRemotely) {
                    imageFound = `${imageName}:${customTag}`;
                  }
                  await (0, import_common.copyBaseConfigurationFiles)(
                    buildPack,
                    workdir,
                    buildId,
                    applicationId,
                    baseImage
                  );
                  const labels = (0, import_common.makeLabelForStandaloneApplication)({
                    applicationId,
                    fqdn,
                    name,
                    type,
                    pullmergeRequestId,
                    buildPack,
                    repository,
                    branch,
                    projectId,
                    port: exposePort ? `${exposePort}:${port}` : port,
                    commit,
                    installCommand,
                    buildCommand,
                    startCommand,
                    baseDirectory,
                    publishDirectory
                  });
                  if (forceRebuild)
                    deployNeeded = true;
                  if (!imageFoundLocally && !imageFoundRemotely || deployNeeded) {
                    if (buildPack === "static") {
                      await buildpacks.staticApp({
                        dockerId: destinationDocker.id,
                        network: destinationDocker.network,
                        buildId,
                        applicationId,
                        domain,
                        name,
                        type,
                        volumes,
                        labels,
                        pullmergeRequestId,
                        buildPack,
                        repository,
                        branch,
                        projectId,
                        publishDirectory,
                        debug,
                        commit,
                        tag,
                        workdir,
                        port: exposePort ? `${exposePort}:${port}` : port,
                        installCommand,
                        buildCommand,
                        startCommand,
                        baseDirectory,
                        secrets,
                        phpModules,
                        pythonWSGI,
                        pythonModule,
                        pythonVariable,
                        dockerFileLocation,
                        dockerComposeConfiguration,
                        dockerComposeFileLocation,
                        denoMainFile,
                        denoOptions,
                        baseImage,
                        baseBuildImage,
                        deploymentType,
                        forceRebuild
                      });
                    } else if (buildpacks[buildPack])
                      await buildpacks[buildPack]({
                        dockerId: destinationDocker.id,
                        network: destinationDocker.network,
                        buildId,
                        applicationId,
                        domain,
                        name,
                        type,
                        volumes,
                        labels,
                        pullmergeRequestId,
                        buildPack,
                        repository,
                        branch,
                        projectId,
                        publishDirectory,
                        debug,
                        commit,
                        tag,
                        workdir,
                        port: exposePort ? `${exposePort}:${port}` : port,
                        installCommand,
                        buildCommand,
                        startCommand,
                        baseDirectory,
                        secrets,
                        phpModules,
                        pythonWSGI,
                        pythonModule,
                        pythonVariable,
                        dockerFileLocation,
                        dockerComposeConfiguration,
                        dockerComposeFileLocation,
                        denoMainFile,
                        denoOptions,
                        baseImage,
                        baseBuildImage,
                        deploymentType,
                        forceRebuild
                      });
                    else {
                      await (0, import_common.saveBuildLog)({
                        line: `Build pack ${buildPack} not found`,
                        buildId,
                        applicationId
                      });
                      throw new Error(`Build pack ${buildPack} not found.`);
                    }
                  } else {
                    if (imageFoundRemotely || deployNeeded) {
                      await (0, import_common.saveBuildLog)({
                        line: `Container image ${imageFound} found in Docker Registry - reuising it`,
                        buildId,
                        applicationId
                      });
                    } else {
                      if (imageFoundLocally || deployNeeded) {
                        await (0, import_common.saveBuildLog)({
                          line: `Container image ${imageFound} found locally - reuising it`,
                          buildId,
                          applicationId
                        });
                      }
                    }
                  }
                  if (buildPack === "compose") {
                    try {
                      const { stdout: containers } = await (0, import_executeCommand.executeCommand)({
                        dockerId: destinationDockerId,
                        command: `docker ps -a --filter 'label=coolify.applicationId=${applicationId}' --format {{.ID}}`
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
                      await (0, import_executeCommand.executeCommand)({
                        debug,
                        buildId,
                        applicationId,
                        dockerId: destinationDocker.id,
                        command: `docker compose --project-directory ${workdir} up -d`
                      });
                      await (0, import_common.saveBuildLog)({ line: "Deployed \u{1F389}", buildId, applicationId });
                      await import_prisma.prisma.build.update({
                        where: { id: buildId },
                        data: { status: "success" }
                      });
                      await import_prisma.prisma.application.update({
                        where: { id: applicationId },
                        data: { configHash: currentHash }
                      });
                    } catch (error) {
                      await (0, import_common.saveBuildLog)({ line: error, buildId, applicationId });
                      const foundBuild = await import_prisma.prisma.build.findUnique({ where: { id: buildId } });
                      if (foundBuild) {
                        await import_prisma.prisma.build.update({
                          where: { id: buildId },
                          data: {
                            status: "failed"
                          }
                        });
                      }
                      throw new Error(error);
                    }
                  } else {
                    try {
                      const { stdout: containers } = await (0, import_executeCommand.executeCommand)({
                        dockerId: destinationDockerId,
                        command: `docker ps -a --filter 'label=com.docker.compose.service=${pullmergeRequestId ? imageId : applicationId}' --format {{.ID}}`
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
                    let envs = [];
                    if (secrets.length > 0) {
                      envs = [
                        ...envs,
                        ...(0, import_common2.generateSecrets)(secrets, pullmergeRequestId, false, port)
                      ];
                    }
                    if (dockerRegistry) {
                      const { url, username, password } = dockerRegistry;
                      await (0, import_common.saveDockerRegistryCredentials)({ url, username, password, workdir });
                    }
                    try {
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
                          [imageId]: {
                            image: imageFound,
                            container_name: imageId,
                            volumes,
                            environment: envs,
                            labels,
                            depends_on: [],
                            expose: [port],
                            ...exposePort ? { ports: [`${exposePort}:${port}`] } : {},
                            ...(0, import_docker.defaultComposeConfiguration)(destinationDocker.network)
                          }
                        },
                        networks: {
                          [destinationDocker.network]: {
                            external: true
                          }
                        },
                        volumes: Object.assign({}, ...composeVolumes)
                      };
                      await import_promises.default.writeFile(`${workdir}/docker-compose.yml`, import_js_yaml.default.dump(composeFile));
                      await (0, import_executeCommand.executeCommand)({
                        debug,
                        dockerId: destinationDocker.id,
                        command: `docker compose --project-directory ${workdir} up -d`
                      });
                      await (0, import_common.saveBuildLog)({ line: "Deployed \u{1F389}", buildId, applicationId });
                    } catch (error) {
                      await (0, import_common.saveBuildLog)({ line: error, buildId, applicationId });
                      const foundBuild = await import_prisma.prisma.build.findUnique({ where: { id: buildId } });
                      if (foundBuild) {
                        await import_prisma.prisma.build.update({
                          where: { id: buildId },
                          data: {
                            status: "failed"
                          }
                        });
                      }
                      throw new Error(error);
                    }
                    if (!pullmergeRequestId)
                      await import_prisma.prisma.application.update({
                        where: { id: applicationId },
                        data: { configHash: currentHash }
                      });
                  }
                }
              } catch (error) {
                const foundBuild = await import_prisma.prisma.build.findUnique({ where: { id: buildId } });
                if (foundBuild) {
                  await import_prisma.prisma.build.update({
                    where: { id: buildId },
                    data: {
                      status: "failed"
                    }
                  });
                }
                if (error !== 1) {
                  await (0, import_common.saveBuildLog)({ line: error, buildId, applicationId: application.id });
                }
                if (error instanceof Error) {
                  await (0, import_common.saveBuildLog)({
                    line: error.message,
                    buildId,
                    applicationId: application.id
                  });
                }
                await import_promises.default.rm(workdir, { recursive: true, force: true });
                return;
              }
              try {
                if (application.dockerRegistryImageName && (!imageFoundRemotely || forceRebuild)) {
                  await (0, import_common.saveBuildLog)({
                    line: `Pushing ${imageName}:${customTag} to Docker Registry... It could take a while...`,
                    buildId,
                    applicationId: application.id
                  });
                  await (0, import_common2.pushToRegistry)(application, workdir, tag, imageName, customTag);
                  await (0, import_common.saveBuildLog)({ line: "Success", buildId, applicationId: application.id });
                }
              } catch (error) {
                if (error.stdout) {
                  await (0, import_common.saveBuildLog)({ line: error.stdout, buildId, applicationId });
                }
                if (error.stderr) {
                  await (0, import_common.saveBuildLog)({ line: error.stderr, buildId, applicationId });
                }
              } finally {
                await import_promises.default.rm(workdir, { recursive: true, force: true });
                await import_prisma.prisma.build.update({ where: { id: buildId }, data: { status: "success" } });
              }
            });
          }
          await pAll.default(actions, { concurrency });
        }
      } catch (error) {
        console.log(error);
      }
    });
    while (true) {
      await th();
    }
  } else {
    console.log("hello");
    process.exit(0);
  }
})();
