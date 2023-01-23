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
  default: () => docker_default
});
module.exports = __toCommonJS(docker_exports);
var import_fs = require("fs");
var import_common = require("../common");
var import_common2 = require("./common");
async function docker_default(data) {
  let { workdir, buildId, baseDirectory, secrets, pullmergeRequestId, dockerFileLocation } = data;
  const file = `${workdir}${baseDirectory}${dockerFileLocation}`;
  data.workdir = `${workdir}${baseDirectory}`;
  const DockerfileRaw = await import_fs.promises.readFile(`${file}`, "utf8");
  const Dockerfile = DockerfileRaw.toString().trim().split("\n");
  Dockerfile.forEach((line, index) => {
    if (line.startsWith("FROM")) {
      Dockerfile.splice(index + 1, 0, `LABEL coolify.buildId=${buildId}`);
    }
  });
  if (secrets.length > 0) {
    (0, import_common.generateSecrets)(secrets, pullmergeRequestId, true).forEach((env) => {
      Dockerfile.forEach((line, index) => {
        if (line.startsWith("FROM")) {
          Dockerfile.splice(index + 1, 0, env);
        }
      });
    });
  }
  await import_fs.promises.writeFile(`${data.workdir}${dockerFileLocation}`, Dockerfile.join("\n"));
  await (0, import_common2.buildImage)(data);
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {});
