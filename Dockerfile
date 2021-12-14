FROM node:16.13.1
WORKDIR /app

RUN curl -fsSL https://download.docker.com/linux/static/stable/x86_64/docker-20.10.9.tgz | tar -xzvf - docker/docker -C . --strip-components 1 && mv docker /usr/bin/docker
RUN chmod +x /usr/bin/docker

COPY package*.json .
RUN yarn install
COPY . .
RUN yarn build


FROM node:16.13.1-alpine
WORKDIR /app
RUN apk add --no-cache git openssh-client g++ make python3 curl cmake

COPY --from=0 /app/build .
COPY --from=0 /app/package.json .
COPY --from=0 /app/node_modules ./node_modules
COPY --from=0 /app/prisma ./prisma

COPY --from=0 /usr/bin/docker /usr/bin/docker

RUN curl -f https://get.pnpm.io/v6.16.js | node - add --global pnpm@6

EXPOSE 3000
CMD ["yarn", "start"]

