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
var php_exports = {};
__export(php_exports, {
  default: () => php_default
});
module.exports = __toCommonJS(php_exports);
var import_fs = require("fs");
var import_common = require("../common");
var import_common2 = require("./common");
const createDockerfile = async (data, image, htaccessFound) => {
  const { workdir, baseDirectory, buildId, port, secrets, pullmergeRequestId } = data;
  const Dockerfile = [];
  let composerFound = false;
  try {
    await import_fs.promises.readFile(`${workdir}${baseDirectory || ""}/composer.json`);
    composerFound = true;
  } catch (error) {
  }
  Dockerfile.push(`FROM ${image}`);
  Dockerfile.push(`LABEL coolify.buildId=${buildId}`);
  if (secrets.length > 0) {
    (0, import_common.generateSecrets)(secrets, pullmergeRequestId, true).forEach((env) => {
      Dockerfile.push(env);
    });
  }
  Dockerfile.push("WORKDIR /app");
  Dockerfile.push(`COPY .${baseDirectory || ""} /app`);
  if (htaccessFound) {
    Dockerfile.push(`COPY .${baseDirectory || ""}/.htaccess ./`);
  }
  if (composerFound) {
    Dockerfile.push(`RUN composer install`);
  }
  Dockerfile.push(`COPY /entrypoint.sh /opt/docker/provision/entrypoint.d/30-entrypoint.sh`);
  Dockerfile.push(`EXPOSE ${port}`);
  await import_fs.promises.writeFile(`${workdir}/Dockerfile`, Dockerfile.join("\n"));
};
async function php_default(data) {
  const { workdir, baseDirectory, baseImage } = data;
  try {
    let htaccessFound = false;
    try {
      await import_fs.promises.readFile(`${workdir}${baseDirectory || ""}/.htaccess`);
      htaccessFound = true;
    } catch (e) {
    }
    await createDockerfile(data, baseImage, htaccessFound);
    await (0, import_common2.buildImage)(data);
  } catch (error) {
    throw error;
  }
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {});
