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
var prisma_exports = {};
__export(prisma_exports, {
  prisma: () => prisma
});
module.exports = __toCommonJS(prisma_exports);
var import_env = require("./env");
var import_client = require("@prisma/client");
const prismaGlobal = global;
const prisma = prismaGlobal.prisma || new import_client.PrismaClient({
  log: import_env.env.NODE_ENV !== "development" ? ["query", "error", "warn"] : ["error"]
});
if (import_env.env.NODE_ENV !== "production") {
  prismaGlobal.prisma = prisma;
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  prisma
});
