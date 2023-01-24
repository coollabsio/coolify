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
var github_exports = {};
__export(github_exports, {
  default: () => github_default
});
module.exports = __toCommonJS(github_exports);
var import_jsonwebtoken = __toESM(require("jsonwebtoken"));
var import_prisma = require("../../prisma");
var import_common = require("../buildPacks/common");
var import_common2 = require("../common");
var import_executeCommand = require("../executeCommand");
async function github_default({
  applicationId,
  workdir,
  githubAppId,
  repository,
  apiUrl,
  gitCommitHash,
  htmlUrl,
  branch,
  buildId,
  customPort,
  forPublic
}) {
  const { default: got } = await import("got");
  const url = htmlUrl.replace("https://", "").replace("http://", "");
  if (forPublic) {
    await (0, import_common.saveBuildLog)({
      line: `Cloning ${repository}:${branch}...`,
      buildId,
      applicationId
    });
    if (gitCommitHash) {
      await (0, import_common.saveBuildLog)({
        line: `Checking out ${gitCommitHash} commit...`,
        buildId,
        applicationId
      });
    }
    await (0, import_executeCommand.executeCommand)({
      command: `git clone -q -b ${branch} https://${url}/${repository}.git ${workdir}/ && cd ${workdir} && git checkout ${gitCommitHash || ""} && git submodule update --init --recursive && git lfs pull && cd .. `,
      shell: true
    });
  } else {
    const body = await import_prisma.prisma.githubApp.findUnique({ where: { id: githubAppId } });
    if (body.privateKey)
      body.privateKey = (0, import_common2.decrypt)(body.privateKey);
    const { privateKey, appId, installationId } = body;
    const githubPrivateKey = privateKey.replace(/\\n/g, "\n").replace(/"/g, "");
    const payload = {
      iat: Math.round(new Date().getTime() / 1e3),
      exp: Math.round(new Date().getTime() / 1e3 + 60),
      iss: appId
    };
    const jwtToken = import_jsonwebtoken.default.sign(payload, githubPrivateKey, {
      algorithm: "RS256"
    });
    const { token } = await got.post(`${apiUrl}/app/installations/${installationId}/access_tokens`, {
      headers: {
        Authorization: `Bearer ${jwtToken}`,
        Accept: "application/vnd.github.machine-man-preview+json"
      }
    }).json();
    await (0, import_common.saveBuildLog)({
      line: `Cloning ${repository}:${branch}...`,
      buildId,
      applicationId
    });
    if (gitCommitHash) {
      await (0, import_common.saveBuildLog)({
        line: `Checking out ${gitCommitHash} commit...`,
        buildId,
        applicationId
      });
    }
    await (0, import_executeCommand.executeCommand)({
      command: `git clone -q -b ${branch} https://x-access-token:${token}@${url}/${repository}.git --config core.sshCommand="ssh -p ${customPort}" ${workdir}/ && cd ${workdir} && git checkout ${gitCommitHash || ""} && git submodule update --init --recursive && git lfs pull && cd .. `,
      shell: true
    });
  }
  const { stdout: commit } = await (0, import_executeCommand.executeCommand)({ command: `cd ${workdir}/ && git rev-parse HEAD`, shell: true });
  return commit.replace("\n", "");
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {});
