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
var auth_exports = {};
__export(auth_exports, {
  authRouter: () => authRouter
});
module.exports = __toCommonJS(auth_exports);
var import_zod = require("zod");
var import_trpc = require("../trpc");
var import_server = require("@trpc/server");
var import_common = require("../../lib/common");
var import_env = require("../../env");
var import_jsonwebtoken = __toESM(require("jsonwebtoken"));
var import_prisma = require("../../prisma");
var import_cuid = __toESM(require("cuid"));
const authRouter = (0, import_trpc.router)({
  register: import_trpc.publicProcedure.input(
    import_zod.z.object({
      email: import_zod.z.string(),
      password: import_zod.z.string()
    })
  ).mutation(async ({ input }) => {
    const { email, password } = input;
    const userFound = await import_prisma.prisma.user.findUnique({
      where: { email },
      include: { teams: true, permission: true }
    });
    if (userFound) {
      throw new import_server.TRPCError({
        code: "BAD_REQUEST",
        message: "User already exists."
      });
    }
    const settings = await (0, import_common.listSettings)();
    if (!settings?.isRegistrationEnabled) {
      throw new import_server.TRPCError({
        code: "FORBIDDEN",
        message: "Registration is disabled."
      });
    }
    const usersCount = await import_prisma.prisma.user.count();
    const uid = usersCount === 0 ? "0" : (0, import_cuid.default)();
    const permission = "owner";
    const isAdmin = true;
    const hashedPassword = await (0, import_common.hashPassword)(password);
    if (usersCount === 0) {
      await import_prisma.prisma.user.create({
        data: {
          id: uid,
          email,
          password: hashedPassword,
          type: "email",
          teams: {
            create: {
              id: uid,
              name: (0, import_common.uniqueName)(),
              destinationDocker: { connect: { network: "coolify" } }
            }
          },
          permission: { create: { teamId: uid, permission } }
        },
        include: { teams: true }
      });
      await import_prisma.prisma.setting.update({
        where: { id: "0" },
        data: { isRegistrationEnabled: false }
      });
    } else {
      await import_prisma.prisma.user.create({
        data: {
          id: uid,
          email,
          password: hashedPassword,
          type: "email",
          teams: {
            create: {
              id: uid,
              name: (0, import_common.uniqueName)()
            }
          },
          permission: { create: { teamId: uid, permission } }
        },
        include: { teams: true }
      });
    }
    const payload = {
      userId: uid,
      teamId: uid,
      permission,
      isAdmin
    };
    return {
      ...payload,
      token: import_jsonwebtoken.default.sign(payload, import_env.env.COOLIFY_SECRET_KEY)
    };
  }),
  login: import_trpc.publicProcedure.input(
    import_zod.z.object({
      email: import_zod.z.string(),
      password: import_zod.z.string()
    })
  ).mutation(async ({ input }) => {
    const { email, password } = input;
    const userFound = await import_prisma.prisma.user.findUnique({
      where: { email },
      include: { teams: true, permission: true }
    });
    if (!userFound) {
      throw new import_server.TRPCError({
        code: "BAD_REQUEST",
        message: "User already exists."
      });
    }
    if (userFound.type === "email") {
      if (userFound.password === "RESETME") {
        const hashedPassword = await (0, import_common.hashPassword)(password);
        if (userFound.updatedAt < new Date(Date.now() - 1e3 * 60 * 10)) {
          if (userFound.id === "0") {
            await import_prisma.prisma.user.update({
              where: { email: userFound.email },
              data: { password: "RESETME" }
            });
          } else {
            await import_prisma.prisma.user.update({
              where: { email: userFound.email },
              data: { password: "RESETTIMEOUT" }
            });
          }
        } else {
          await import_prisma.prisma.user.update({
            where: { email: userFound.email },
            data: { password: hashedPassword }
          });
          const payload2 = {
            userId: userFound.id,
            teamId: userFound.id,
            permission: userFound.permission,
            isAdmin: true
          };
          return {
            ...payload2,
            token: import_jsonwebtoken.default.sign(payload2, import_env.env.COOLIFY_SECRET_KEY)
          };
        }
      }
      if (!userFound.password) {
        throw new import_server.TRPCError({
          code: "BAD_REQUEST",
          message: "Something went wrong. Please try again later."
        });
      }
      const passwordMatch = (0, import_common.comparePassword)(password, userFound.password);
      if (!passwordMatch) {
        throw new import_server.TRPCError({
          code: "BAD_REQUEST",
          message: "Incorrect password."
        });
      }
      const payload = {
        userId: userFound.id,
        teamId: userFound.id,
        permission: userFound.permission,
        isAdmin: true
      };
      return {
        ...payload,
        token: import_jsonwebtoken.default.sign(payload, import_env.env.COOLIFY_SECRET_KEY)
      };
    }
    throw new import_server.TRPCError({
      code: "BAD_REQUEST",
      message: "Not implemented yet."
    });
  })
});
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  authRouter
});
