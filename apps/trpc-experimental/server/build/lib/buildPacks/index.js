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
var buildPacks_exports = {};
__export(buildPacks_exports, {
  astro: () => import_static2.default,
  compose: () => import_compose.default,
  deno: () => import_deno.default,
  docker: () => import_docker.default,
  eleventy: () => import_static3.default,
  gatsby: () => import_gatsby.default,
  heroku: () => import_heroku.default,
  laravel: () => import_laravel.default,
  nestjs: () => import_nestjs.default,
  nextjs: () => import_nextjs.default,
  node: () => import_node.default,
  nuxtjs: () => import_nuxtjs.default,
  php: () => import_php.default,
  python: () => import_python.default,
  react: () => import_react.default,
  rust: () => import_rust.default,
  staticApp: () => import_static.default,
  svelte: () => import_svelte.default,
  vuejs: () => import_vuejs.default
});
module.exports = __toCommonJS(buildPacks_exports);
var import_node = __toESM(require("./node"));
var import_static = __toESM(require("./static"));
var import_docker = __toESM(require("./docker"));
var import_gatsby = __toESM(require("./gatsby"));
var import_svelte = __toESM(require("./svelte"));
var import_react = __toESM(require("./react"));
var import_nestjs = __toESM(require("./nestjs"));
var import_nextjs = __toESM(require("./nextjs"));
var import_nuxtjs = __toESM(require("./nuxtjs"));
var import_vuejs = __toESM(require("./vuejs"));
var import_php = __toESM(require("./php"));
var import_rust = __toESM(require("./rust"));
var import_static2 = __toESM(require("./static"));
var import_static3 = __toESM(require("./static"));
var import_python = __toESM(require("./python"));
var import_deno = __toESM(require("./deno"));
var import_laravel = __toESM(require("./laravel"));
var import_heroku = __toESM(require("./heroku"));
var import_compose = __toESM(require("./compose"));
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  astro,
  compose,
  deno,
  docker,
  eleventy,
  gatsby,
  heroku,
  laravel,
  nestjs,
  nextjs,
  node,
  nuxtjs,
  php,
  python,
  react,
  rust,
  staticApp,
  svelte,
  vuejs
});
