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
var compose_exports = {};
__export(compose_exports, {
  default: () => compose_default
});
module.exports = __toCommonJS(compose_exports);
var import_fs = require("fs");
var import_common = require("../common");
var import_common2 = require("./common");
var import_js_yaml = __toESM(require("js-yaml"));
var import_docker = require("../docker");
var import_executeCommand = require("../executeCommand");
async function compose_default(data) {
  let {
    applicationId,
    debug,
    buildId,
    dockerId,
    network,
    volumes,
    labels,
    workdir,
    baseDirectory,
    secrets,
    pullmergeRequestId,
    dockerComposeConfiguration,
    dockerComposeFileLocation
  } = data;
  const fileYaml = `${workdir}${baseDirectory}${dockerComposeFileLocation}`;
  const dockerComposeRaw = await import_fs.promises.readFile(fileYaml, "utf8");
  const dockerComposeYaml = import_js_yaml.default.load(dockerComposeRaw);
  if (!dockerComposeYaml.services) {
    throw "No Services found in docker-compose file.";
  }
  let envs = [];
  if (secrets.length > 0) {
    envs = [...envs, ...(0, import_common.generateSecrets)(secrets, pullmergeRequestId, false, null)];
  }
  const composeVolumes = [];
  if (volumes.length > 0) {
    for (const volume of volumes) {
      let [v, path] = volume.split(":");
      composeVolumes[v] = {
        name: v
      };
    }
  }
  let networks = {};
  for (let [key, value] of Object.entries(dockerComposeYaml.services)) {
    value["container_name"] = `${applicationId}-${key}`;
    let environment = typeof value["environment"] === "undefined" ? [] : value["environment"];
    value["environment"] = [...environment, ...envs];
    value["labels"] = labels;
    if (value["volumes"]?.length > 0) {
      value["volumes"] = value["volumes"].map((volume) => {
        let [v, path, permission] = volume.split(":");
        if (!path) {
          path = v;
          v = `${applicationId}${v.replace(/\//gi, "-").replace(/\./gi, "")}`;
        } else {
          v = `${applicationId}${v.replace(/\//gi, "-").replace(/\./gi, "")}`;
        }
        composeVolumes[v] = {
          name: v
        };
        return `${v}:${path}${permission ? ":" + permission : ""}`;
      });
    }
    if (volumes.length > 0) {
      for (const volume of volumes) {
        value["volumes"].push(volume);
      }
    }
    if (dockerComposeConfiguration[key].port) {
      value["expose"] = [dockerComposeConfiguration[key].port];
    }
    if (value["networks"]?.length > 0) {
      value["networks"].forEach((network2) => {
        networks[network2] = {
          name: network2
        };
      });
    }
    value["networks"] = [...value["networks"] || "", network];
    dockerComposeYaml.services[key] = {
      ...dockerComposeYaml.services[key],
      restart: (0, import_docker.defaultComposeConfiguration)(network).restart,
      deploy: (0, import_docker.defaultComposeConfiguration)(network).deploy
    };
  }
  if (Object.keys(composeVolumes).length > 0) {
    dockerComposeYaml["volumes"] = { ...composeVolumes };
  }
  dockerComposeYaml["networks"] = Object.assign({ ...networks }, { [network]: { external: true } });
  await import_fs.promises.writeFile(fileYaml, import_js_yaml.default.dump(dockerComposeYaml));
  await (0, import_executeCommand.executeCommand)({
    debug,
    buildId,
    applicationId,
    dockerId,
    command: `docker compose --project-directory ${workdir} pull`
  });
  await (0, import_common2.saveBuildLog)({ line: "Pulling images from Compose file...", buildId, applicationId });
  await (0, import_executeCommand.executeCommand)({
    debug,
    buildId,
    applicationId,
    dockerId,
    command: `docker compose --project-directory ${workdir} build --progress plain`
  });
  await (0, import_common2.saveBuildLog)({ line: "Building images from Compose file...", buildId, applicationId });
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {});
