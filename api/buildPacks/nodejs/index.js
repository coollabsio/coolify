const fs = require("fs").promises;
const { checkImageAvailable } = require("../../libs/common");
const { buildImage } = require("../../libs/applications/build/helpers");
const { streamEvents, docker } = require("../../libs/docker");

module.exports = async function (configuration) {
  // const onlyConfigurationChanged = await checkImageAvailable(
  //   `${config.build.container.name}:${config.build.container.tag}`
  // );
  if (configuration.build.command.build) await buildImage(configuration)

  let dockerFile = `# production stage
      FROM node:lts
      WORKDIR /usr/src/app
      `;
  if (configuration.build.command.build) {
    dockerFile += `COPY --from=${configuration.build.container.name}:${configuration.build.container.tag} /usr/src/app/${configuration.build.directory} /usr/src/app`;
  } else {
    dockerFile += `COPY . ./`;
  }
  if (configuration.build.command.installation) {
    dockerFile += `
      RUN ${configuration.build.command.installation}
      `;
  }
  dockerFile += `
        EXPOSE ${configuration.publish.port}
        CMD [ "yarn", "start" ]`;

  await fs.writeFile(`${configuration.general.workdir}/Dockerfile`, dockerFile);
  const stream = await docker.engine.buildImage(
    { src: ["."], context: configuration.general.workdir },
    { t: `${configuration.build.container.name}:${configuration.build.container.tag}` }
  );
  await streamEvents(stream, configuration);
};
