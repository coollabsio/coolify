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
var docker_exports = {};
__export(docker_exports, {
  checkContainer: () => checkContainer,
  defaultComposeConfiguration: () => defaultComposeConfiguration,
  formatLabelsOnDocker: () => formatLabelsOnDocker,
  removeContainer: () => removeContainer,
  stopDatabaseContainer: () => stopDatabaseContainer,
  stopTcpHttpProxy: () => stopTcpHttpProxy
});
module.exports = __toCommonJS(docker_exports);
var import_executeCommand = require("./executeCommand");
async function checkContainer({
  dockerId,
  container,
  remove = false
}) {
  let containerFound = false;
  try {
    const { stdout } = await (0, import_executeCommand.executeCommand)({
      dockerId,
      command: `docker inspect --format '{{json .State}}' ${container}`
    });
    containerFound = true;
    const parsedStdout = JSON.parse(stdout);
    const status = parsedStdout.Status;
    const isRunning = status === "running";
    const isRestarting = status === "restarting";
    const isExited = status === "exited";
    if (status === "created") {
      await (0, import_executeCommand.executeCommand)({
        dockerId,
        command: `docker rm ${container}`
      });
    }
    if (remove && status === "exited") {
      await (0, import_executeCommand.executeCommand)({
        dockerId,
        command: `docker rm ${container}`
      });
    }
    return {
      found: containerFound,
      status: {
        isRunning,
        isRestarting,
        isExited
      }
    };
  } catch (err) {
  }
  return {
    found: false
  };
}
async function removeContainer({
  id,
  dockerId
}) {
  try {
    const { stdout } = await (0, import_executeCommand.executeCommand)({
      dockerId,
      command: `docker inspect --format '{{json .State}}' ${id}`
    });
    if (JSON.parse(stdout).Running) {
      await (0, import_executeCommand.executeCommand)({ dockerId, command: `docker stop -t 0 ${id}` });
      await (0, import_executeCommand.executeCommand)({ dockerId, command: `docker rm ${id}` });
    }
    if (JSON.parse(stdout).Status === "exited") {
      await (0, import_executeCommand.executeCommand)({ dockerId, command: `docker rm ${id}` });
    }
  } catch (error) {
    throw error;
  }
}
async function stopDatabaseContainer(database) {
  let everStarted = false;
  const {
    id,
    destinationDockerId,
    destinationDocker: { engine, id: dockerId }
  } = database;
  if (destinationDockerId) {
    try {
      const { stdout } = await (0, import_executeCommand.executeCommand)({
        dockerId,
        command: `docker inspect --format '{{json .State}}' ${id}`
      });
      if (stdout) {
        everStarted = true;
        await removeContainer({ id, dockerId });
      }
    } catch (error) {
    }
  }
  return everStarted;
}
async function stopTcpHttpProxy(id, destinationDocker, publicPort, forceName = null) {
  const { id: dockerId } = destinationDocker;
  let container = `${id}-${publicPort}`;
  if (forceName)
    container = forceName;
  const { found } = await checkContainer({ dockerId, container });
  try {
    if (!found)
      return true;
    return await (0, import_executeCommand.executeCommand)({
      dockerId,
      command: `docker stop -t 0 ${container} && docker rm ${container}`,
      shell: true
    });
  } catch (error) {
    return error;
  }
}
function formatLabelsOnDocker(data) {
  return data.trim().split("\n").map((a) => JSON.parse(a)).map((container) => {
    const labels = container.Labels.split(",");
    let jsonLabels = {};
    labels.forEach((l) => {
      const name = l.split("=")[0];
      const value = l.split("=")[1];
      jsonLabels = { ...jsonLabels, ...{ [name]: value } };
    });
    container.Labels = jsonLabels;
    return container;
  });
}
function defaultComposeConfiguration(network) {
  return {
    networks: [network],
    restart: "on-failure",
    deploy: {
      restart_policy: {
        condition: "on-failure",
        delay: "5s",
        max_attempts: 10,
        window: "120s"
      }
    }
  };
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  checkContainer,
  defaultComposeConfiguration,
  formatLabelsOnDocker,
  removeContainer,
  stopDatabaseContainer,
  stopTcpHttpProxy
});
