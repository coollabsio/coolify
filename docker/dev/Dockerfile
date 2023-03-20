FROM serversideup/php:8.2-fpm-nginx

ARG NODE_VERSION=18
ARG POSTGRES_VERSION=15

RUN apt-get update \
    && curl -sLS https://deb.nodesource.com/setup_$NODE_VERSION.x | bash - \
    && apt-get install -y nodejs \
    && npm install -g npm

RUN apt-get install -y php-pgsql openssh-client

RUN apt-get -y autoremove \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*

USER root

# S6 Overlay config
COPY --chmod=755 etc/s6-overlay/ /etc/s6-overlay/
