const fs = require("fs").promises;
const { checkImageAvailable } = require("./common");
const { streamDocker } = require("./streamDocker");

async function buildImage(config, engine) {
    dockerFile = `
                # build
                FROM node:lts
                WORKDIR /usr/src/app
                COPY package*.json .
                `;
    if (config.build.installCmd) {
        dockerFile += `RUN ${config.build.installCmd}
                `;
    }
    dockerFile += `COPY . .
            RUN ${config.build.buildCmd}`;

    await fs.writeFile(`${config.general.workdir}/Dockerfile`, dockerFile);

    const stream = await engine.buildImage(
        { src: ["."], context: config.general.workdir },
        { t: `${config.build.container.name}:${config.build.container.tag}` }
    );
    await streamDocker(engine, stream, config);

}

module.exports = {
    buildImage
}