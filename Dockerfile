FROM node:16.13.1
WORKDIR /app

RUN curl -fsSL https://download.docker.com/linux/static/stable/x86_64/docker-20.10.9.tgz | tar -xzvf - docker/docker -C . --strip-components 1 && mv docker /usr/bin/docker
RUN chmod +x /usr/bin/docker
RUN curl -f https://get.pnpm.io/v6.16.js | node - add --global pnpm@6

COPY package*.json .
RUN yarn install
COPY . .
RUN yarn build
EXPOSE 3000

CMD ["yarn", "start"]

