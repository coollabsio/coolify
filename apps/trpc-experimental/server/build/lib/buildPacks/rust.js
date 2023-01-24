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
var rust_exports = {};
__export(rust_exports, {
  default: () => rust_default
});
module.exports = __toCommonJS(rust_exports);
var import_fs = require("fs");
var import_toml = __toESM(require("@iarna/toml"));
var import_common = require("./common");
var import_executeCommand = require("../executeCommand");
const createDockerfile = async (data, image, name) => {
  const { workdir, port, applicationId, tag, buildId } = data;
  const Dockerfile = [];
  Dockerfile.push(`FROM ${image}`);
  Dockerfile.push("WORKDIR /app");
  Dockerfile.push(`LABEL coolify.buildId=${buildId}`);
  Dockerfile.push(`COPY --from=${applicationId}:${tag}-cache /app/target target`);
  Dockerfile.push(`COPY --from=${applicationId}:${tag}-cache /usr/local/cargo /usr/local/cargo`);
  Dockerfile.push(`COPY . .`);
  Dockerfile.push(`RUN cargo build --release --bin ${name}`);
  Dockerfile.push("FROM debian:buster-slim");
  Dockerfile.push("WORKDIR /app");
  Dockerfile.push(
    `RUN apt-get update -y && apt-get install -y --no-install-recommends openssl libcurl4 ca-certificates && apt-get autoremove -y && apt-get clean -y && rm -rf /var/lib/apt/lists/*`
  );
  Dockerfile.push(`RUN update-ca-certificates`);
  Dockerfile.push(`COPY --from=${applicationId}:${tag}-cache /app/target/release/${name} ${name}`);
  Dockerfile.push(`EXPOSE ${port}`);
  Dockerfile.push(`CMD ["/app/${name}"]`);
  await import_fs.promises.writeFile(`${workdir}/Dockerfile`, Dockerfile.join("\n"));
};
async function rust_default(data) {
  try {
    const { workdir, baseImage, baseBuildImage } = data;
    const { stdout: cargoToml } = await (0, import_executeCommand.executeCommand)({ command: `cat ${workdir}/Cargo.toml` });
    const parsedToml = import_toml.default.parse(cargoToml);
    const name = parsedToml.package.name;
    await (0, import_common.buildCacheImageWithCargo)(data, baseBuildImage);
    await createDockerfile(data, baseImage, name);
    await (0, import_common.buildImage)(data);
  } catch (error) {
    throw error;
  }
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {});
