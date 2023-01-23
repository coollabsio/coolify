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
var gitlab_exports = {};
__export(gitlab_exports, {
  default: () => gitlab_default
});
module.exports = __toCommonJS(gitlab_exports);
var import_common = require("../buildPacks/common");
var import_executeCommand = require("../executeCommand");
async function gitlab_default({
  applicationId,
  workdir,
  repodir,
  htmlUrl,
  gitCommitHash,
  repository,
  branch,
  buildId,
  privateSshKey,
  customPort,
  forPublic,
  customUser
}) {
  const url = htmlUrl.replace("https://", "").replace("http://", "").replace(/\/$/, "");
  if (!forPublic) {
    await (0, import_executeCommand.executeCommand)({ command: `echo '${privateSshKey}' > ${repodir}/id.rsa`, shell: true });
    await (0, import_executeCommand.executeCommand)({ command: `chmod 600 ${repodir}/id.rsa` });
  }
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
  if (forPublic) {
    await (0, import_executeCommand.executeCommand)(
      {
        command: `git clone -q -b ${branch} https://${url}/${repository}.git ${workdir}/ && cd ${workdir}/ && git checkout ${gitCommitHash || ""} && git submodule update --init --recursive && git lfs pull && cd .. `,
        shell: true
      }
    );
  } else {
    await (0, import_executeCommand.executeCommand)(
      {
        command: `git clone -q -b ${branch} ${customUser}@${url}:${repository}.git --config core.sshCommand="ssh -p ${customPort} -q -i ${repodir}id.rsa -o StrictHostKeyChecking=no" ${workdir}/ && cd ${workdir}/ && git checkout ${gitCommitHash || ""} && git submodule update --init --recursive && git lfs pull && cd .. `,
        shell: true
      }
    );
  }
  const { stdout: commit } = await (0, import_executeCommand.executeCommand)({ command: `cd ${workdir}/ && git rev-parse HEAD`, shell: true });
  return commit.replace("\n", "");
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {});
