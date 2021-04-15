const fs = require('fs').promises
const { streamEvents, docker } = require('../../libs/docker')
const { execShellAsync } = require('../../libs/common')
const TOML = require('@iarna/toml')

const publishRustDocker = (configuration, custom) => {
  return [
    'FROM rust:latest',
    'WORKDIR /app',
    `COPY --from=${configuration.build.container.name}:cache /app/target target`,
    `COPY --from=${configuration.build.container.name}:cache /usr/local/cargo /usr/local/cargo`,
    'COPY . .',
    `RUN cargo build --release --bin ${custom.name}`,
    'FROM debian:buster-slim',
    'WORKDIR /app',
    'RUN apt-get update -y && apt-get install -y --no-install-recommends openssl libcurl4 ca-certificates && apt-get autoremove -y && apt-get clean -y && rm -rf /var/lib/apt/lists/*',
    'RUN update-ca-certificates',
    `COPY --from=${configuration.build.container.name}:cache /app/target/release/${custom.name} ${custom.name}`,
    `EXPOSE ${configuration.publish.port}`,
    `CMD ["/app/${custom.name}"]`
  ].join('\n')
}

const cacheRustDocker = (configuration, custom) => {
  return [
    `FROM rust:latest AS planner-${configuration.build.container.name}`,
    'WORKDIR /app',
    'RUN cargo install cargo-chef',
    'COPY . .',
    'RUN cargo chef prepare --recipe-path recipe.json',
    'FROM rust:latest',
    'WORKDIR /app',
    'RUN cargo install cargo-chef',
    `COPY --from=planner-${configuration.build.container.name} /app/recipe.json recipe.json`,
    'RUN cargo chef cook --release --recipe-path recipe.json'
  ].join('\n')
}

module.exports = async function (configuration) {
  try {
    const cargoToml = await execShellAsync(`cat ${configuration.general.workdir}/Cargo.toml`)
    const parsedToml = TOML.parse(cargoToml)
    const custom = {
      name: parsedToml.package.name
    }
    await fs.writeFile(`${configuration.general.workdir}/Dockerfile`, cacheRustDocker(configuration, custom))

    let stream = await docker.engine.buildImage(
      { src: ['.'], context: configuration.general.workdir },
      { t: `${configuration.build.container.name}:cache` }
    )
    await streamEvents(stream, configuration)

    await fs.writeFile(`${configuration.general.workdir}/Dockerfile`, publishRustDocker(configuration, custom))

    stream = await docker.engine.buildImage(
      { src: ['.'], context: configuration.general.workdir },
      { t: `${configuration.build.container.name}:${configuration.build.container.tag}` }
    )
    await streamEvents(stream, configuration)
  } catch (error) {
    throw { error, type: 'server' }
  }
}
