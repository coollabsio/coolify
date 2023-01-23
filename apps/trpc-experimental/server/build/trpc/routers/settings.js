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
var settings_exports = {};
__export(settings_exports, {
  settingsRouter: () => settingsRouter
});
module.exports = __toCommonJS(settings_exports);
var import_trpc = require("../trpc");
var import_server = require("@trpc/server");
var import_common = require("../../lib/common");
var import_env = require("../../env");
var import_jsonwebtoken = __toESM(require("jsonwebtoken"));
const settingsRouter = (0, import_trpc.router)({
  getBaseSettings: import_trpc.publicProcedure.query(async () => {
    const settings = await (0, import_common.listSettings)();
    return {
      success: true,
      data: {
        isRegistrationEnabled: settings?.isRegistrationEnabled
      }
    };
  }),
  getInstanceSettings: import_trpc.privateProcedure.query(async ({ ctx }) => {
    try {
      const settings = await (0, import_common.listSettings)();
      let isAdmin = false;
      let permission = null;
      let token = null;
      let pendingInvitations = [];
      if (!settings) {
        throw new import_server.TRPCError({
          code: "INTERNAL_SERVER_ERROR",
          message: "An unexpected error occurred, please try again later."
        });
      }
      if (ctx.user) {
        const currentUser = await (0, import_common.getCurrentUser)(ctx.user.userId);
        if (currentUser) {
          const foundPermission = currentUser.permission.find(
            (p) => p.teamId === ctx.user?.teamId
          )?.permission;
          if (foundPermission) {
            permission = foundPermission;
            isAdmin = foundPermission === "owner" || foundPermission === "admin";
          }
          const payload = {
            userId: ctx.user?.userId,
            teamId: ctx.user?.teamId,
            permission,
            isAdmin,
            iat: Math.floor(Date.now() / 1e3)
          };
          token = import_jsonwebtoken.default.sign(payload, import_env.env.COOLIFY_SECRET_KEY);
        }
        pendingInvitations = await (0, import_common.getTeamInvitation)(ctx.user.userId);
      }
      return {
        success: true,
        data: {
          token,
          userId: ctx.user?.userId,
          teamId: ctx.user?.teamId,
          permission,
          isAdmin,
          ipv4: ctx.user?.teamId ? settings.ipv4 : null,
          ipv6: ctx.user?.teamId ? settings.ipv6 : null,
          version: import_common.version,
          whiteLabeled: import_env.env.COOLIFY_WHITE_LABELED === "true",
          whiteLabeledIcon: import_env.env.COOLIFY_WHITE_LABELED_ICON,
          isRegistrationEnabled: settings.isRegistrationEnabled,
          pendingInvitations
        }
      };
    } catch (error) {
      throw new import_server.TRPCError({
        code: "INTERNAL_SERVER_ERROR",
        message: "An unexpected error occurred, please try again later.",
        cause: error
      });
    }
  })
});
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  settingsRouter
});
