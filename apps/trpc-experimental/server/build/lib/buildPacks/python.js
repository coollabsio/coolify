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
var python_exports = {};
__export(python_exports, {
  default: () => python_default
});
module.exports = __toCommonJS(python_exports);
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
    pythonWSGI,
    pythonModule,
    pythonVariable,
    buildId
  } = data;
  const Dockerfile = [];
  Dockerfile.push(`FROM ${image}`);
  Dockerfile.push("WORKDIR /app");
  Dockerfile.push(`LABEL coolify.buildId=${buildId}`);
  if (secrets.length > 0) {
    (0, import_common.generateSecrets)(secrets, pullmergeRequestId, true).forEach((env) => {
      Dockerfile.push(env);
    });
  }
  if (pythonWSGI?.toLowerCase() === "gunicorn") {
    Dockerfile.push(`RUN pip install gunicorn`);
  } else if (pythonWSGI?.toLowerCase() === "uvicorn") {
    Dockerfile.push(`RUN pip install uvicorn`);
  } else if (pythonWSGI?.toLowerCase() === "uwsgi") {
    Dockerfile.push(`RUN apk add --no-cache uwsgi-python3`);
  }
  try {
    await import_fs.promises.stat(`${workdir}${baseDirectory || ""}/requirements.txt`);
    Dockerfile.push(`COPY .${baseDirectory || ""}/requirements.txt ./`);
    Dockerfile.push(`RUN pip install --no-cache-dir -r .${baseDirectory || ""}/requirements.txt`);
  } catch (e) {
  }
  Dockerfile.push(`COPY .${baseDirectory || ""} ./`);
  Dockerfile.push(`EXPOSE ${port}`);
  if (pythonWSGI?.toLowerCase() === "gunicorn") {
    Dockerfile.push(`CMD gunicorn -w=4 -b=0.0.0.0:8000 ${pythonModule}:${pythonVariable}`);
  } else if (pythonWSGI?.toLowerCase() === "uvicorn") {
    Dockerfile.push(`CMD uvicorn ${pythonModule}:${pythonVariable} --port ${port} --host 0.0.0.0`);
  } else if (pythonWSGI?.toLowerCase() === "uwsgi") {
    Dockerfile.push(
      `CMD uwsgi --master -p 4 --http-socket 0.0.0.0:8000 --uid uwsgi --plugins python3 --protocol uwsgi --wsgi ${pythonModule}:${pythonVariable}`
    );
  } else {
    Dockerfile.push(`CMD python ${pythonModule}`);
  }
  await import_fs.promises.writeFile(`${workdir}/Dockerfile`, Dockerfile.join("\n"));
};
async function python_default(data) {
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
