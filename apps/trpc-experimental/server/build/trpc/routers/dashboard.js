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
var dashboard_exports = {};
__export(dashboard_exports, {
  dashboardRouter: () => dashboardRouter
});
module.exports = __toCommonJS(dashboard_exports);
var import_trpc = require("../trpc");
var import_common = require("../../lib/common");
var import_prisma = require("../../prisma");
const dashboardRouter = (0, import_trpc.router)({
  resources: import_trpc.privateProcedure.query(async ({ ctx }) => {
    const id = ctx.user?.teamId === "0" ? void 0 : ctx.user?.teamId;
    let applications = await import_prisma.prisma.application.findMany({
      where: { teams: { some: { id } } },
      include: { settings: true, destinationDocker: true, teams: true }
    });
    const databases = await import_prisma.prisma.database.findMany({
      where: { teams: { some: { id } } },
      include: { settings: true, destinationDocker: true, teams: true }
    });
    const services = await import_prisma.prisma.service.findMany({
      where: { teams: { some: { id } } },
      include: { destinationDocker: true, teams: true }
    });
    const gitSources = await import_prisma.prisma.gitSource.findMany({
      where: {
        OR: [{ teams: { some: { id } } }, { isSystemWide: true }]
      },
      include: { teams: true }
    });
    const destinations = await import_prisma.prisma.destinationDocker.findMany({
      where: { teams: { some: { id } } },
      include: { teams: true }
    });
    const settings = await (0, import_common.listSettings)();
    let foundUnconfiguredApplication = false;
    for (const application of applications) {
      if ((!application.buildPack || !application.branch) && !application.simpleDockerfile || !application.destinationDockerId || !application.settings?.isBot && !application?.fqdn && application.buildPack !== "compose") {
        foundUnconfiguredApplication = true;
      }
    }
    let foundUnconfiguredService = false;
    for (const service of services) {
      if (!service.fqdn) {
        foundUnconfiguredService = true;
      }
    }
    let foundUnconfiguredDatabase = false;
    for (const database of databases) {
      if (!database.version) {
        foundUnconfiguredDatabase = true;
      }
    }
    return {
      foundUnconfiguredApplication,
      foundUnconfiguredDatabase,
      foundUnconfiguredService,
      applications,
      databases,
      services,
      gitSources,
      destinations,
      settings
    };
  })
});
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  dashboardRouter
});
