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
var deno_exports = {};
__export(deno_exports, {
  default: () => deno_default
});
module.exports = __toCommonJS(deno_exports);
var import_fs = require("fs");
var import_common = require("../common");
var import_common2 = require("./common");
const createDockerfile = async (data, image) => {
  const {
    workdir,
    port,
    baseDirectory,
    secrets,
    pullmergeRequestId,
    denoMainFile,
    denoOptions,
    buildId
  } = data;
  const Dockerfile = [];
  let depsFound = false;
  try {
    await import_fs.promises.readFile(`${workdir}${baseDirectory || ""}/deps.ts`);
    depsFound = true;
  } catch (error) {
  }
  Dockerfile.push(`FROM ${image}`);
  Dockerfile.push("WORKDIR /app");
  Dockerfile.push(`LABEL coolify.buildId=${buildId}`);
  if (secrets.length > 0) {
    (0, import_common.generateSecrets)(secrets, pullmergeRequestId, true).forEach((env) => {
      Dockerfile.push(env);
    });
  }
  if (depsFound) {
    Dockerfile.push(`COPY .${baseDirectory || ""}/deps.ts /app`);
    Dockerfile.push(`RUN deno cache deps.ts`);
  }
  Dockerfile.push(`COPY .${baseDirectory || ""} ./`);
  Dockerfile.push(`RUN deno cache ${denoMainFile}`);
  Dockerfile.push(`ENV NO_COLOR true`);
  Dockerfile.push(`EXPOSE ${port}`);
  Dockerfile.push(`CMD deno run ${denoOptions || ""} ${denoMainFile}`);
  await import_fs.promises.writeFile(`${workdir}/Dockerfile`, Dockerfile.join("\n"));
};
async function deno_default(data) {
  try {
    const { baseImage, baseBuildImage } = data;
    await createDockerfile(data, baseImage);
    await (0, import_common2.buildImage)(data);
  } catch (error) {
    throw error;
  }
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {});
