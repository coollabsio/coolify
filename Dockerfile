FROM node:18-slim as build
WORKDIR /app

ARG PNPM_VERSION=7.11.0

RUN apt update && apt -y install curl
RUN npm --no-update-notifier --no-fund --global install pnpm@${PNPM_VERSION}

COPY . .
RUN pnpm install
RUN pnpm build

# Production build
FROM node:18-slim
WORKDIR /app
ENV NODE_ENV production
ARG NPM_VERSION=8.19.1
ARG PNPM_VERSION=7.11.0
ARG TARGETPLATFORM

RUN apt update && apt -y install --no-install-recommends ca-certificates git git-lfs openssh-client curl jq cmake sqlite3 openssl psmisc python3
RUN apt-get clean autoclean && apt-get autoremove --yes && rm -rf /var/lib/{apt,dpkg,cache,log}/
RUN npm --no-update-notifier --no-fund --global install pnpm@${PNPM_VERSION}
RUN npm install -g npm@${PNPM_VERSION}

RUN mkdir -p ~/.docker/cli-plugins/
# https://download.docker.com/linux/static/stable/
RUN curl -SL https://cdn.coollabs.io/bin/$TARGETPLATFORM/docker-20.10.9 -o /usr/bin/docker
# https://github.com/docker/compose/releases
# Reverted to 2.6.1 because of this https://github.com/docker/compose/issues/9704. 2.9.0 still has a bug.
RUN curl -SL https://cdn.coollabs.io/bin/$TARGETPLATFORM/docker-compose-linux-2.6.1 -o ~/.docker/cli-plugins/docker-compose
RUN chmod +x ~/.docker/cli-plugins/docker-compose /usr/bin/docker

RUN (curl -sSL "https://github.com/buildpacks/pack/releases/download/v0.27.0/pack-v0.27.0-linux.tgz" | tar -C /usr/local/bin/ --no-same-owner -xzv pack)

COPY --from=build /app/apps/api/build/ .
COPY --from=build /app/apps/ui/build/ ./public
COPY --from=build /app/apps/api/prisma/ ./prisma
COPY --from=build /app/apps/api/package.json .
COPY --from=build /app/docker-compose.yaml .

RUN pnpm install -p

EXPOSE 3000
ENV CHECKPOINT_DISABLE=1
CMD pnpm start