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
var ssh_exports = {};
__export(ssh_exports, {
  getFreeSSHLocalPort: () => getFreeSSHLocalPort
});
module.exports = __toCommonJS(ssh_exports);
var import_prisma = require("../prisma");
var import_common = require("./common");
async function getFreeSSHLocalPort(id) {
  const { default: isReachable } = await import("is-port-reachable");
  const { remoteIpAddress, sshLocalPort } = await import_prisma.prisma.destinationDocker.findUnique({
    where: { id }
  });
  if (sshLocalPort) {
    return Number(sshLocalPort);
  }
  const data = await import_prisma.prisma.setting.findFirst();
  const { minPort, maxPort } = data;
  const ports = await import_prisma.prisma.destinationDocker.findMany({
    where: { sshLocalPort: { not: null }, remoteIpAddress: { not: remoteIpAddress } }
  });
  const alreadyConfigured = await import_prisma.prisma.destinationDocker.findFirst({
    where: {
      remoteIpAddress,
      id: { not: id },
      sshLocalPort: { not: null }
    }
  });
  if (alreadyConfigured?.sshLocalPort) {
    await import_prisma.prisma.destinationDocker.update({
      where: { id },
      data: { sshLocalPort: alreadyConfigured.sshLocalPort }
    });
    return Number(alreadyConfigured.sshLocalPort);
  }
  const range = (0, import_common.generateRangeArray)(minPort, maxPort);
  const availablePorts = range.filter((port) => !ports.map((p) => p.sshLocalPort).includes(port));
  for (const port of availablePorts) {
    const found = await isReachable(port, { host: "localhost" });
    if (!found) {
      await import_prisma.prisma.destinationDocker.update({
        where: { id },
        data: { sshLocalPort: Number(port) }
      });
      return Number(port);
    }
  }
  return false;
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  getFreeSSHLocalPort
});
