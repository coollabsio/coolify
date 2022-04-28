FROM fluent/fluent-bit:1.9.0
COPY fluentbit-dev.conf /tmp/fluentbit.conf
ENTRYPOINT ["/fluent-bit/bin/fluent-bit", "-c", "/tmp/fluentbit.conf"]
# USER root
# RUN ["gem", "install", "fluent-plugin-mongo"]
# USER fluent