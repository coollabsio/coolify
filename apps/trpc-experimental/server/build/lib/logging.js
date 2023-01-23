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
var logging_exports = {};
__export(logging_exports, {
  saveBuildLog: () => saveBuildLog
});
module.exports = __toCommonJS(logging_exports);
var import_prisma = require("../prisma");
var import_common = require("./common");
var import_dayjs = require("./dayjs");
const saveBuildLog = async ({ line, buildId, applicationId }) => {
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
  const fluentBitUrl = import_common.isDev ? "http://localhost:24224" : "http://coolify-fluentbit:24224";
  if (import_common.isDev) {
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
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  saveBuildLog
});
