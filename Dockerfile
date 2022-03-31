FROM node:17-alpine
RUN apk add --no-cache g++ cmake make python3
WORKDIR /app
COPY package*.json .
RUN yarn install
COPY . .
RUN yarn build

FROM node:17-alpine
WORKDIR /app
ARG TARGETPLATFORM
LABEL coolify.managed true
RUN apk add --no-cache git openssh-client curl jq cmake sqlite
RUN curl -f https://get.pnpm.io/v6.16.js | node - add --global pnpm@6
RUN pnpm add -g pnpm

RUN mkdir -p ~/.docker/cli-plugins/
RUN curl -SL https://cdn.coollabs.io/bin/docker-20.10.9-$TARGETPLATFORM -o /usr/bin/docker
RUN curl -SL https://cdn.coollabs.io/bin/docker-compose-linux-2.3.4-$TARGETPLATFORM -o ~/.docker/cli-plugins/docker-compose
RUN chmod +x ~/.docker/cli-plugins/docker-compose /usr/bin/docker

COPY --from=0 /app/docker-compose.yaml .
COPY --from=0 /app/build .
COPY --from=0 /app/package.json .
COPY --from=0 /app/node_modules ./node_modules
COPY --from=0 /app/prisma ./prisma

EXPOSE 3000
CMD ["pnpm", "start"]