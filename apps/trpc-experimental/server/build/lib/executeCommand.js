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
var executeCommand_exports = {};
__export(executeCommand_exports, {
  createRemoteEngineConfiguration: () => createRemoteEngineConfiguration,
  executeCommand: () => executeCommand
});
module.exports = __toCommonJS(executeCommand_exports);
var import_prisma = require("../prisma");
var import_os = __toESM(require("os"));
var import_promises = __toESM(require("fs/promises"));
var import_ssh_config = __toESM(require("ssh-config"));
var import_ssh = require("./ssh");
var import_env = require("../env");
var import_logging = require("./logging");
var import_common = require("./common");
async function executeCommand({
  command,
  dockerId = null,
  sshCommand = false,
  shell = false,
  stream = false,
  buildId,
  applicationId,
  debug
}) {
  const { execa, execaCommand } = await import("execa");
  const { parse } = await import("shell-quote");
  const parsedCommand = parse(command);
  const dockerCommand = parsedCommand[0];
  const dockerArgs = parsedCommand.slice(1);
  if (dockerId && dockerCommand && dockerArgs) {
    const destinationDocker = await import_prisma.prisma.destinationDocker.findUnique({
      where: { id: dockerId }
    });
    if (!destinationDocker) {
      throw new Error("Destination docker not found");
    }
    let { remoteEngine, remoteIpAddress, engine } = destinationDocker;
    if (remoteEngine) {
      await createRemoteEngineConfiguration(dockerId);
      engine = `ssh://${remoteIpAddress}-remote`;
    } else {
      engine = "unix:///var/run/docker.sock";
    }
    if (import_env.env.CODESANDBOX_HOST) {
      if (command.startsWith("docker compose")) {
        command = command.replace(/docker compose/gi, "docker-compose");
      }
    }
    if (sshCommand) {
      if (shell) {
        return execaCommand(`ssh ${remoteIpAddress}-remote ${command}`);
      }
      return await execa("ssh", [`${remoteIpAddress}-remote`, dockerCommand, ...dockerArgs]);
    }
    if (stream) {
      return await new Promise(async (resolve, reject) => {
        let subprocess = null;
        if (shell) {
          subprocess = execaCommand(command, {
            env: { DOCKER_BUILDKIT: "1", DOCKER_HOST: engine }
          });
        } else {
          subprocess = execa(dockerCommand, dockerArgs, {
            env: { DOCKER_BUILDKIT: "1", DOCKER_HOST: engine }
          });
        }
        const logs = [];
        if (subprocess && subprocess.stdout && subprocess.stderr) {
          subprocess.stdout.on("data", async (data) => {
            const stdout = data.toString();
            const array = stdout.split("\n");
            for (const line of array) {
              if (line !== "\n" && line !== "") {
                const log = {
                  line: `${line.replace("\n", "")}`,
                  buildId,
                  applicationId
                };
                logs.push(log);
                if (debug) {
                  await (0, import_logging.saveBuildLog)(log);
                }
              }
            }
          });
          subprocess.stderr.on("data", async (data) => {
            const stderr = data.toString();
            const array = stderr.split("\n");
            for (const line of array) {
              if (line !== "\n" && line !== "") {
                const log = {
                  line: `${line.replace("\n", "")}`,
                  buildId,
                  applicationId
                };
                logs.push(log);
                if (debug) {
                  await (0, import_logging.saveBuildLog)(log);
                }
              }
            }
          });
          subprocess.on("exit", async (code) => {
            if (code === 0) {
              resolve(code);
            } else {
              if (!debug) {
                for (const log of logs) {
                  await (0, import_logging.saveBuildLog)(log);
                }
              }
              reject(code);
            }
          });
        }
      });
    } else {
      if (shell) {
        return await execaCommand(command, {
          env: { DOCKER_BUILDKIT: "1", DOCKER_HOST: engine }
        });
      } else {
        return await execa(dockerCommand, dockerArgs, {
          env: { DOCKER_BUILDKIT: "1", DOCKER_HOST: engine }
        });
      }
    }
  } else {
    if (shell) {
      return execaCommand(command, { shell: true });
    }
    return await execa(dockerCommand, dockerArgs);
  }
}
async function createRemoteEngineConfiguration(id) {
  const homedir = import_os.default.homedir();
  const sshKeyFile = `/tmp/id_rsa-${id}`;
  const localPort = await (0, import_ssh.getFreeSSHLocalPort)(id);
  const {
    sshKey: { privateKey },
    network,
    remoteIpAddress,
    remotePort,
    remoteUser
  } = await import_prisma.prisma.destinationDocker.findFirst({ where: { id }, include: { sshKey: true } });
  await import_promises.default.writeFile(sshKeyFile, (0, import_common.decrypt)(privateKey) + "\n", { encoding: "utf8", mode: 400 });
  const config = import_ssh_config.default.parse("");
  const Host = `${remoteIpAddress}-remote`;
  try {
    await executeCommand({ command: `ssh-keygen -R ${Host}` });
    await executeCommand({ command: `ssh-keygen -R ${remoteIpAddress}` });
    await executeCommand({ command: `ssh-keygen -R localhost:${localPort}` });
  } catch (error) {
  }
  const found = config.find({ Host });
  const foundIp = config.find({ Host: remoteIpAddress });
  if (found)
    config.remove({ Host });
  if (foundIp)
    config.remove({ Host: remoteIpAddress });
  config.append({
    Host,
    Hostname: remoteIpAddress,
    Port: remotePort.toString(),
    User: remoteUser,
    StrictHostKeyChecking: "no",
    IdentityFile: sshKeyFile,
    ControlMaster: "auto",
    ControlPath: `${homedir}/.ssh/coolify-${remoteIpAddress}-%r@%h:%p`,
    ControlPersist: "10m"
  });
  try {
    await import_promises.default.stat(`${homedir}/.ssh/`);
  } catch (error) {
    await import_promises.default.mkdir(`${homedir}/.ssh/`);
  }
  return await import_promises.default.writeFile(`${homedir}/.ssh/config`, import_ssh_config.default.stringify(config));
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  createRemoteEngineConfiguration,
  executeCommand
});
