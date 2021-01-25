const fs = require("fs").promises;
const { checkImageAvailable } = require("../../libs/common");
const { streamDocker } = require("../../libs/streamDocker");
module.exports = async function (config, engine) {
  const installCommand = config.build.installCmd || "yarn install";
  const publishDir = config.publish.directory || "dist";
  const commitImageAvailable = await checkImageAvailable(
    `${config.build.container.name}:${config.build.container.tag}`,
    engine
  );
  let dockerFile = null;
  if (config.build.buildCmd && !commitImageAvailable) {
    dockerFile = `
              # build
              FROM node:lts
              WORKDIR /usr/src/app
              COPY package*.json .
              `;
    if (installCommand) {
      dockerFile += `RUN ${installCommand}
              `;
    }
    dockerFile += `COPY . .
          RUN ${config.build.buildCmd}`;

    await fs.writeFile(`${config.general.workdir}/Dockerfile`, dockerFile);

    const stream = await engine.buildImage(
      { src: ["."], context: config.general.workdir },
      { t: `${config.build.container.name}:cache` }
    );
    await streamDocker(engine, stream, config);
  }

  dockerFile = `# production stage
      FROM node:lts
      WORKDIR /usr/src/app
      `;
  if (config.build.buildCmd) {
    dockerFile += `COPY --from=${config.build.container.name}:cache /usr/src/app/${publishDir} /usr/src/app`;
  } else {
    dockerFile += `COPY . ./`;
  }
  if (installCommand) {
    dockerFile += `
      RUN ${installCommand}
      `;
  }
  dockerFile += `
        EXPOSE ${config.publish.port}
        CMD [ "yarn", "start" ]`;

  await fs.writeFile(`${config.general.workdir}/Dockerfile`, dockerFile);
  const stream = await engine.buildImage(
    { src: ["."], context: config.general.workdir },
    { t: `${config.build.container.name}:${config.build.container.tag}` }
  );
  await streamDocker(engine, stream, config);
};
