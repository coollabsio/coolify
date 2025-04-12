FROM debian:bookworm-slim
ENV DOMAIN=localhost
RUN apt-get update && \
    apt-get -y install \
    imagemagick \
    python3-cryptography \
    python3-dateutil \
    python3-idna \
    python3-requests \
    python3-pip \
    python3-setuptools \
    python3-socks \
    python3-idna \
    libimage-exiftool-perl \
    python3-flake8 \
    python3-django-timezone-field \
    python3-pyqrcode \
    python3-png \
    python3-bandit \
    tor
RUN adduser --system --home=/opt/epicyon --group epicyon
COPY --chown=epicyon:epicyon . /app
EXPOSE 80 7156
CMD /usr/bin/python3 \
    /app/epicyon.py \
    --port 80 \
    --proxy 7156 \
    --registration open \
    --domain $DOMAIN \
    --path /app