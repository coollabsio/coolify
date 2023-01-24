"use strict";
var __defProp = Object.defineProperty;
var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
var __getOwnPropNames = Object.getOwnPropertyNames;
var __hasOwnProp = Object.prototype.hasOwnProperty;
var __copyProps = (to, from, except, desc) => {
  if (from && typeof from === "object" || typeof from === "function") {
    for (let key of __getOwnPropNames(from))
      if (!__hasOwnProp.call(to, key) && key !== except)
        __defProp(to, key, { get: () => from[key], enumerable: !(desc = __getOwnPropDesc(from, key)) || desc.enumerable });
  }
  return to;
};
var __reExport = (target, mod, secondTarget) => (__copyProps(target, mod, "default"), secondTarget && __copyProps(secondTarget, mod, "default"));
var __toCommonJS = (mod) => __copyProps(__defProp({}, "__esModule", { value: true }), mod);
var routers_exports = {};
module.exports = __toCommonJS(routers_exports);
__reExport(routers_exports, require("./auth"), module.exports);
__reExport(routers_exports, require("./dashboard"), module.exports);
__reExport(routers_exports, require("./settings"), module.exports);
__reExport(routers_exports, require("./applications"), module.exports);
__reExport(routers_exports, require("./services"), module.exports);
__reExport(routers_exports, require("./databases"), module.exports);
__reExport(routers_exports, require("./sources"), module.exports);
__reExport(routers_exports, require("./destinations"), module.exports);
