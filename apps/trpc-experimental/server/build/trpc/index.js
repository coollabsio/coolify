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
var trpc_exports = {};
__export(trpc_exports, {
  appRouter: () => appRouter
});
module.exports = __toCommonJS(trpc_exports);
var import_trpc = require("./trpc");
var import_routers = require("./routers");
const appRouter = (0, import_trpc.router)({
  settings: import_routers.settingsRouter,
  auth: import_routers.authRouter,
  dashboard: import_routers.dashboardRouter,
  applications: import_routers.applicationsRouter,
  services: import_routers.servicesRouter,
  databases: import_routers.databasesRouter,
  sources: import_routers.sourcesRouter,
  destinations: import_routers.destinationsRouter
});
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  appRouter
});
