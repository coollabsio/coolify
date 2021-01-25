require('dotenv').config()
const fastify = require("fastify")({
  logger: true
});
const mongoose = require("mongoose");
const path = require("path");
const { schema } = require('./schema')
fastify.register(require("fastify-env"), {
  schema,
  dotenv: true,
});

if (process.env.NODE_ENV === "production") {
  fastify.register(require("fastify-static"), {
    root: path.join(__dirname, "../dist/"),
  });

  fastify.setNotFoundHandler(function (request, reply) {
    reply.sendFile("index.html");
  });
} else {
  fastify.register(require("fastify-static"), {
    root: path.join(__dirname, "../public/"),
  });
}

fastify.register(require("./app"), { prefix: "/api/v1" });
mongoose.connect(
  `mongodb://${process.env.MONGODB_USER}:${process.env.MONGODB_PASSWORD}@${process.env.MONGODB_HOST}:${process.env.MONGODB_PORT}/${process.env.MONGODB_DB}?authSource=${process.env.MONGODB_DB}&readPreference=primary&ssl=false`,
  { useNewUrlParser: true, useUnifiedTopology: true, useFindAndModify: false }
);
mongoose.connection.on(
  "error",
  console.error.bind(console, "connection error:")
);
mongoose.connection.once("open", function () {
  if (process.env.NODE_ENV === "production") {
    fastify.listen(3000, '0.0.0.0');
  } else {
    fastify.listen(3001);
  }
});
