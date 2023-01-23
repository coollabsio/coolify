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
var heroku_exports = {};
__export(heroku_exports, {
  default: () => heroku_default
});
module.exports = __toCommonJS(heroku_exports);
var import_executeCommand = require("../executeCommand");
var import_common = require("./common");
async function heroku_default(data) {
  const { buildId, applicationId, tag, dockerId, debug, workdir, baseDirectory, baseImage } = data;
  try {
    await (0, import_common.saveBuildLog)({ line: `Building production image...`, buildId, applicationId });
    await (0, import_executeCommand.executeCommand)({
      buildId,
      debug,
      dockerId,
      command: `pack build -p ${workdir}${baseDirectory} ${applicationId}:${tag} --builder ${baseImage}`
    });
  } catch (error) {
    throw error;
  }
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {});
