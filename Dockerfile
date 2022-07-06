FROM node:18-alpine as build
WORKDIR /app

RUN apk add --no-cache curl
RUN curl -sL https://unpkg.com/@pnpm/self-installer | node

COPY . .
RUN pnpm install
RUN pnpm build

# Production build
FROM node:18-alpine
WORKDIR /app
ENV NODE_ENV production
ARG TARGETPLATFORM

ENV PRISMA_QUERY_ENGINE_BINARY=/app/prisma-engines/query-engine \
  PRISMA_MIGRATION_ENGINE_BINARY=/app/prisma-engines/migration-engine \
  PRISMA_INTROSPECTION_ENGINE_BINARY=/app/prisma-engines/introspection-engine \
  PRISMA_FMT_BINARY=/app/prisma-engines/prisma-fmt \
  PRISMA_CLI_QUERY_ENGINE_TYPE=binary \
  PRISMA_CLIENT_ENGINE_TYPE=binary

COPY --from=coollabsio/prisma-engine:3.15 /prisma-engines/query-engine /prisma-engines/migration-engine /prisma-engines/introspection-engine /prisma-engines/prisma-fmt /app/prisma-engines/

RUN apk add --no-cache git git-lfs openssh-client curl jq cmake sqlite openssl
RUN curl -sL https://unpkg.com/@pnpm/self-installer | node

RUN mkdir -p ~/.docker/cli-plugins/
RUN curl -SL https://cdn.coollabs.io/bin/$TARGETPLATFORM/docker-20.10.9 -o /usr/bin/docker
RUN curl -SL https://cdn.coollabs.io/bin/$TARGETPLATFORM/docker-compose-linux-2.3.4 -o ~/.docker/cli-plugins/docker-compose
RUN chmod +x ~/.docker/cli-plugins/docker-compose /usr/bin/docker

COPY --from=build /app/apps/api/build/ .
COPY --from=build /app/apps/ui/build/ ./public
COPY --from=build /app/apps/api/prisma/ ./prisma
COPY --from=build /app/apps/api/package.json .
COPY --from=build /app/docker-compose.yaml .

RUN pnpm install -p

EXPOSE 3000
CMD pnpm start