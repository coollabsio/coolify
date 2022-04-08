FROM node:16.14.0-alpine
RUN apk add --no-cache g++ cmake make python3
WORKDIR /app
COPY package*.json .
RUN yarn install
COPY . .
RUN yarn build

FROM node:16.14.0-alpine
WORKDIR /app

LABEL coolify.managed true

RUN apk add --no-cache git git-lfs openssh-client curl jq cmake sqlite openssl

RUN curl -f https://get.pnpm.io/v6.16.js | node - add --global pnpm@6
RUN pnpm add -g pnpm

RUN curl -fsSL "https://download.docker.com/linux/static/stable/x86_64/docker-20.10.9.tgz" | tar -xzvf - docker/docker -C . --strip-components 1 && mv docker /usr/bin/docker
RUN mkdir -p ~/.docker/cli-plugins/
RUN curl -SL https://github.com/docker/compose/releases/download/v2.2.2/docker-compose-linux-x86_64 -o ~/.docker/cli-plugins/docker-compose
RUN chmod +x ~/.docker/cli-plugins/docker-compose

COPY --from=0 /app/docker-compose.yaml .
COPY --from=0 /app/build .
COPY --from=0 /app/package.json .
COPY --from=0 /app/node_modules ./node_modules
COPY --from=0 /app/prisma ./prisma

EXPOSE 3000
CMD ["pnpm", "start"]