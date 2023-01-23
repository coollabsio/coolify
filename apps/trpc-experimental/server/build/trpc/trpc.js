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
var trpc_exports = {};
__export(trpc_exports, {
  privateProcedure: () => privateProcedure,
  publicProcedure: () => publicProcedure,
  router: () => router
});
module.exports = __toCommonJS(trpc_exports);
var import_server = require("@trpc/server");
var import_superjson = __toESM(require("superjson"));
const t = import_server.initTRPC.context().create({
  transformer: import_superjson.default,
  errorFormatter({ shape }) {
    return shape;
  }
});
const logger = t.middleware(async ({ path, type, next }) => {
  const start = Date.now();
  const result = await next();
  const durationMs = Date.now() - start;
  result.ok ? console.log("OK request timing:", { path, type, durationMs }) : console.log("Non-OK request timing", { path, type, durationMs });
  return result;
});
const isAdmin = t.middleware(async ({ ctx, next }) => {
  if (!ctx.user) {
    throw new import_server.TRPCError({ code: "UNAUTHORIZED" });
  }
  return next({
    ctx: {
      user: ctx.user
    }
  });
});
const router = t.router;
const privateProcedure = t.procedure.use(isAdmin);
const publicProcedure = t.procedure;
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  privateProcedure,
  publicProcedure,
  router
});
