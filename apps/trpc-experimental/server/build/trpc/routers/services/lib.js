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
var lib_exports = {};
__export(lib_exports, {
  generatePassword: () => generatePassword,
  getFreePublicPort: () => getFreePublicPort,
  parseAndFindServiceTemplates: () => parseAndFindServiceTemplates,
  persistentVolumes: () => persistentVolumes,
  startServiceContainers: () => startServiceContainers,
  verifyAndDecryptServiceSecrets: () => verifyAndDecryptServiceSecrets
});
module.exports = __toCommonJS(lib_exports);
var import_common = require("../../../lib/common");
var import_bcryptjs = __toESM(require("bcryptjs"));
var import_prisma = require("../../../prisma");
var import_crypto = __toESM(require("crypto"));
var import_executeCommand = require("../../../lib/executeCommand");
async function parseAndFindServiceTemplates(service, workdir, isDeploy = false) {
  const templates = await (0, import_common.getTemplates)();
  const foundTemplate = templates.find((t) => (0, import_common.fixType)(t.type) === service.type);
  let parsedTemplate = {};
  if (foundTemplate) {
    if (!isDeploy) {
      for (const [key, value] of Object.entries(foundTemplate.services)) {
        const realKey = key.replace("$$id", service.id);
        let name = value.name;
        if (!name) {
          if (Object.keys(foundTemplate.services).length === 1) {
            name = foundTemplate.name || service.name.toLowerCase();
          } else {
            if (key === "$$id") {
              name = foundTemplate.name || key.replaceAll("$$id-", "") || service.name.toLowerCase();
            } else {
              name = key.replaceAll("$$id-", "") || service.name.toLowerCase();
            }
          }
        }
        parsedTemplate[realKey] = {
          value,
          name,
          documentation: value.documentation || foundTemplate.documentation || "https://docs.coollabs.io",
          image: value.image,
          files: value?.files,
          environment: [],
          fqdns: [],
          hostPorts: [],
          proxy: {}
        };
        if (value.environment?.length > 0) {
          for (const env of value.environment) {
            let [envKey, ...envValue] = env.split("=");
            envValue = envValue.join("=");
            let variable = null;
            if (foundTemplate?.variables) {
              variable = foundTemplate?.variables.find((v) => v.name === envKey) || foundTemplate?.variables.find((v) => v.id === envValue);
            }
            if (variable) {
              const id = variable.id.replaceAll("$$", "");
              const label = variable?.label;
              const description = variable?.description;
              const defaultValue = variable?.defaultValue;
              const main = variable?.main || "$$id";
              const type = variable?.type || "input";
              const placeholder = variable?.placeholder || "";
              const readOnly = variable?.readOnly || false;
              const required = variable?.required || false;
              if (envValue.startsWith("$$config") || variable?.showOnConfiguration) {
                if (envValue.startsWith("$$config_coolify")) {
                  continue;
                }
                parsedTemplate[realKey].environment.push({
                  id,
                  name: envKey,
                  value: envValue,
                  main,
                  label,
                  description,
                  defaultValue,
                  type,
                  placeholder,
                  required,
                  readOnly
                });
              }
            }
          }
        }
        if (value?.proxy && value.proxy.length > 0) {
          for (const proxyValue of value.proxy) {
            if (proxyValue.domain) {
              const variable = foundTemplate?.variables.find((v) => v.id === proxyValue.domain);
              if (variable) {
                const { id, name: name2, label, description, defaultValue, required = false } = variable;
                const found = await import_prisma.prisma.serviceSetting.findFirst({
                  where: { serviceId: service.id, variableName: proxyValue.domain }
                });
                parsedTemplate[realKey].fqdns.push({
                  id,
                  name: name2,
                  value: found?.value || "",
                  label,
                  description,
                  defaultValue,
                  required
                });
              }
            }
            if (proxyValue.hostPort) {
              const variable = foundTemplate?.variables.find((v) => v.id === proxyValue.hostPort);
              if (variable) {
                const { id, name: name2, label, description, defaultValue, required = false } = variable;
                const found = await import_prisma.prisma.serviceSetting.findFirst({
                  where: { serviceId: service.id, variableName: proxyValue.hostPort }
                });
                parsedTemplate[realKey].hostPorts.push({
                  id,
                  name: name2,
                  value: found?.value || "",
                  label,
                  description,
                  defaultValue,
                  required
                });
              }
            }
          }
        }
      }
    } else {
      parsedTemplate = foundTemplate;
    }
    let strParsedTemplate = JSON.stringify(parsedTemplate);
    strParsedTemplate = strParsedTemplate.replaceAll("$$id", service.id);
    strParsedTemplate = strParsedTemplate.replaceAll(
      "$$core_version",
      service.version || foundTemplate.defaultVersion
    );
    if (workdir) {
      strParsedTemplate = strParsedTemplate.replaceAll("$$workdir", workdir);
    }
    if (service.serviceSetting.length > 0) {
      for (const setting of service.serviceSetting) {
        const { value, variableName } = setting;
        const regex = new RegExp(`\\$\\$config_${variableName.replace("$$config_", "")}"`, "gi");
        if (value === "$$generate_fqdn") {
          strParsedTemplate = strParsedTemplate.replaceAll(regex, service.fqdn + '"' || '"');
        } else if (value === "$$generate_fqdn_slash") {
          strParsedTemplate = strParsedTemplate.replaceAll(regex, service.fqdn + '/"');
        } else if (value === "$$generate_domain") {
          strParsedTemplate = strParsedTemplate.replaceAll(regex, (0, import_common.getDomain)(service.fqdn) + '"');
        } else if (service.destinationDocker?.network && value === "$$generate_network") {
          strParsedTemplate = strParsedTemplate.replaceAll(
            regex,
            service.destinationDocker.network + '"'
          );
        } else {
          strParsedTemplate = strParsedTemplate.replaceAll(regex, value + '"');
        }
      }
    }
    if (service.serviceSecret.length > 0) {
      for (const secret of service.serviceSecret) {
        let { name, value } = secret;
        name = name.toLowerCase();
        const regexHashed = new RegExp(`\\$\\$hashed\\$\\$secret_${name}`, "gi");
        const regex = new RegExp(`\\$\\$secret_${name}`, "gi");
        if (value) {
          strParsedTemplate = strParsedTemplate.replaceAll(
            regexHashed,
            import_bcryptjs.default.hashSync(value.replaceAll('"', '\\"'), 10)
          );
          strParsedTemplate = strParsedTemplate.replaceAll(regex, value.replaceAll('"', '\\"'));
        } else {
          strParsedTemplate = strParsedTemplate.replaceAll(regexHashed, "");
          strParsedTemplate = strParsedTemplate.replaceAll(regex, "");
        }
      }
    }
    parsedTemplate = JSON.parse(strParsedTemplate);
  }
  return parsedTemplate;
}
function generatePassword({
  length = 24,
  symbols = false,
  isHex = false
}) {
  if (isHex) {
    return import_crypto.default.randomBytes(length).toString("hex");
  }
  const password = generator.generate({
    length,
    numbers: true,
    strict: true,
    symbols
  });
  return password;
}
async function getFreePublicPort({ id, remoteEngine, engine, remoteIpAddress }) {
  const { default: isReachable } = await import("is-port-reachable");
  const data = await import_prisma.prisma.setting.findFirst();
  const { minPort, maxPort } = data;
  if (remoteEngine) {
    const dbUsed = await (await import_prisma.prisma.database.findMany({
      where: {
        publicPort: { not: null },
        id: { not: id },
        destinationDocker: { remoteIpAddress }
      },
      select: { publicPort: true }
    })).map((a) => a.publicPort);
    const wpFtpUsed = await (await import_prisma.prisma.wordpress.findMany({
      where: {
        ftpPublicPort: { not: null },
        id: { not: id },
        service: { destinationDocker: { remoteIpAddress } }
      },
      select: { ftpPublicPort: true }
    })).map((a) => a.ftpPublicPort);
    const wpUsed = await (await import_prisma.prisma.wordpress.findMany({
      where: {
        mysqlPublicPort: { not: null },
        id: { not: id },
        service: { destinationDocker: { remoteIpAddress } }
      },
      select: { mysqlPublicPort: true }
    })).map((a) => a.mysqlPublicPort);
    const minioUsed = await (await import_prisma.prisma.minio.findMany({
      where: {
        publicPort: { not: null },
        id: { not: id },
        service: { destinationDocker: { remoteIpAddress } }
      },
      select: { publicPort: true }
    })).map((a) => a.publicPort);
    const usedPorts = [...dbUsed, ...wpFtpUsed, ...wpUsed, ...minioUsed];
    const range = (0, import_common.generateRangeArray)(minPort, maxPort);
    const availablePorts = range.filter((port) => !usedPorts.includes(port));
    for (const port of availablePorts) {
      const found = await isReachable(port, { host: remoteIpAddress });
      if (!found) {
        return port;
      }
    }
    return false;
  } else {
    const dbUsed = await (await import_prisma.prisma.database.findMany({
      where: { publicPort: { not: null }, id: { not: id }, destinationDocker: { engine } },
      select: { publicPort: true }
    })).map((a) => a.publicPort);
    const wpFtpUsed = await (await import_prisma.prisma.wordpress.findMany({
      where: {
        ftpPublicPort: { not: null },
        id: { not: id },
        service: { destinationDocker: { engine } }
      },
      select: { ftpPublicPort: true }
    })).map((a) => a.ftpPublicPort);
    const wpUsed = await (await import_prisma.prisma.wordpress.findMany({
      where: {
        mysqlPublicPort: { not: null },
        id: { not: id },
        service: { destinationDocker: { engine } }
      },
      select: { mysqlPublicPort: true }
    })).map((a) => a.mysqlPublicPort);
    const minioUsed = await (await import_prisma.prisma.minio.findMany({
      where: {
        publicPort: { not: null },
        id: { not: id },
        service: { destinationDocker: { engine } }
      },
      select: { publicPort: true }
    })).map((a) => a.publicPort);
    const usedPorts = [...dbUsed, ...wpFtpUsed, ...wpUsed, ...minioUsed];
    const range = (0, import_common.generateRangeArray)(minPort, maxPort);
    const availablePorts = range.filter((port) => !usedPorts.includes(port));
    for (const port of availablePorts) {
      const found = await isReachable(port, { host: "localhost" });
      if (!found) {
        return port;
      }
    }
    return false;
  }
}
async function verifyAndDecryptServiceSecrets(id) {
  const secrets = await import_prisma.prisma.serviceSecret.findMany({ where: { serviceId: id } });
  let decryptedSecrets = secrets.map((secret) => {
    const { name, value } = secret;
    if (value) {
      let rawValue = (0, import_common.decrypt)(value);
      rawValue = rawValue.replaceAll(/\$/gi, "$$$");
      return { name, value: rawValue };
    }
    return { name, value };
  });
  return decryptedSecrets;
}
function persistentVolumes(id, persistentStorage, config) {
  let volumeSet = /* @__PURE__ */ new Set();
  if (Object.keys(config).length > 0) {
    for (const [key, value] of Object.entries(config)) {
      if (value.volumes) {
        for (const volume of value.volumes) {
          if (!volume.startsWith("/")) {
            volumeSet.add(volume);
          }
        }
      }
    }
  }
  const volumesArray = Array.from(volumeSet);
  const persistentVolume = persistentStorage?.map((storage) => {
    return `${id}${storage.path.replace(/\//gi, "-")}:${storage.path}`;
  }) || [];
  let volumes = [...persistentVolume];
  if (volumesArray)
    volumes = [...volumesArray, ...volumes];
  const composeVolumes = volumes.length > 0 && volumes.map((volume) => {
    return {
      [`${volume.split(":")[0]}`]: {
        name: volume.split(":")[0]
      }
    };
  }) || [];
  const volumeMounts = Object.assign({}, ...composeVolumes) || {};
  return { volumeMounts };
}
async function startServiceContainers(fastify, id, teamId, dockerId, composeFileDestination) {
  try {
    await (0, import_executeCommand.executeCommand)({ dockerId, command: `docker compose -f ${composeFileDestination} pull` });
  } catch (error) {
  }
  await (0, import_executeCommand.executeCommand)({ dockerId, command: `docker compose -f ${composeFileDestination} build --no-cache` });
  await (0, import_executeCommand.executeCommand)({ dockerId, command: `docker compose -f ${composeFileDestination} create` });
  await (0, import_executeCommand.executeCommand)({ dockerId, command: `docker compose -f ${composeFileDestination} start` });
  await (0, import_common.asyncSleep)(1e3);
  await (0, import_executeCommand.executeCommand)({ dockerId, command: `docker compose -f ${composeFileDestination} up -d` });
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  generatePassword,
  getFreePublicPort,
  parseAndFindServiceTemplates,
  persistentVolumes,
  startServiceContainers,
  verifyAndDecryptServiceSecrets
});
