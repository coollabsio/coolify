ARG PNPM_VERSION=7.11.0

FROM node:18-slim as build
WORKDIR /app

RUN apt update && apt -y install curl
RUN npm --no-update-notifier --no-fund --global install pnpm@${PNPM_VERSION}

COPY . .
RUN pnpm install
RUN pnpm build

# Production build
FROM node:18-slim
WORKDIR /app
ENV NODE_ENV production
ARG TARGETPLATFORM

# https://download.docker.com/linux/static/stable/
ARG DOCKER_VERSION=20.10.18
# https://github.com/docker/compose/releases
# Reverted to 2.6.1 because of this https://github.com/docker/compose/issues/9704. 2.9.0 still has a bug.
ARG DOCKER_COMPOSE_VERSION=2.6.1
# https://github.com/buildpacks/pack/releases
ARG PACK_VERSION=v0.27.0

RUN apt update && apt -y install --no-install-recommends ca-certificates git git-lfs openssh-client curl jq cmake sqlite3 openssl psmisc python3
RUN apt-get clean autoclean && apt-get autoremove --yes && rm -rf /var/lib/{apt,dpkg,cache,log}/
RUN npm --no-update-notifier --no-fund --global install pnpm@${PNPM_VERSION}
RUN npm install -g npm@${PNPM_VERSION}

RUN mkdir -p ~/.docker/cli-plugins/

RUN curl -SL https://cdn.coollabs.io/bin/$TARGETPLATFORM/docker-$DOCKER_VERSION -o /usr/bin/docker
RUN curl -SL https://cdn.coollabs.io/bin/$TARGETPLATFORM/docker-compose-linux-$DOCKER_COMPOSE_VERSION -o ~/.docker/cli-plugins/docker-compose
RUN curl -SL https://cdn.coollabs.io/bin/$TARGETPLATFORM/pack-$PACK_VERSION -o /usr/local/bin/pack 

RUN chmod +x ~/.docker/cli-plugins/docker-compose /usr/bin/docker /usr/local/bin/pack

COPY --from=build /app/apps/api/build/ .
COPY --from=build /app/others/fluentbit/ ./fluentbit
COPY --from=build /app/apps/ui/build/ ./public
COPY --from=build /app/apps/api/prisma/ ./prisma
COPY --from=build /app/apps/api/package.json .
COPY --from=build /app/docker-compose.yaml .
COPY --from=build /app/apps/api/tags.json .
COPY --from=build /app/apps/api/templates.json .

RUN pnpm install -p

EXPOSE 3000
ENV CHECKPOINT_DISABLE=1
CMD pnpm start