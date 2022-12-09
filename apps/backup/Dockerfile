ARG PNPM_VERSION=7.17.1

FROM node:18-slim as build
WORKDIR /app
RUN npm --no-update-notifier --no-fund --global install pnpm@${PNPM_VERSION}

COPY ./package*.json  .
RUN pnpm install -p
COPY . .

# Production build
FROM node:18-slim
ARG DOCKER_VERSION=20.10.18
ARG TARGETPLATFORM
ENV NODE_ENV production

WORKDIR /app

RUN apt update && apt -y install curl
RUN npm --no-update-notifier --no-fund --global install pnpm@${PNPM_VERSION}
RUN curl -SL https://cdn.coollabs.io/bin/$TARGETPLATFORM/docker-$DOCKER_VERSION -o /usr/bin/docker
RUN chmod +x /usr/bin/docker
COPY --from=minio/mc:latest /usr/bin/mc /usr/bin/mc
COPY --from=build /app/ .

ENV CHECKPOINT_DISABLE=1
CMD node /app/src/index.mjs