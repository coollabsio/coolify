FROM rust:1.58.1-alpine3.14 as prisma
WORKDIR /prisma
ENV RUSTFLAGS="-C target-feature=-crt-static"
RUN apk --no-cache add openssl direnv git musl-dev openssl-dev build-base perl protoc
RUN git clone --depth=1 --branch=3.11.x https://github.com/prisma/prisma-engines.git /prisma 
RUN cargo build --release

FROM alpine
WORKDIR /prisma-engines
COPY --from=prisma /prisma/target/release/query-engine /prisma/target/release/migration-engine /prisma/target/release/introspection-engine /prisma/target/release/prisma-fmt /prisma-engines/
