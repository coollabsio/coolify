const fs = require("fs").promises;
const { checkImageAvailable } = require("../../libs/common");
const { buildImage } = require("../../libs/buildPackHelpers");
const { streamDocker } = require("../../libs/streamDocker");
module.exports = async function (config, engine) {
  if (!config.build.installCmd) config.build.installCmd = "yarn install";
  if (!config.build.publishDir) config.build.publishDir = "";
  
  const onlyConfigurationChanged = await checkImageAvailable(
    `${config.build.container.name}:${config.build.container.tag}`,
    engine
  );
  if (config.build.buildCmd && !onlyConfigurationChanged) await buildImage(config, engine)

  let dockerFile = `# production stage
      FROM node:lts
      WORKDIR /usr/src/app
      `;
  if (config.build.buildCmd && onlyConfigurationChanged) {
    dockerFile += `COPY --from=${config.build.container.name}:${config.build.container.tag} /usr/src/app/${config.build.publishDir} /usr/src/app`;
  } else {
    dockerFile += `COPY . ./`;
  }
  if (config.build.installCmd) {
    dockerFile += `
      RUN ${config.build.installCmd}
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
