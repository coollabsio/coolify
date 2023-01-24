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
  asyncSleep: () => asyncSleep,
  base64Decode: () => base64Decode,
  base64Encode: () => base64Encode,
  checkDomainsIsValidInDNS: () => checkDomainsIsValidInDNS,
  checkExposedPort: () => checkExposedPort,
  cleanupDB: () => cleanupDB,
  comparePassword: () => comparePassword,
  configureNetworkTraefikProxy: () => configureNetworkTraefikProxy,
  createDirectories: () => createDirectories,
  decrypt: () => decrypt,
  decryptApplication: () => decryptApplication,
  defaultTraefikImage: () => defaultTraefikImage,
  encrypt: () => encrypt,
  fixType: () => fixType,
  generateRangeArray: () => generateRangeArray,
  generateSecrets: () => generateSecrets,
  generateTimestamp: () => generateTimestamp,
  getAPIUrl: () => getAPIUrl,
  getContainerUsage: () => getContainerUsage,
  getCurrentUser: () => getCurrentUser,
  getDomain: () => getDomain,
  getFreeExposedPort: () => getFreeExposedPort,
  getTags: () => getTags,
  getTeamInvitation: () => getTeamInvitation,
  getTemplates: () => getTemplates,
  getUIUrl: () => getUIUrl,
  hashPassword: () => hashPassword,
  isARM: () => isARM,
  isDev: () => isDev,
  isDomainConfigured: () => isDomainConfigured,
  listSettings: () => listSettings,
  makeLabelForServices: () => makeLabelForServices,
  pushToRegistry: () => pushToRegistry,
  removeService: () => removeService,
  saveDockerRegistryCredentials: () => saveDockerRegistryCredentials,
  scanningTemplates: () => scanningTemplates,
  sentryDSN: () => sentryDSN,
  setDefaultConfiguration: () => setDefaultConfiguration,
  startTraefikProxy: () => startTraefikProxy,
  startTraefikTCPProxy: () => startTraefikTCPProxy,
  stopTraefikProxy: () => stopTraefikProxy,
  uniqueName: () => uniqueName,
  version: () => version
});
module.exports = __toCommonJS(common_exports);
var import_prisma = require("../prisma");
var import_bcryptjs = __toESM(require("bcryptjs"));
var import_crypto = __toESM(require("crypto"));
var import_dns = require("dns");
var import_promises = __toESM(require("fs/promises"));
var import_unique_names_generator = require("unique-names-generator");
var import_env = require("../env");
var import_dayjs = require("./dayjs");
var import_executeCommand = require("./executeCommand");
var import_logging = require("./logging");
var import_docker = require("./docker");
var import_js_yaml = __toESM(require("js-yaml"));
const customConfig = {
  dictionaries: [import_unique_names_generator.adjectives, import_unique_names_generator.colors, import_unique_names_generator.animals],
  style: "capital",
  separator: " ",
  length: 3
};
const algorithm = "aes-256-ctr";
const isDev = import_env.env.NODE_ENV === "development";
const version = "3.13.0";
const sentryDSN = "https://409f09bcb7af47928d3e0f46b78987f3@o1082494.ingest.sentry.io/4504236622217216";
const defaultTraefikImage = `traefik:v2.8`;
function getAPIUrl() {
  if (process.env.GITPOD_WORKSPACE_URL) {
    const { href } = new URL(process.env.GITPOD_WORKSPACE_URL);
    const newURL = href.replace("https://", "https://3001-").replace(/\/$/, "");
    return newURL;
  }
  if (process.env.CODESANDBOX_HOST) {
    return `https://${process.env.CODESANDBOX_HOST.replace(/\$PORT/, "3001")}`;
  }
  return isDev ? "http://host.docker.internal:3001" : "http://localhost:3000";
}
function getUIUrl() {
  if (process.env.GITPOD_WORKSPACE_URL) {
    const { href } = new URL(process.env.GITPOD_WORKSPACE_URL);
    const newURL = href.replace("https://", "https://3000-").replace(/\/$/, "");
    return newURL;
  }
  if (process.env.CODESANDBOX_HOST) {
    return `https://${process.env.CODESANDBOX_HOST.replace(/\$PORT/, "3000")}`;
  }
  return "http://localhost:3000";
}
const mainTraefikEndpoint = isDev ? `${getAPIUrl()}/webhooks/traefik/main.json` : "http://coolify:3000/webhooks/traefik/main.json";
const otherTraefikEndpoint = isDev ? `${getAPIUrl()}/webhooks/traefik/other.json` : "http://coolify:3000/webhooks/traefik/other.json";
async function listSettings() {
  return await import_prisma.prisma.setting.findUnique({ where: { id: "0" } });
}
async function getCurrentUser(userId) {
  return await import_prisma.prisma.user.findUnique({
    where: { id: userId },
    include: { teams: true, permission: true }
  });
}
async function getTeamInvitation(userId) {
  return await import_prisma.prisma.teamInvitation.findMany({ where: { uid: userId } });
}
async function hashPassword(password) {
  const saltRounds = 15;
  return import_bcryptjs.default.hash(password, saltRounds);
}
async function comparePassword(password, hashedPassword) {
  return import_bcryptjs.default.compare(password, hashedPassword);
}
const uniqueName = () => (0, import_unique_names_generator.uniqueNamesGenerator)(customConfig);
const decrypt = (hashString) => {
  if (hashString) {
    try {
      const hash = JSON.parse(hashString);
      const decipher = import_crypto.default.createDecipheriv(
        algorithm,
        import_env.env.COOLIFY_SECRET_KEY,
        Buffer.from(hash.iv, "hex")
      );
      const decrpyted = Buffer.concat([
        decipher.update(Buffer.from(hash.content, "hex")),
        decipher.final()
      ]);
      return decrpyted.toString();
    } catch (error) {
      if (error instanceof Error) {
        console.log({ decryptionError: error.message });
      }
      return hashString;
    }
  }
  return false;
};
function generateRangeArray(start, end) {
  return Array.from({ length: end - start }, (_v, k) => k + start);
}
function generateTimestamp() {
  return `${(0, import_dayjs.day)().format("HH:mm:ss.SSS")}`;
}
const encrypt = (text) => {
  if (text) {
    const iv = import_crypto.default.randomBytes(16);
    const cipher = import_crypto.default.createCipheriv(algorithm, import_env.env.COOLIFY_SECRET_KEY, iv);
    const encrypted = Buffer.concat([cipher.update(text.trim()), cipher.final()]);
    return JSON.stringify({
      iv: iv.toString("hex"),
      content: encrypted.toString("hex")
    });
  }
  return false;
};
async function getTemplates() {
  const templatePath = isDev ? "./templates.json" : "/app/templates.json";
  const open = await import_promises.default.open(templatePath, "r");
  try {
    let data = await open.readFile({ encoding: "utf-8" });
    let jsonData = JSON.parse(data);
    if (isARM(process.arch)) {
      jsonData = jsonData.filter((d) => d.arch !== "amd64");
    }
    return jsonData;
  } catch (error) {
    return [];
  } finally {
    await open?.close();
  }
}
function isARM(arch) {
  if (arch === "arm" || arch === "arm64" || arch === "aarch" || arch === "aarch64") {
    return true;
  }
  return false;
}
async function removeService({ id }) {
  await import_prisma.prisma.serviceSecret.deleteMany({ where: { serviceId: id } });
  await import_prisma.prisma.serviceSetting.deleteMany({ where: { serviceId: id } });
  await import_prisma.prisma.servicePersistentStorage.deleteMany({ where: { serviceId: id } });
  await import_prisma.prisma.meiliSearch.deleteMany({ where: { serviceId: id } });
  await import_prisma.prisma.fider.deleteMany({ where: { serviceId: id } });
  await import_prisma.prisma.ghost.deleteMany({ where: { serviceId: id } });
  await import_prisma.prisma.umami.deleteMany({ where: { serviceId: id } });
  await import_prisma.prisma.hasura.deleteMany({ where: { serviceId: id } });
  await import_prisma.prisma.plausibleAnalytics.deleteMany({ where: { serviceId: id } });
  await import_prisma.prisma.minio.deleteMany({ where: { serviceId: id } });
  await import_prisma.prisma.vscodeserver.deleteMany({ where: { serviceId: id } });
  await import_prisma.prisma.wordpress.deleteMany({ where: { serviceId: id } });
  await import_prisma.prisma.glitchTip.deleteMany({ where: { serviceId: id } });
  await import_prisma.prisma.moodle.deleteMany({ where: { serviceId: id } });
  await import_prisma.prisma.appwrite.deleteMany({ where: { serviceId: id } });
  await import_prisma.prisma.searxng.deleteMany({ where: { serviceId: id } });
  await import_prisma.prisma.weblate.deleteMany({ where: { serviceId: id } });
  await import_prisma.prisma.taiga.deleteMany({ where: { serviceId: id } });
  await import_prisma.prisma.service.delete({ where: { id } });
}
const createDirectories = async ({
  repository,
  buildId
}) => {
  if (repository)
    repository = repository.replaceAll(" ", "");
  const repodir = `/tmp/build-sources/${repository}/`;
  const workdir = `/tmp/build-sources/${repository}/${buildId}`;
  let workdirFound = false;
  try {
    workdirFound = !!await import_promises.default.stat(workdir);
  } catch (error) {
  }
  if (workdirFound) {
    await (0, import_executeCommand.executeCommand)({ command: `rm -fr ${workdir}` });
  }
  await (0, import_executeCommand.executeCommand)({ command: `mkdir -p ${workdir}` });
  return {
    workdir,
    repodir
  };
};
async function saveDockerRegistryCredentials({ url, username, password, workdir }) {
  if (!username || !password) {
    return null;
  }
  let decryptedPassword = decrypt(password);
  const location = `${workdir}/.docker`;
  try {
    await import_promises.default.mkdir(`${workdir}/.docker`);
  } catch (error) {
    console.log(error);
  }
  const payload = JSON.stringify({
    auths: {
      [url]: {
        auth: Buffer.from(`${username}:${decryptedPassword}`).toString("base64")
      }
    }
  });
  await import_promises.default.writeFile(`${location}/config.json`, payload);
  return location;
}
function getDomain(domain) {
  if (domain) {
    return domain?.replace("https://", "").replace("http://", "");
  } else {
    return "";
  }
}
async function isDomainConfigured({
  id,
  fqdn,
  checkOwn = false,
  remoteIpAddress = void 0
}) {
  const domain = getDomain(fqdn);
  const nakedDomain = domain.replace("www.", "");
  const foundApp = await import_prisma.prisma.application.findFirst({
    where: {
      OR: [
        { fqdn: { endsWith: `//${nakedDomain}` } },
        { fqdn: { endsWith: `//www.${nakedDomain}` } },
        { dockerComposeConfiguration: { contains: `//${nakedDomain}` } },
        { dockerComposeConfiguration: { contains: `//www.${nakedDomain}` } }
      ],
      id: { not: id },
      destinationDocker: {
        remoteIpAddress
      }
    },
    select: { fqdn: true }
  });
  const foundService = await import_prisma.prisma.service.findFirst({
    where: {
      OR: [
        { fqdn: { endsWith: `//${nakedDomain}` } },
        { fqdn: { endsWith: `//www.${nakedDomain}` } }
      ],
      id: { not: checkOwn ? void 0 : id },
      destinationDocker: {
        remoteIpAddress
      }
    },
    select: { fqdn: true }
  });
  const coolifyFqdn = await import_prisma.prisma.setting.findFirst({
    where: {
      OR: [
        { fqdn: { endsWith: `//${nakedDomain}` } },
        { fqdn: { endsWith: `//www.${nakedDomain}` } }
      ],
      id: { not: id }
    },
    select: { fqdn: true }
  });
  return !!(foundApp || foundService || coolifyFqdn);
}
async function checkExposedPort({
  id,
  configuredPort,
  exposePort,
  engine,
  remoteEngine,
  remoteIpAddress
}) {
  if (exposePort < 1024 || exposePort > 65535) {
    throw { status: 500, message: `Exposed Port needs to be between 1024 and 65535.` };
  }
  if (configuredPort) {
    if (configuredPort !== exposePort) {
      const availablePort = await getFreeExposedPort(
        id,
        exposePort,
        engine,
        remoteEngine,
        remoteIpAddress
      );
      if (availablePort.toString() !== exposePort.toString()) {
        throw { status: 500, message: `Port ${exposePort} is already in use.` };
      }
    }
  } else {
    const availablePort = await getFreeExposedPort(
      id,
      exposePort,
      engine,
      remoteEngine,
      remoteIpAddress
    );
    if (availablePort.toString() !== exposePort.toString()) {
      throw { status: 500, message: `Port ${exposePort} is already in use.` };
    }
  }
}
async function getFreeExposedPort(id, exposePort, engine, remoteEngine, remoteIpAddress) {
  const { default: checkPort } = await import("is-port-reachable");
  if (remoteEngine) {
    const applicationUsed = await (await import_prisma.prisma.application.findMany({
      where: {
        exposePort: { not: null },
        id: { not: id },
        destinationDocker: { remoteIpAddress }
      },
      select: { exposePort: true }
    })).map((a) => a.exposePort);
    const serviceUsed = await (await import_prisma.prisma.service.findMany({
      where: {
        exposePort: { not: null },
        id: { not: id },
        destinationDocker: { remoteIpAddress }
      },
      select: { exposePort: true }
    })).map((a) => a.exposePort);
    const usedPorts = [...applicationUsed, ...serviceUsed];
    if (usedPorts.includes(exposePort)) {
      return false;
    }
    const found = await checkPort(exposePort, { host: remoteIpAddress });
    if (!found) {
      return exposePort;
    }
    return false;
  } else {
    const applicationUsed = await (await import_prisma.prisma.application.findMany({
      where: { exposePort: { not: null }, id: { not: id }, destinationDocker: { engine } },
      select: { exposePort: true }
    })).map((a) => a.exposePort);
    const serviceUsed = await (await import_prisma.prisma.service.findMany({
      where: { exposePort: { not: null }, id: { not: id }, destinationDocker: { engine } },
      select: { exposePort: true }
    })).map((a) => a.exposePort);
    const usedPorts = [...applicationUsed, ...serviceUsed];
    if (usedPorts.includes(exposePort)) {
      return false;
    }
    const found = await checkPort(exposePort, { host: "localhost" });
    if (!found) {
      return exposePort;
    }
    return false;
  }
}
async function checkDomainsIsValidInDNS({ hostname, fqdn, dualCerts }) {
  const { isIP } = await import("is-ip");
  const domain = getDomain(fqdn);
  const domainDualCert = domain.includes("www.") ? domain.replace("www.", "") : `www.${domain}`;
  const { DNSServers } = await listSettings();
  if (DNSServers) {
    import_dns.promises.setServers([...DNSServers.split(",")]);
  }
  let resolves = [];
  try {
    if (isIP(hostname)) {
      resolves = [hostname];
    } else {
      resolves = await import_dns.promises.resolve4(hostname);
    }
  } catch (error) {
    throw { status: 500, message: `Could not determine IP address for ${hostname}.` };
  }
  if (dualCerts) {
    try {
      const ipDomain = await import_dns.promises.resolve4(domain);
      const ipDomainDualCert = await import_dns.promises.resolve4(domainDualCert);
      let ipDomainFound = false;
      let ipDomainDualCertFound = false;
      for (const ip of ipDomain) {
        if (resolves.includes(ip)) {
          ipDomainFound = true;
        }
      }
      for (const ip of ipDomainDualCert) {
        if (resolves.includes(ip)) {
          ipDomainDualCertFound = true;
        }
      }
      if (ipDomainFound && ipDomainDualCertFound)
        return { status: 200 };
      throw {
        status: 500,
        message: `DNS not set correctly or propogated.<br>Please check your DNS settings.`
      };
    } catch (error) {
      throw {
        status: 500,
        message: `DNS not set correctly or propogated.<br>Please check your DNS settings.`
      };
    }
  } else {
    try {
      const ipDomain = await import_dns.promises.resolve4(domain);
      let ipDomainFound = false;
      for (const ip of ipDomain) {
        if (resolves.includes(ip)) {
          ipDomainFound = true;
        }
      }
      if (ipDomainFound)
        return { status: 200 };
      throw {
        status: 500,
        message: `DNS not set correctly or propogated.<br>Please check your DNS settings.`
      };
    } catch (error) {
      throw {
        status: 500,
        message: `DNS not set correctly or propogated.<br>Please check your DNS settings.`
      };
    }
  }
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
async function cleanupDB(buildId, applicationId) {
  const data = await import_prisma.prisma.build.findUnique({ where: { id: buildId } });
  if (data?.status === "queued" || data?.status === "running") {
    await import_prisma.prisma.build.update({ where: { id: buildId }, data: { status: "canceled" } });
  }
  await (0, import_logging.saveBuildLog)({ line: "Canceled.", buildId, applicationId });
}
const base64Encode = (text) => {
  return Buffer.from(text).toString("base64");
};
const base64Decode = (text) => {
  return Buffer.from(text, "base64").toString("ascii");
};
function parseSecret(secret, isBuild) {
  if (secret.value.includes("$")) {
    secret.value = secret.value.replaceAll("$", "$$$$");
  }
  if (secret.value.includes("\\n")) {
    if (isBuild) {
      return `ARG ${secret.name}=${secret.value}`;
    } else {
      return `${secret.name}=${secret.value}`;
    }
  } else if (secret.value.includes(" ")) {
    if (isBuild) {
      return `ARG ${secret.name}='${secret.value}'`;
    } else {
      return `${secret.name}='${secret.value}'`;
    }
  } else {
    if (isBuild) {
      return `ARG ${secret.name}=${secret.value}`;
    } else {
      return `${secret.name}=${secret.value}`;
    }
  }
}
function generateSecrets(secrets, pullmergeRequestId, isBuild = false, port = null) {
  const envs = [];
  const isPRMRSecret = secrets.filter((s) => s.isPRMRSecret);
  const normalSecrets = secrets.filter((s) => !s.isPRMRSecret);
  if (pullmergeRequestId && isPRMRSecret.length > 0) {
    isPRMRSecret.forEach((secret) => {
      if (isBuild && !secret.isBuildSecret) {
        return;
      }
      const build = isBuild && secret.isBuildSecret;
      envs.push(parseSecret(secret, build));
    });
  }
  if (!pullmergeRequestId && normalSecrets.length > 0) {
    normalSecrets.forEach((secret) => {
      if (isBuild && !secret.isBuildSecret) {
        return;
      }
      const build = isBuild && secret.isBuildSecret;
      envs.push(parseSecret(secret, build));
    });
  }
  const portFound = envs.filter((env2) => env2.startsWith("PORT"));
  if (portFound.length === 0 && port && !isBuild) {
    envs.push(`PORT=${port}`);
  }
  const nodeEnv = envs.filter((env2) => env2.startsWith("NODE_ENV"));
  if (nodeEnv.length === 0 && !isBuild) {
    envs.push(`NODE_ENV=production`);
  }
  return envs;
}
function decryptApplication(application) {
  if (application) {
    if (application?.gitSource?.githubApp?.clientSecret) {
      application.gitSource.githubApp.clientSecret = decrypt(application.gitSource.githubApp.clientSecret) || null;
    }
    if (application?.gitSource?.githubApp?.webhookSecret) {
      application.gitSource.githubApp.webhookSecret = decrypt(application.gitSource.githubApp.webhookSecret) || null;
    }
    if (application?.gitSource?.githubApp?.privateKey) {
      application.gitSource.githubApp.privateKey = decrypt(application.gitSource.githubApp.privateKey) || null;
    }
    if (application?.gitSource?.gitlabApp?.appSecret) {
      application.gitSource.gitlabApp.appSecret = decrypt(application.gitSource.gitlabApp.appSecret) || null;
    }
    if (application?.secrets.length > 0) {
      application.secrets = application.secrets.map((s) => {
        s.value = decrypt(s.value) || null;
        return s;
      });
    }
    return application;
  }
}
async function pushToRegistry(application, workdir, tag, imageName, customTag) {
  const location = `${workdir}/.docker`;
  const tagCommand = `docker tag ${application.id}:${tag} ${imageName}:${customTag}`;
  const pushCommand = `docker --config ${location} push ${imageName}:${customTag}`;
  await (0, import_executeCommand.executeCommand)({
    dockerId: application.destinationDockerId,
    command: tagCommand
  });
  await (0, import_executeCommand.executeCommand)({
    dockerId: application.destinationDockerId,
    command: pushCommand
  });
}
async function getContainerUsage(dockerId, container) {
  try {
    const { stdout } = await (0, import_executeCommand.executeCommand)({
      dockerId,
      command: `docker container stats ${container} --no-stream --no-trunc --format "{{json .}}"`
    });
    return JSON.parse(stdout);
  } catch (err) {
    return {
      MemUsage: 0,
      CPUPerc: 0,
      NetIO: 0
    };
  }
}
function fixType(type) {
  return type?.replaceAll(" ", "").toLowerCase() || null;
}
const compareSemanticVersions = (a, b) => {
  const a1 = a.split(".");
  const b1 = b.split(".");
  const len = Math.min(a1.length, b1.length);
  for (let i = 0; i < len; i++) {
    const a2 = +a1[i] || 0;
    const b2 = +b1[i] || 0;
    if (a2 !== b2) {
      return a2 > b2 ? 1 : -1;
    }
  }
  return b1.length - a1.length;
};
async function getTags(type) {
  try {
    if (type) {
      const tagsPath = isDev ? "./tags.json" : "/app/tags.json";
      const data = await import_promises.default.readFile(tagsPath, "utf8");
      let tags = JSON.parse(data);
      if (tags) {
        tags = tags.find((tag) => tag.name.includes(type));
        tags.tags = tags.tags.sort(compareSemanticVersions).reverse();
        return tags;
      }
    }
  } catch (error) {
    return [];
  }
}
function makeLabelForServices(type) {
  return [
    "coolify.managed=true",
    `coolify.version=${version}`,
    `coolify.type=service`,
    `coolify.service.type=${type}`
  ];
}
const asyncSleep = (delay) => new Promise((resolve) => setTimeout(resolve, delay));
async function startTraefikTCPProxy(destinationDocker, id, publicPort, privatePort, type) {
  const { network, id: dockerId, remoteEngine } = destinationDocker;
  const container = `${id}-${publicPort}`;
  const { found } = await (0, import_docker.checkContainer)({ dockerId, container, remove: true });
  const { ipv4, ipv6 } = await listSettings();
  let dependentId = id;
  if (type === "wordpressftp")
    dependentId = `${id}-ftp`;
  const { found: foundDependentContainer } = await (0, import_docker.checkContainer)({
    dockerId,
    container: dependentId,
    remove: true
  });
  if (foundDependentContainer && !found) {
    const { stdout: Config } = await (0, import_executeCommand.executeCommand)({
      dockerId,
      command: `docker network inspect ${network} --format '{{json .IPAM.Config }}'`
    });
    const ip = JSON.parse(Config)[0].Gateway;
    let traefikUrl = otherTraefikEndpoint;
    if (remoteEngine) {
      let ip2 = null;
      if (isDev) {
        ip2 = getAPIUrl();
      } else {
        ip2 = `http://${ipv4 || ipv6}:3000`;
      }
      traefikUrl = `${ip2}/webhooks/traefik/other.json`;
    }
    const tcpProxy = {
      version: "3.8",
      services: {
        [`${id}-${publicPort}`]: {
          container_name: container,
          image: defaultTraefikImage,
          command: [
            `--entrypoints.tcp.address=:${publicPort}`,
            `--entryPoints.tcp.forwardedHeaders.insecure=true`,
            `--providers.http.endpoint=${traefikUrl}?id=${id}&privatePort=${privatePort}&publicPort=${publicPort}&type=tcp&address=${dependentId}`,
            "--providers.http.pollTimeout=10s",
            "--log.level=error"
          ],
          ports: [`${publicPort}:${publicPort}`],
          extra_hosts: ["host.docker.internal:host-gateway", `host.docker.internal: ${ip}`],
          volumes: ["/var/run/docker.sock:/var/run/docker.sock"],
          networks: ["coolify-infra", network]
        }
      },
      networks: {
        [network]: {
          external: false,
          name: network
        },
        "coolify-infra": {
          external: false,
          name: "coolify-infra"
        }
      }
    };
    await import_promises.default.writeFile(`/tmp/docker-compose-${id}.yaml`, import_js_yaml.default.dump(tcpProxy));
    await (0, import_executeCommand.executeCommand)({
      dockerId,
      command: `docker compose -f /tmp/docker-compose-${id}.yaml up -d`
    });
    await import_promises.default.rm(`/tmp/docker-compose-${id}.yaml`);
  }
  if (!foundDependentContainer && found) {
    await (0, import_executeCommand.executeCommand)({
      dockerId,
      command: `docker stop -t 0 ${container} && docker rm ${container}`,
      shell: true
    });
  }
}
async function startTraefikProxy(id) {
  const { engine, network, remoteEngine, remoteIpAddress } = await import_prisma.prisma.destinationDocker.findUnique({ where: { id } });
  const { found } = await (0, import_docker.checkContainer)({
    dockerId: id,
    container: "coolify-proxy",
    remove: true
  });
  const { id: settingsId, ipv4, ipv6 } = await listSettings();
  if (!found) {
    const { stdout: coolifyNetwork } = await (0, import_executeCommand.executeCommand)({
      dockerId: id,
      command: `docker network ls --filter 'name=coolify-infra' --no-trunc --format "{{json .}}"`
    });
    if (!coolifyNetwork) {
      await (0, import_executeCommand.executeCommand)({
        dockerId: id,
        command: `docker network create --attachable coolify-infra`
      });
    }
    const { stdout: Config } = await (0, import_executeCommand.executeCommand)({
      dockerId: id,
      command: `docker network inspect ${network} --format '{{json .IPAM.Config }}'`
    });
    const ip = JSON.parse(Config)[0].Gateway;
    let traefikUrl = mainTraefikEndpoint;
    if (remoteEngine) {
      let ip2 = null;
      if (isDev) {
        ip2 = getAPIUrl();
      } else {
        ip2 = `http://${ipv4 || ipv6}:3000`;
      }
      traefikUrl = `${ip2}/webhooks/traefik/remote/${id}`;
    }
    await (0, import_executeCommand.executeCommand)({
      dockerId: id,
      command: `docker run --restart always 			--add-host 'host.docker.internal:host-gateway' 			${ip ? `--add-host 'host.docker.internal:${ip}'` : ""} 			-v coolify-traefik-letsencrypt:/etc/traefik/acme 			-v /var/run/docker.sock:/var/run/docker.sock 			--network coolify-infra 			-p "80:80" 			-p "443:443" 			--name coolify-proxy 			-d ${defaultTraefikImage} 			--entrypoints.web.address=:80 			--entrypoints.web.forwardedHeaders.insecure=true 			--entrypoints.websecure.address=:443 			--entrypoints.websecure.forwardedHeaders.insecure=true 			--providers.docker=true 			--providers.docker.exposedbydefault=false 			--providers.http.endpoint=${traefikUrl} 			--providers.http.pollTimeout=5s 			--certificatesresolvers.letsencrypt.acme.httpchallenge=true 			--certificatesresolvers.letsencrypt.acme.storage=/etc/traefik/acme/acme.json 			--certificatesresolvers.letsencrypt.acme.httpchallenge.entrypoint=web 			--log.level=error`
    });
    await import_prisma.prisma.destinationDocker.update({
      where: { id },
      data: { isCoolifyProxyUsed: true }
    });
  }
  if (engine) {
    const destinations = await import_prisma.prisma.destinationDocker.findMany({ where: { engine } });
    for (const destination of destinations) {
      await configureNetworkTraefikProxy(destination);
    }
  }
  if (remoteEngine) {
    const destinations = await import_prisma.prisma.destinationDocker.findMany({ where: { remoteIpAddress } });
    for (const destination of destinations) {
      await configureNetworkTraefikProxy(destination);
    }
  }
}
async function configureNetworkTraefikProxy(destination) {
  const { id } = destination;
  const { stdout: networks } = await (0, import_executeCommand.executeCommand)({
    dockerId: id,
    command: `docker ps -a --filter name=coolify-proxy --format '{{json .Networks}}'`
  });
  const configuredNetworks = networks.replace(/"/g, "").replace("\n", "").split(",");
  if (!configuredNetworks.includes(destination.network)) {
    await (0, import_executeCommand.executeCommand)({
      dockerId: destination.id,
      command: `docker network connect ${destination.network} coolify-proxy`
    });
  }
}
async function stopTraefikProxy(id) {
  const { found } = await (0, import_docker.checkContainer)({ dockerId: id, container: "coolify-proxy" });
  await import_prisma.prisma.destinationDocker.update({
    where: { id },
    data: { isCoolifyProxyUsed: false }
  });
  if (found) {
    return await (0, import_executeCommand.executeCommand)({
      dockerId: id,
      command: `docker stop -t 0 coolify-proxy && docker rm coolify-proxy`,
      shell: true
    });
  }
  return { stdout: "", stderr: "" };
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  asyncSleep,
  base64Decode,
  base64Encode,
  checkDomainsIsValidInDNS,
  checkExposedPort,
  cleanupDB,
  comparePassword,
  configureNetworkTraefikProxy,
  createDirectories,
  decrypt,
  decryptApplication,
  defaultTraefikImage,
  encrypt,
  fixType,
  generateRangeArray,
  generateSecrets,
  generateTimestamp,
  getAPIUrl,
  getContainerUsage,
  getCurrentUser,
  getDomain,
  getFreeExposedPort,
  getTags,
  getTeamInvitation,
  getTemplates,
  getUIUrl,
  hashPassword,
  isARM,
  isDev,
  isDomainConfigured,
  listSettings,
  makeLabelForServices,
  pushToRegistry,
  removeService,
  saveDockerRegistryCredentials,
  scanningTemplates,
  sentryDSN,
  setDefaultConfiguration,
  startTraefikProxy,
  startTraefikTCPProxy,
  stopTraefikProxy,
  uniqueName,
  version
});
