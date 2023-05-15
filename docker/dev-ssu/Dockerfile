FROM serversideup/php:8.2-fpm-nginx
ARG POSTGRES_VERSION=15
RUN apt-get update && apt-get install -y php-pgsql openssh-client git git-lfs postgresql-client
RUN apt-get -y autoremove \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*

RUN echo "alias ll='ls -al'" >> /etc/bash.bashrc
RUN echo "alias a='php artisan'" >> /etc/bash.bashrc
RUN echo "alias mfs='php artisan migrate:fresh --seed'" >> /etc/bash.bashrc
RUN echo "alias cda='composer dump-autoload'" >> /etc/bash.bashrc

# COPY --chmod=755 docker/dev-ssu/etc/s6-overlay/ /etc/s6-overlay/
