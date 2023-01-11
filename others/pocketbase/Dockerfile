FROM alpine:3.17
ARG BUILDARCH 
ARG PB_VERSION=0.11.0
RUN apk add --no-cache \
    unzip \
    ca-certificates

ADD https://github.com/pocketbase/pocketbase/releases/download/v${PB_VERSION}/pocketbase_${PB_VERSION}_linux_${BUILDARCH}.zip /tmp/pb.zip
RUN unzip /tmp/pb.zip -d /app/
RUN rm /tmp/pb.zip
EXPOSE 8080
CMD ["/app/pocketbase", "serve", "--http=0.0.0.0:8080"]
