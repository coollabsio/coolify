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
var common_exports = {};
__export(common_exports, {
  buildCacheImageForLaravel: () => buildCacheImageForLaravel,
  buildCacheImageWithCargo: () => buildCacheImageWithCargo,
  buildCacheImageWithNode: () => buildCacheImageWithNode,
  buildImage: () => buildImage,
  checkPnpm: () => checkPnpm,
  copyBaseConfigurationFiles: () => copyBaseConfigurationFiles,
  makeLabelForSimpleDockerfile: () => makeLabelForSimpleDockerfile,
  makeLabelForStandaloneApplication: () => makeLabelForStandaloneApplication,
  saveBuildLog: () => saveBuildLog,
  saveDockerRegistryCredentials: () => saveDockerRegistryCredentials,
  scanningTemplates: () => scanningTemplates,
  setDefaultBaseImage: () => setDefaultBaseImage,
  setDefaultConfiguration: () => setDefaultConfiguration
});
module.exports = __toCommonJS(common_exports);
var import_common = require("../common");
var import_fs = require("fs");
var import_dayjs = require("../dayjs");
var import_prisma = require("../../prisma");
var import_executeCommand = require("../executeCommand");
const staticApps = ["static", "react", "vuejs", "svelte", "gatsby", "astro", "eleventy"];
const nodeBased = [
  "react",
  "preact",
  "vuejs",
  "svelte",
  "gatsby",
  "astro",
  "eleventy",
  "node",
  "nestjs",
  "nuxtjs",
  "nextjs"
];
function setDefaultBaseImage(buildPack, deploymentType = null) {
  const nodeVersions = [
    {
      value: "node:lts",
      label: "node:lts"
    },
    {
      value: "node:18",
      label: "node:18"
    },
    {
      value: "node:17",
      label: "node:17"
    },
    {
      value: "node:16",
      label: "node:16"
    },
    {
      value: "node:14",
      label: "node:14"
    },
    {
      value: "node:12",
      label: "node:12"
    }
  ];
  const staticVersions = [
    {
      value: "webdevops/nginx:alpine",
      label: "webdevops/nginx:alpine"
    },
    {
      value: "webdevops/apache:alpine",
      label: "webdevops/apache:alpine"
    },
    {
      value: "nginx:alpine",
      label: "nginx:alpine"
    },
    {
      value: "httpd:alpine",
      label: "httpd:alpine (Apache)"
    }
  ];
  const rustVersions = [
    {
      value: "rust:latest",
      label: "rust:latest"
    },
    {
      value: "rust:1.60",
      label: "rust:1.60"
    },
    {
      value: "rust:1.60-buster",
      label: "rust:1.60-buster"
    },
    {
      value: "rust:1.60-bullseye",
      label: "rust:1.60-bullseye"
    },
    {
      value: "rust:1.60-slim-buster",
      label: "rust:1.60-slim-buster"
    },
    {
      value: "rust:1.60-slim-bullseye",
      label: "rust:1.60-slim-bullseye"
    },
    {
      value: "rust:1.60-alpine3.14",
      label: "rust:1.60-alpine3.14"
    },
    {
      value: "rust:1.60-alpine3.15",
      label: "rust:1.60-alpine3.15"
    }
  ];
  const phpVersions = [
    {
      value: "webdevops/php-apache:8.2",
      label: "webdevops/php-apache:8.2"
    },
    {
      value: "webdevops/php-nginx:8.2",
      label: "webdevops/php-nginx:8.2"
    },
    {
      value: "webdevops/php-apache:8.1",
      label: "webdevops/php-apache:8.1"
    },
    {
      value: "webdevops/php-nginx:8.1",
      label: "webdevops/php-nginx:8.1"
    },
    {
      value: "webdevops/php-apache:8.0",
      label: "webdevops/php-apache:8.0"
    },
    {
      value: "webdevops/php-nginx:8.0",
      label: "webdevops/php-nginx:8.0"
    },
    {
      value: "webdevops/php-apache:7.4",
      label: "webdevops/php-apache:7.4"
    },
    {
      value: "webdevops/php-nginx:7.4",
      label: "webdevops/php-nginx:7.4"
    },
    {
      value: "webdevops/php-apache:7.3",
      label: "webdevops/php-apache:7.3"
    },
    {
      value: "webdevops/php-nginx:7.3",
      label: "webdevops/php-nginx:7.3"
    },
    {
      value: "webdevops/php-apache:7.2",
      label: "webdevops/php-apache:7.2"
    },
    {
      value: "webdevops/php-nginx:7.2",
      label: "webdevops/php-nginx:7.2"
    },
    {
      value: "webdevops/php-apache:7.1",
      label: "webdevops/php-apache:7.1"
    },
    {
      value: "webdevops/php-nginx:7.1",
      label: "webdevops/php-nginx:7.1"
    },
    {
      value: "webdevops/php-apache:7.0",
      label: "webdevops/php-apache:7.0"
    },
    {
      value: "webdevops/php-nginx:7.0",
      label: "webdevops/php-nginx:7.0"
    },
    {
      value: "webdevops/php-apache:5.6",
      label: "webdevops/php-apache:5.6"
    },
    {
      value: "webdevops/php-nginx:5.6",
      label: "webdevops/php-nginx:5.6"
    },
    {
      value: "webdevops/php-apache:8.2-alpine",
      label: "webdevops/php-apache:8.2-alpine"
    },
    {
      value: "webdevops/php-nginx:8.2-alpine",
      label: "webdevops/php-nginx:8.2-alpine"
    },
    {
      value: "webdevops/php-apache:8.1-alpine",
      label: "webdevops/php-apache:8.1-alpine"
    },
    {
      value: "webdevops/php-nginx:8.1-alpine",
      label: "webdevops/php-nginx:8.1-alpine"
    },
    {
      value: "webdevops/php-apache:8.0-alpine",
      label: "webdevops/php-apache:8.0-alpine"
    },
    {
      value: "webdevops/php-nginx:8.0-alpine",
      label: "webdevops/php-nginx:8.0-alpine"
    },
    {
      value: "webdevops/php-apache:7.4-alpine",
      label: "webdevops/php-apache:7.4-alpine"
    },
    {
      value: "webdevops/php-nginx:7.4-alpine",
      label: "webdevops/php-nginx:7.4-alpine"
    },
    {
      value: "webdevops/php-apache:7.3-alpine",
      label: "webdevops/php-apache:7.3-alpine"
    },
    {
      value: "webdevops/php-nginx:7.3-alpine",
      label: "webdevops/php-nginx:7.3-alpine"
    },
    {
      value: "webdevops/php-apache:7.2-alpine",
      label: "webdevops/php-apache:7.2-alpine"
    },
    {
      value: "webdevops/php-nginx:7.2-alpine",
      label: "webdevops/php-nginx:7.2-alpine"
    },
    {
      value: "webdevops/php-apache:7.1-alpine",
      label: "webdevops/php-apache:7.1-alpine"
    },
    {
      value: "php:8.1-fpm",
      label: "php:8.1-fpm"
    },
    {
      value: "php:8.0-fpm",
      label: "php:8.0-fpm"
    },
    {
      value: "php:8.1-fpm-alpine",
      label: "php:8.1-fpm-alpine"
    },
    {
      value: "php:8.0-fpm-alpine",
      label: "php:8.0-fpm-alpine"
    }
  ];
  const pythonVersions = [
    {
      value: "python:3.10-alpine",
      label: "python:3.10-alpine"
    },
    {
      value: "python:3.10-buster",
      label: "python:3.10-buster"
    },
    {
      value: "python:3.10-bullseye",
      label: "python:3.10-bullseye"
    },
    {
      value: "python:3.10-slim-bullseye",
      label: "python:3.10-slim-bullseye"
    },
    {
      value: "python:3.9-alpine",
      label: "python:3.9-alpine"
    },
    {
      value: "python:3.9-buster",
      label: "python:3.9-buster"
    },
    {
      value: "python:3.9-bullseye",
      label: "python:3.9-bullseye"
    },
    {
      value: "python:3.9-slim-bullseye",
      label: "python:3.9-slim-bullseye"
    },
    {
      value: "python:3.8-alpine",
      label: "python:3.8-alpine"
    },
    {
      value: "python:3.8-buster",
      label: "python:3.8-buster"
    },
    {
      value: "python:3.8-bullseye",
      label: "python:3.8-bullseye"
    },
    {
      value: "python:3.8-slim-bullseye",
      label: "python:3.8-slim-bullseye"
    },
    {
      value: "python:3.7-alpine",
      label: "python:3.7-alpine"
    },
    {
      value: "python:3.7-buster",
      label: "python:3.7-buster"
    },
    {
      value: "python:3.7-bullseye",
      label: "python:3.7-bullseye"
    },
    {
      value: "python:3.7-slim-bullseye",
      label: "python:3.7-slim-bullseye"
    }
  ];
  const herokuVersions = [
    {
      value: "heroku/builder:22",
      label: "heroku/builder:22"
    },
    {
      value: "heroku/buildpacks:20",
      label: "heroku/buildpacks:20"
    },
    {
      value: "heroku/builder-classic:22",
      label: "heroku/builder-classic:22"
    }
  ];
  let payload = {
    baseImage: null,
    baseBuildImage: null,
    baseImages: [],
    baseBuildImages: []
  };
  if (nodeBased.includes(buildPack)) {
    if (deploymentType === "static") {
      payload.baseImage = (0, import_common.isARM)(process.arch) ? "nginx:alpine" : "webdevops/nginx:alpine";
      payload.baseImages = (0, import_common.isARM)(process.arch) ? staticVersions.filter((version2) => !version2.value.includes("webdevops")) : staticVersions;
      payload.baseBuildImage = "node:lts";
      payload.baseBuildImages = nodeVersions;
    } else {
      payload.baseImage = "node:lts";
      payload.baseImages = nodeVersions;
      payload.baseBuildImage = "node:lts";
      payload.baseBuildImages = nodeVersions;
    }
  }
  if (staticApps.includes(buildPack)) {
    payload.baseImage = (0, import_common.isARM)(process.arch) ? "nginx:alpine" : "webdevops/nginx:alpine";
    payload.baseImages = (0, import_common.isARM)(process.arch) ? staticVersions.filter((version2) => !version2.value.includes("webdevops")) : staticVersions;
    payload.baseBuildImage = "node:lts";
    payload.baseBuildImages = nodeVersions;
  }
  if (buildPack === "python") {
    payload.baseImage = "python:3.10-alpine";
    payload.baseImages = pythonVersions;
  }
  if (buildPack === "rust") {
    payload.baseImage = "rust:latest";
    payload.baseBuildImage = "rust:latest";
    payload.baseImages = rustVersions;
    payload.baseBuildImages = rustVersions;
  }
  if (buildPack === "deno") {
    payload.baseImage = "denoland/deno:latest";
  }
  if (buildPack === "php") {
    payload.baseImage = (0, import_common.isARM)(process.arch) ? "php:8.1-fpm-alpine" : "webdevops/php-apache:8.2-alpine";
    payload.baseImages = (0, import_common.isARM)(process.arch) ? phpVersions.filter((version2) => !version2.value.includes("webdevops")) : phpVersions;
  }
  if (buildPack === "laravel") {
    payload.baseImage = (0, import_common.isARM)(process.arch) ? "php:8.1-fpm-alpine" : "webdevops/php-apache:8.2-alpine";
    payload.baseImages = (0, import_common.isARM)(process.arch) ? phpVersions.filter((version2) => !version2.value.includes("webdevops")) : phpVersions;
    payload.baseBuildImage = "node:18";
    payload.baseBuildImages = nodeVersions;
  }
  if (buildPack === "heroku") {
    payload.baseImage = "heroku/buildpacks:20";
    payload.baseImages = herokuVersions;
  }
  return payload;
}
const setDefaultConfiguration = async (data) => {
  let {
    buildPack,
    port,
    installCommand,
    startCommand,
    buildCommand,
    publishDirectory,
    baseDirectory,
    dockerFileLocation,
    dockerComposeFileLocation,
    denoMainFile
  } = data;
  const template = scanningTemplates[buildPack];
  if (!port) {
    port = template?.port || 3e3;
    if (buildPack === "static")
      port = 80;
    else if (buildPack === "node")
      port = 3e3;
    else if (buildPack === "php")
      port = 80;
    else if (buildPack === "python")
      port = 8e3;
  }
  if (!installCommand && buildPack !== "static" && buildPack !== "laravel")
    installCommand = template?.installCommand || "yarn install";
  if (!startCommand && buildPack !== "static" && buildPack !== "laravel")
    startCommand = template?.startCommand || "yarn start";
  if (!buildCommand && buildPack !== "static" && buildPack !== "laravel")
    buildCommand = template?.buildCommand || null;
  if (!publishDirectory)
    publishDirectory = template?.publishDirectory || null;
  if (baseDirectory) {
    if (!baseDirectory.startsWith("/"))
      baseDirectory = `/${baseDirectory}`;
    if (baseDirectory.endsWith("/") && baseDirectory !== "/")
      baseDirectory = baseDirectory.slice(0, -1);
  }
  if (dockerFileLocation) {
    if (!dockerFileLocation.startsWith("/"))
      dockerFileLocation = `/${dockerFileLocation}`;
    if (dockerFileLocation.endsWith("/"))
      dockerFileLocation = dockerFileLocation.slice(0, -1);
  } else {
    dockerFileLocation = "/Dockerfile";
  }
  if (dockerComposeFileLocation) {
    if (!dockerComposeFileLocation.startsWith("/"))
      dockerComposeFileLocation = `/${dockerComposeFileLocation}`;
    if (dockerComposeFileLocation.endsWith("/"))
      dockerComposeFileLocation = dockerComposeFileLocation.slice(0, -1);
  } else {
    dockerComposeFileLocation = "/Dockerfile";
  }
  if (!denoMainFile) {
    denoMainFile = "main.ts";
  }
  return {
    buildPack,
    port,
    installCommand,
    startCommand,
    buildCommand,
    publishDirectory,
    baseDirectory,
    dockerFileLocation,
    dockerComposeFileLocation,
    denoMainFile
  };
};
const scanningTemplates = {
  "@sveltejs/kit": {
    buildPack: "nodejs"
  },
  astro: {
    buildPack: "astro"
  },
  "@11ty/eleventy": {
    buildPack: "eleventy"
  },
  svelte: {
    buildPack: "svelte"
  },
  "@nestjs/core": {
    buildPack: "nestjs"
  },
  next: {
    buildPack: "nextjs"
  },
  nuxt: {
    buildPack: "nuxtjs"
  },
  "react-scripts": {
    buildPack: "react"
  },
  "parcel-bundler": {
    buildPack: "static"
  },
  "@vue/cli-service": {
    buildPack: "vuejs"
  },
  vuejs: {
    buildPack: "vuejs"
  },
  gatsby: {
    buildPack: "gatsby"
  },
  "preact-cli": {
    buildPack: "react"
  }
};
const saveBuildLog = async ({
  line,
  buildId,
  applicationId
}) => {
  if (buildId === "undefined" || buildId === "null" || !buildId)
    return;
  if (applicationId === "undefined" || applicationId === "null" || !applicationId)
    return;
  const { default: got } = await import("got");
  if (typeof line === "object" && line) {
    if (line.shortMessage) {
      line = line.shortMessage + "\n" + line.stderr;
    } else {
      line = JSON.stringify(line);
    }
  }
  if (line && typeof line === "string" && line.includes("ghs_")) {
    const regex = /ghs_.*@/g;
    line = line.replace(regex, "<SENSITIVE_DATA_DELETED>@");
  }
  const addTimestamp = `[${(0, import_common.generateTimestamp)()}] ${line}`;
  const fluentBitUrl = import_common.isDev ? process.env.COOLIFY_CONTAINER_DEV === "true" ? "http://coolify-fluentbit:24224" : "http://localhost:24224" : "http://coolify-fluentbit:24224";
  if (import_common.isDev && !process.env.COOLIFY_CONTAINER_DEV) {
    console.debug(`[${applicationId}] ${addTimestamp}`);
  }
  try {
    return await got.post(`${fluentBitUrl}/${applicationId}_buildlog_${buildId}.csv`, {
      json: {
        line: (0, import_common.encrypt)(line)
      }
    });
  } catch (error) {
    return await import_prisma.prisma.buildLog.create({
      data: {
        line: addTimestamp,
        buildId,
        time: Number((0, import_dayjs.day)().valueOf()),
        applicationId
      }
    });
  }
};
async function copyBaseConfigurationFiles(buildPack, workdir, buildId, applicationId, baseImage) {
  try {
    if (buildPack === "php") {
      await import_fs.promises.writeFile(`${workdir}/entrypoint.sh`, `chown -R 1000 /app`);
      await saveBuildLog({
        line: "Copied default configuration file for PHP.",
        buildId,
        applicationId
      });
    } else if (baseImage?.includes("nginx")) {
      await import_fs.promises.writeFile(
        `${workdir}/nginx.conf`,
        `user  nginx;
            worker_processes  auto;
            
            error_log  /docker.stdout;
            pid        /run/nginx.pid;
            
            events {
                worker_connections  1024;
            }
            
            http {
				log_format  main  '$remote_addr - $remote_user [$time_local] "$request" '
				'$status $body_bytes_sent "$http_referer" '
				'"$http_user_agent" "$http_x_forwarded_for"';

                access_log  /docker.stdout main;

				sendfile            on;
				tcp_nopush          on;
				tcp_nodelay         on;
				keepalive_timeout   65;
				types_hash_max_size 2048;

			    include             /etc/nginx/mime.types;
    			default_type        application/octet-stream;
				
                server {
                    listen       80;
                    server_name  localhost;
                    
                    location / {
                        root   /app;
                        index  index.html;
                        try_files $uri $uri/index.html $uri/ /index.html =404;
                    }
            
                    error_page  404              /50x.html;
            
                    # redirect server error pages to the static page /50x.html
                    #
                    error_page   500 502 503 504  /50x.html;
                    location = /50x.html {
                        root   /app;
                    }  
            
                }
            
            }
            `
      );
    }
  } catch (error) {
    throw new Error(error);
  }
}
function checkPnpm(installCommand = null, buildCommand = null, startCommand = null) {
  return installCommand?.includes("pnpm") || buildCommand?.includes("pnpm") || startCommand?.includes("pnpm");
}
async function saveDockerRegistryCredentials({ url, username, password, workdir }) {
  if (!username || !password) {
    return null;
  }
  let decryptedPassword = (0, import_common.decrypt)(password);
  const location = `${workdir}/.docker`;
  try {
    await import_fs.promises.mkdir(`${workdir}/.docker`);
  } catch (error) {
  }
  const payload = JSON.stringify({
    auths: {
      [url]: {
        auth: Buffer.from(`${username}:${decryptedPassword}`).toString("base64")
      }
    }
  });
  await import_fs.promises.writeFile(`${location}/config.json`, payload);
  return location;
}
async function buildImage({
  applicationId,
  tag,
  workdir,
  buildId,
  dockerId,
  isCache = false,
  debug = false,
  dockerFileLocation = "/Dockerfile",
  commit,
  forceRebuild = false
}) {
  if (isCache) {
    await saveBuildLog({ line: `Building cache image...`, buildId, applicationId });
  } else {
    await saveBuildLog({ line: `Building production image...`, buildId, applicationId });
  }
  const dockerFile = isCache ? `${dockerFileLocation}-cache` : `${dockerFileLocation}`;
  const cache = `${applicationId}:${tag}${isCache ? "-cache" : ""}`;
  let location = null;
  const { dockerRegistry } = await import_prisma.prisma.application.findUnique({
    where: { id: applicationId },
    select: { dockerRegistry: true }
  });
  if (dockerRegistry) {
    const { url, username, password } = dockerRegistry;
    location = await saveDockerRegistryCredentials({ url, username, password, workdir });
  }
  await (0, import_executeCommand.executeCommand)({
    stream: true,
    debug,
    buildId,
    applicationId,
    dockerId,
    command: `docker ${location ? `--config ${location}` : ""} build ${forceRebuild ? "--no-cache" : ""} --progress plain -f ${workdir}/${dockerFile} -t ${cache} --build-arg SOURCE_COMMIT=${commit} ${workdir}`
  });
  const { status } = await import_prisma.prisma.build.findUnique({ where: { id: buildId } });
  if (status === "canceled") {
    throw new Error("Canceled.");
  }
}
function makeLabelForSimpleDockerfile({ applicationId, port, type }) {
  return [
    "coolify.managed=true",
    `coolify.version=${import_common.version}`,
    `coolify.applicationId=${applicationId}`,
    `coolify.type=standalone-application`
  ];
}
function makeLabelForStandaloneApplication({
  applicationId,
  fqdn,
  name,
  type,
  pullmergeRequestId = null,
  buildPack,
  repository,
  branch,
  projectId,
  port,
  commit,
  installCommand,
  buildCommand,
  startCommand,
  baseDirectory,
  publishDirectory
}) {
  if (pullmergeRequestId) {
    const protocol = fqdn.startsWith("https://") ? "https" : "http";
    const domain = (0, import_common.getDomain)(fqdn);
    fqdn = `${protocol}://${pullmergeRequestId}.${domain}`;
  }
  return [
    "coolify.managed=true",
    `coolify.version=${import_common.version}`,
    `coolify.applicationId=${applicationId}`,
    `coolify.type=standalone-application`,
    `coolify.name=${name}`,
    `coolify.configuration=${(0, import_common.base64Encode)(
      JSON.stringify({
        applicationId,
        fqdn,
        name,
        type,
        pullmergeRequestId,
        buildPack,
        repository,
        branch,
        projectId,
        port,
        commit,
        installCommand,
        buildCommand,
        startCommand,
        baseDirectory,
        publishDirectory
      })
    )}`
  ];
}
async function buildCacheImageWithNode(data, imageForBuild) {
  const {
    workdir,
    buildId,
    baseDirectory,
    installCommand,
    buildCommand,
    secrets,
    pullmergeRequestId
  } = data;
  const isPnpm = checkPnpm(installCommand, buildCommand);
  const Dockerfile = [];
  Dockerfile.push(`FROM ${imageForBuild}`);
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
  Dockerfile.push(`COPY .${baseDirectory || ""} ./`);
  if (installCommand) {
    Dockerfile.push(`RUN ${installCommand}`);
  }
  Dockerfile.push(`RUN ${buildCommand}`);
  await import_fs.promises.writeFile(`${workdir}/Dockerfile-cache`, Dockerfile.join("\n"));
  await buildImage({ ...data, isCache: true });
}
async function buildCacheImageForLaravel(data, imageForBuild) {
  const { workdir, buildId, secrets, pullmergeRequestId } = data;
  const Dockerfile = [];
  Dockerfile.push(`FROM ${imageForBuild}`);
  Dockerfile.push("WORKDIR /app");
  Dockerfile.push(`LABEL coolify.buildId=${buildId}`);
  if (secrets.length > 0) {
    (0, import_common.generateSecrets)(secrets, pullmergeRequestId, true).forEach((env) => {
      Dockerfile.push(env);
    });
  }
  Dockerfile.push(`COPY *.json *.mix.js /app/`);
  Dockerfile.push(`COPY resources /app/resources`);
  Dockerfile.push(`RUN yarn install && yarn production`);
  await import_fs.promises.writeFile(`${workdir}/Dockerfile-cache`, Dockerfile.join("\n"));
  await buildImage({ ...data, isCache: true });
}
async function buildCacheImageWithCargo(data, imageForBuild) {
  const { applicationId, workdir, buildId } = data;
  const Dockerfile = [];
  Dockerfile.push(`FROM ${imageForBuild} as planner-${applicationId}`);
  Dockerfile.push(`LABEL coolify.buildId=${buildId}`);
  Dockerfile.push("WORKDIR /app");
  Dockerfile.push("RUN cargo install cargo-chef");
  Dockerfile.push("COPY . .");
  Dockerfile.push("RUN cargo chef prepare --recipe-path recipe.json");
  Dockerfile.push(`FROM ${imageForBuild}`);
  Dockerfile.push(`LABEL coolify.buildId=${buildId}`);
  Dockerfile.push("WORKDIR /app");
  Dockerfile.push("RUN cargo install cargo-chef");
  Dockerfile.push(`COPY --from=planner-${applicationId} /app/recipe.json recipe.json`);
  Dockerfile.push("RUN cargo chef cook --release --recipe-path recipe.json");
  await import_fs.promises.writeFile(`${workdir}/Dockerfile-cache`, Dockerfile.join("\n"));
  await buildImage({ ...data, isCache: true });
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  buildCacheImageForLaravel,
  buildCacheImageWithCargo,
  buildCacheImageWithNode,
  buildImage,
  checkPnpm,
  copyBaseConfigurationFiles,
  makeLabelForSimpleDockerfile,
  makeLabelForStandaloneApplication,
  saveBuildLog,
  saveDockerRegistryCredentials,
  scanningTemplates,
  setDefaultBaseImage,
  setDefaultConfiguration
});
