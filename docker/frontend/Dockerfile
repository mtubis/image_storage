# Vite dev server for local development; dependencies are installed by the
# entrypoint into the bind-mounted frontend/ directory.
FROM node:22-alpine

ARG APP_UID=1000
ARG APP_GID=1000

WORKDIR /app

COPY entrypoint.sh /usr/local/bin/frontend-entrypoint.sh
RUN chmod +x /usr/local/bin/frontend-entrypoint.sh

# Align the image's built-in `node` user with the host user so bind-mounted
# files in frontend/ (node_modules, dist) stay owned by the developer, not
# root; mirrors the same UID/GID alignment done for www-data in docker/php.
RUN if [ "$(id -u node)" != "${APP_UID}" ] || [ "$(id -g node)" != "${APP_GID}" ]; then \
        deluser node && delgroup node 2>/dev/null; \
        addgroup -g "${APP_GID}" node \
        && adduser -D -u "${APP_UID}" -G node -h /home/node node; \
    fi \
    && mkdir -p /app && chown -R node:node /app /home/node

USER node

ENTRYPOINT ["/usr/local/bin/frontend-entrypoint.sh"]
