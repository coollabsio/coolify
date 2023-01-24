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
var nestjs_exports = {};
__export(nestjs_exports, {
  default: () => nestjs_default
});
module.exports = __toCommonJS(nestjs_exports);
var import_fs = require("fs");
var import_common = require("./common");
const createDockerfile = async (data, image) => {
  const { buildId, applicationId, tag, port, startCommand, workdir, baseDirectory } = data;
  const Dockerfile = [];
  const isPnpm = startCommand.includes("pnpm");
  Dockerfile.push(`FROM ${image}`);
  Dockerfile.push("WORKDIR /app");
  Dockerfile.push(`LABEL coolify.buildId=${buildId}`);
  if (isPnpm) {
    Dockerfile.push("RUN curl -f https://get.pnpm.io/v6.16.js | node - add --global pnpm@7");
  }
  Dockerfile.push(`COPY --from=${applicationId}:${tag}-cache /app/${baseDirectory || ""} ./`);
  Dockerfile.push(`EXPOSE ${port}`);
  Dockerfile.push(`CMD ${startCommand}`);
  await import_fs.promises.writeFile(`${workdir}/Dockerfile`, Dockerfile.join("\n"));
};
async function nestjs_default(data) {
  try {
    const { baseImage, baseBuildImage } = data;
    await (0, import_common.buildCacheImageWithNode)(data, baseBuildImage);
    await createDockerfile(data, baseImage);
    await (0, import_common.buildImage)(data);
  } catch (error) {
    throw error;
  }
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {});
