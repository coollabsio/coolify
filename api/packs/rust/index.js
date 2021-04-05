const fs = require('fs').promises
const { streamEvents, docker } = require('../../libs/docker')

const publishRustDocker = (configuration) => {
  return [
    'FROM rust:latest AS planner',
    'WORKDIR /app',
    'RUN cargo install cargo-chef',
    'COPY . .',
    'RUN cargo chef prepare  --recipe-path recipe.json',
    'FROM rust:latest AS cacher',
    'WORKDIR /app',
    'RUN cargo install cargo-chef',
    'COPY --from=planner /app/recipe.json recipe.json',
    'RUN cargo chef cook --release --recipe-path recipe.json',
    'FROM rust:latest AS builder',
    'WORKDIR /app',
    'COPY --from=cacher /app/target target',
    'COPY --from=cacher /usr/local/cargo /usr/local/cargo',
    'COPY . .',
    `RUN cargo build --release --bin ${configuration.build.container.name}`,
    'FROM debian:buster-slim AS runtime',
    'WORKDIR /app',
    'RUN apt-get update -y \
        && apt-get install -y --no-install-recommends openssl libcurl4 ca-certificates\
        && apt-get autoremove -y && apt-get clean -y && rm -rf /var/lib/apt/lists/*',
    'RUN update-ca-certificates',
    `COPY --from=builder /app/target/release/${configuration.build.container.name} ${configuration.build.container.name}`,
    `EXPOSE ${configuration.publish.port}`,
    'ENTRYPOINT ["./poke-spearify"]'
  ].join('\n')
}

module.exports = async function (configuration) {
  await fs.writeFile(`${configuration.general.workdir}/Dockerfile`, publishRustDocker(configuration))
  const stream = await docker.engine.buildImage(
    { src: ['.'], context: configuration.general.workdir },
    { t: `${configuration.build.container.name}:${configuration.build.container.tag}` }
  )
  await streamEvents(stream, configuration)
}
