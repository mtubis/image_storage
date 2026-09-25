# Vite dev server for local development (the E2E stack overrides the command to
# serve a production build); dependencies are installed by the
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
# A GID that already exists is reused rather than recreated: macOS users are in
# group 20 (staff), which is dialout in Alpine, and busybox can't duplicate it.
RUN if [ "$(id -u node)" != "${APP_UID}" ] || [ "$(id -g node)" != "${APP_GID}" ]; then \
        deluser node && delgroup node 2>/dev/null; \
        group="$(getent group "${APP_GID}" | cut -d: -f1)"; \
        if [ -z "${group}" ]; then addgroup -g "${APP_GID}" node && group=node; fi \
        && adduser -D -u "${APP_UID}" -G "${group}" -h /home/node node; \
    fi \
    && mkdir -p /app && chown -R "${APP_UID}:${APP_GID}" /app /home/node

USER node

ENTRYPOINT ["/usr/local/bin/frontend-entrypoint.sh"]
CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0"]
