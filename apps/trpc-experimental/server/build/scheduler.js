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
var scheduler_exports = {};
__export(scheduler_exports, {
  isDev: () => isDev,
  scheduler: () => scheduler
});
module.exports = __toCommonJS(scheduler_exports);
var import_bree = __toESM(require("bree"));
var import_path = __toESM(require("path"));
var import_ts_worker = __toESM(require("@breejs/ts-worker"));
const isDev = process.env["NODE_ENV"] === "development";
import_bree.default.extend(import_ts_worker.default);
const options = {
  defaultExtension: "js",
  logger: false,
  jobs: [{ name: "applicationBuildQueue" }]
};
if (isDev)
  options.root = import_path.default.join(__dirname, "./jobs");
const scheduler = new import_bree.default(options);
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  isDev,
  scheduler
});
