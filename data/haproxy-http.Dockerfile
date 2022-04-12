FROM haproxytech/haproxy-alpine:2.5
RUN mkdir -p /usr/local/etc/haproxy/ssl /usr/local/etc/haproxy/maps /usr/local/etc/haproxy/spoe

COPY data/haproxy/haproxy.cfg-http.template /usr/local/etc/haproxy/haproxy.cfg
COPY data/haproxy/dataplaneapi.hcl /usr/local/etc/haproxy/dataplaneapi.hcl
COPY data/haproxy/ssl/default.pem /usr/local/etc/haproxy/ssl/default.pem 