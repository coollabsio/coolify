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

  dockerFile = `# production stage
    FROM nginx:stable-alpine
    COPY nginx.conf /etc/nginx/nginx.conf
    `;
  if (config.build.buildCmd && onlyConfigurationChanged) {
    dockerFile += `COPY --from=${config.build.container.name}:${config.build.container.tag} /usr/src/app/${publishDir} /usr/share/nginx/html`;
  } else {
    dockerFile += "COPY . /usr/share/nginx/html";
  }
  dockerFile += `
      EXPOSE 80
      CMD ["nginx", "-g", "daemon off;"]`;
  await fs.writeFile(`${config.general.workdir}/Dockerfile`, dockerFile);

  const stream = await engine.buildImage(
    { src: ["."], context: config.general.workdir },
    { t: `${config.build.container.name}:${config.build.container.tag}` }
  );
  await streamDocker(engine, stream, config);
};
