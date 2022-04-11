FROM node:16.14.2-alpine as install
WORKDIR /app
COPY package*.json .
RUN yarn install

FROM node:16.14.2-alpine 
ARG TARGETPLATFORM

WORKDIR /app

ENV PRISMA_QUERY_ENGINE_BINARY=/app/prisma-engines/query-engine \
  PRISMA_MIGRATION_ENGINE_BINARY=/app/prisma-engines/migration-engine \
  PRISMA_INTROSPECTION_ENGINE_BINARY=/app/prisma-engines/introspection-engine \
  PRISMA_FMT_BINARY=/app/prisma-engines/prisma-fmt \
  PRISMA_CLI_QUERY_ENGINE_TYPE=binary \
  PRISMA_CLIENT_ENGINE_TYPE=binary
  
COPY --from=coollabsio/prisma-engine:latest /prisma-engines/query-engine /prisma-engines/migration-engine /prisma-engines/introspection-engine /prisma-engines/prisma-fmt /app/prisma-engines/

COPY --from=install /app/node_modules ./node_modules
COPY . .

RUN apk add --no-cache git git-lfs openssh-client curl jq cmake sqlite openssl
RUN curl -f https://get.pnpm.io/v6.16.js | node - add --global pnpm@6
RUN pnpm add -g pnpm

RUN mkdir -p ~/.docker/cli-plugins/
RUN curl -SL https://cdn.coollabs.io/bin/$TARGETPLATFORM/docker-20.10.9 -o /usr/bin/docker
RUN curl -SL https://cdn.coollabs.io/bin/$TARGETPLATFORM/docker-compose-linux-2.3.4 -o ~/.docker/cli-plugins/docker-compose
RUN chmod +x ~/.docker/cli-plugins/docker-compose /usr/bin/docker

RUN pnpm build
EXPOSE 3000
CMD ["pnpm", "start"]