"use strict";
var import_config = require("./config");
var import_server = require("./server");
const server = (0, import_server.createServer)(import_config.serverConfig);
server.start();
