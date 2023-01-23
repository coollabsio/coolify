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
var sources_exports = {};
__export(sources_exports, {
  sourcesRouter: () => sourcesRouter
});
module.exports = __toCommonJS(sources_exports);
var import_zod = require("zod");
var import_trpc = require("../../trpc");
var import_common = require("../../../lib/common");
var import_prisma = require("../../../prisma");
var import_cuid = __toESM(require("cuid"));
const sourcesRouter = (0, import_trpc.router)({
  save: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string(),
      name: import_zod.z.string(),
      htmlUrl: import_zod.z.string(),
      apiUrl: import_zod.z.string(),
      customPort: import_zod.z.number(),
      customUser: import_zod.z.string(),
      isSystemWide: import_zod.z.boolean().default(false)
    })
  ).mutation(async ({ input, ctx }) => {
    let { id, name, htmlUrl, apiUrl, customPort, customUser, isSystemWide } = input;
    if (customPort)
      customPort = Number(customPort);
    await import_prisma.prisma.gitSource.update({
      where: { id },
      data: { name, htmlUrl, apiUrl, customPort, customUser, isSystemWide }
    });
  }),
  newGitHubApp: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string(),
      name: import_zod.z.string(),
      htmlUrl: import_zod.z.string(),
      apiUrl: import_zod.z.string(),
      organization: import_zod.z.string(),
      customPort: import_zod.z.number(),
      isSystemWide: import_zod.z.boolean().default(false)
    })
  ).mutation(async ({ ctx, input }) => {
    const { teamId } = ctx.user;
    let { id, name, htmlUrl, apiUrl, organization, customPort, isSystemWide } = input;
    if (customPort)
      customPort = Number(customPort);
    if (id === "new") {
      const newId = (0, import_cuid.default)();
      await import_prisma.prisma.gitSource.create({
        data: {
          id: newId,
          name,
          htmlUrl,
          apiUrl,
          organization,
          customPort,
          isSystemWide,
          type: "github",
          teams: { connect: { id: teamId } }
        }
      });
      return {
        id: newId
      };
    }
    return null;
  }),
  newGitLabApp: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string(),
      type: import_zod.z.string(),
      name: import_zod.z.string(),
      htmlUrl: import_zod.z.string(),
      apiUrl: import_zod.z.string(),
      oauthId: import_zod.z.number(),
      appId: import_zod.z.string(),
      appSecret: import_zod.z.string(),
      groupName: import_zod.z.string().optional().nullable(),
      customPort: import_zod.z.number().optional().nullable(),
      customUser: import_zod.z.string().optional().nullable()
    })
  ).mutation(async ({ input, ctx }) => {
    const { teamId } = ctx.user;
    let {
      id,
      type,
      name,
      htmlUrl,
      apiUrl,
      oauthId,
      appId,
      appSecret,
      groupName,
      customPort,
      customUser
    } = input;
    if (oauthId)
      oauthId = Number(oauthId);
    if (customPort)
      customPort = Number(customPort);
    const encryptedAppSecret = (0, import_common.encrypt)(appSecret);
    if (id === "new") {
      const newId = (0, import_cuid.default)();
      await import_prisma.prisma.gitSource.create({
        data: {
          id: newId,
          type,
          apiUrl,
          htmlUrl,
          name,
          customPort,
          customUser,
          teams: { connect: { id: teamId } }
        }
      });
      await import_prisma.prisma.gitlabApp.create({
        data: {
          teams: { connect: { id: teamId } },
          appId,
          oauthId,
          groupName,
          appSecret: encryptedAppSecret,
          gitSource: { connect: { id: newId } }
        }
      });
      return {
        status: 201,
        id: newId
      };
    } else {
      await import_prisma.prisma.gitSource.update({
        where: { id },
        data: { type, apiUrl, htmlUrl, name, customPort, customUser }
      });
      await import_prisma.prisma.gitlabApp.update({
        where: { id },
        data: {
          appId,
          oauthId,
          groupName,
          appSecret: encryptedAppSecret
        }
      });
    }
  }),
  delete: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string()
    })
  ).mutation(async ({ input, ctx }) => {
    const { id } = input;
    const source = await import_prisma.prisma.gitSource.delete({
      where: { id },
      include: { githubApp: true, gitlabApp: true }
    });
    if (source.githubAppId) {
      await import_prisma.prisma.githubApp.delete({ where: { id: source.githubAppId } });
    }
    if (source.gitlabAppId) {
      await import_prisma.prisma.gitlabApp.delete({ where: { id: source.gitlabAppId } });
    }
  }),
  getSourceById: import_trpc.privateProcedure.input(
    import_zod.z.object({
      id: import_zod.z.string()
    })
  ).query(async ({ input, ctx }) => {
    const { id } = input;
    const { teamId } = ctx.user;
    const settings = await import_prisma.prisma.setting.findFirst({});
    if (id === "new") {
      return {
        source: {
          name: null,
          type: null,
          htmlUrl: null,
          apiUrl: null,
          organization: null,
          customPort: 22,
          customUser: "git"
        },
        settings
      };
    }
    const source = await import_prisma.prisma.gitSource.findFirst({
      where: {
        id,
        OR: [
          { teams: { some: { id: teamId === "0" ? void 0 : teamId } } },
          { isSystemWide: true }
        ]
      },
      include: { githubApp: true, gitlabApp: true }
    });
    if (!source) {
      throw { status: 404, message: "Source not found." };
    }
    if (source?.githubApp?.clientSecret)
      source.githubApp.clientSecret = (0, import_common.decrypt)(source.githubApp.clientSecret);
    if (source?.githubApp?.webhookSecret)
      source.githubApp.webhookSecret = (0, import_common.decrypt)(source.githubApp.webhookSecret);
    if (source?.githubApp?.privateKey)
      source.githubApp.privateKey = (0, import_common.decrypt)(source.githubApp.privateKey);
    if (source?.gitlabApp?.appSecret)
      source.gitlabApp.appSecret = (0, import_common.decrypt)(source.gitlabApp.appSecret);
    return {
      success: true,
      data: {
        source,
        settings
      }
    };
  })
});
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  sourcesRouter
});
