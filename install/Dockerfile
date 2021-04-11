FROM coolify-base
LABEL coolify-preserve=true
WORKDIR /usr/src/app
RUN pnpm build
CMD ["pnpm", "start"]
EXPOSE 3000