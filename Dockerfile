FROM node:16.13.0
WORKDIR /app
RUN curl -f https://get.pnpm.io/v6.16.js | node - add --global pnpm@6
COPY package*.json .
RUN pnpm install
COPY . .
RUN pnpm build
EXPOSE 3000
CMD ["yarn", "start"]

