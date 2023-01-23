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
var nuxtjs_exports = {};
__export(nuxtjs_exports, {
  default: () => nuxtjs_default
});
module.exports = __toCommonJS(nuxtjs_exports);
var import_fs = require("fs");
var import_common = require("../common");
var import_common2 = require("./common");
const createDockerfile = async (data, image) => {
  const {
    applicationId,
    buildId,
    tag,
    workdir,
    publishDirectory,
    port,
    installCommand,
    buildCommand,
    startCommand,
    baseDirectory,
    secrets,
    pullmergeRequestId,
    deploymentType,
    baseImage
  } = data;
  const Dockerfile = [];
  const isPnpm = (0, import_common2.checkPnpm)(installCommand, buildCommand, startCommand);
  Dockerfile.push(`FROM ${image}`);
  Dockerfile.push("WORKDIR /app");
  Dockerfile.push(`LABEL coolify.buildId=${buildId}`);
  if (secrets.length > 0) {
    (0, import_common.generateSecrets)(secrets, pullmergeRequestId, true).forEach((env) => {
      Dockerfile.push(env);
    });
  }
  if (isPnpm) {
    Dockerfile.push("RUN curl -f https://get.pnpm.io/v6.16.js | node - add --global pnpm@7");
  }
  if (deploymentType === "node") {
    Dockerfile.push(`COPY .${baseDirectory || ""} ./`);
    Dockerfile.push(`RUN ${installCommand}`);
    Dockerfile.push(`RUN ${buildCommand}`);
    Dockerfile.push(`EXPOSE ${port}`);
    Dockerfile.push(`CMD ${startCommand}`);
  } else if (deploymentType === "static") {
    if (baseImage?.includes("nginx")) {
      Dockerfile.push(`COPY /nginx.conf /etc/nginx/nginx.conf`);
    }
    Dockerfile.push(`COPY --from=${applicationId}:${tag}-cache /app/${publishDirectory} ./`);
    Dockerfile.push(`EXPOSE 80`);
  }
  await import_fs.promises.writeFile(`${workdir}/Dockerfile`, Dockerfile.join("\n"));
};
async function nuxtjs_default(data) {
  try {
    const { baseImage, baseBuildImage, deploymentType, buildCommand } = data;
    if (deploymentType === "node") {
      await createDockerfile(data, baseImage);
      await (0, import_common2.buildImage)(data);
    } else if (deploymentType === "static") {
      if (buildCommand)
        await (0, import_common2.buildCacheImageWithNode)(data, baseBuildImage);
      await createDockerfile(data, baseImage);
      await (0, import_common2.buildImage)(data);
    }
  } catch (error) {
    throw error;
  }
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {});
