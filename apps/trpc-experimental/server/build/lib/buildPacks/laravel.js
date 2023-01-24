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
var laravel_exports = {};
__export(laravel_exports, {
  default: () => laravel_default
});
module.exports = __toCommonJS(laravel_exports);
var import_fs = require("fs");
var import_common = require("../common");
var import_common2 = require("./common");
const createDockerfile = async (data, image) => {
  const { workdir, applicationId, tag, buildId, port, secrets, pullmergeRequestId } = data;
  const Dockerfile = [];
  Dockerfile.push(`FROM ${image}`);
  Dockerfile.push(`LABEL coolify.buildId=${buildId}`);
  if (secrets.length > 0) {
    (0, import_common.generateSecrets)(secrets, pullmergeRequestId, true).forEach((env) => {
      Dockerfile.push(env);
    });
  }
  Dockerfile.push("WORKDIR /app");
  Dockerfile.push(`ENV WEB_DOCUMENT_ROOT /app/public`);
  Dockerfile.push(`COPY --chown=application:application composer.* ./`);
  Dockerfile.push(`COPY --chown=application:application database/ database/`);
  Dockerfile.push(
    `RUN composer install --ignore-platform-reqs --no-interaction --no-plugins --no-scripts --prefer-dist`
  );
  Dockerfile.push(
    `COPY --chown=application:application --from=${applicationId}:${tag}-cache /app/public/js/ /app/public/js/`
  );
  Dockerfile.push(
    `COPY --chown=application:application --from=${applicationId}:${tag}-cache /app/public/css/ /app/public/css/`
  );
  Dockerfile.push(
    `COPY --chown=application:application --from=${applicationId}:${tag}-cache /app/mix-manifest.json /app/public/mix-manifest.json`
  );
  Dockerfile.push(`COPY --chown=application:application . ./`);
  Dockerfile.push(`EXPOSE ${port}`);
  await import_fs.promises.writeFile(`${workdir}/Dockerfile`, Dockerfile.join("\n"));
};
async function laravel_default(data) {
  const { baseImage, baseBuildImage } = data;
  try {
    await (0, import_common2.buildCacheImageForLaravel)(data, baseBuildImage);
    await createDockerfile(data, baseImage);
    await (0, import_common2.buildImage)(data);
  } catch (error) {
    throw error;
  }
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {});
