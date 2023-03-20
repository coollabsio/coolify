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

# Adding Coolify User and SSH key to connect to the Testing-Host (which is simulation of a VPS)
RUN useradd -ms /bin/bash coolify
USER coolify
RUN mkdir -p ~/.ssh
RUN echo "-----BEGIN OPENSSH PRIVATE KEY-----\n\
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW\n\
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk\n\
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA\n\
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV\n\
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==\n\
-----END OPENSSH PRIVATE KEY-----" >> ~/.ssh/id_ed25519
RUN chmod 0600 ~/.ssh/id_ed25519

USER root
