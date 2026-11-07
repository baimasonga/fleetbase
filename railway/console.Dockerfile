# The Fleetbase console for Railway: the same build as console/Dockerfile, with
# runtime config left on, so the API and socket addresses come from the
# service's variables when the container starts (see console-config.sh).
# Build context: the repository root.

# ---- Build Stage ----
FROM node:22.22.0-alpine3.22 AS builder

WORKDIR /console

RUN mkdir -p ~/.pnpm
ENV PNPM_HOME=/root/.pnpm
ENV PATH=/root/.pnpm/bin:$PATH

ARG ENVIRONMENT=production
ARG DISABLE_FLEETBASE_ATTRIBUTION=false
ENV DISABLE_FLEETBASE_ATTRIBUTION=$DISABLE_FLEETBASE_ATTRIBUTION
# Read fleetbase.config.json at start-up (production builds turn this off by default).
ENV DISABLE_RUNTIME_CONFIG=false

COPY console/package.json console/pnpm-lock.yaml console/pnpm-workspace.yaml ./
COPY console/.npmr[c] ./

RUN npm install -g ember-cli pnpm
RUN apk update && apk add git openssh-client
RUN mkdir -p -m 0600 ~/.ssh && ssh-keyscan github.com >> ~/.ssh/known_hosts

RUN pnpm install --frozen-lockfile

COPY console/ .

RUN pnpm build --environment $ENVIRONMENT

# ---- Serve Stage ----
FROM nginx:alpine

COPY --from=builder /console/dist /usr/share/nginx/html
COPY console/nginx.conf /etc/nginx/conf.d/default.conf
# nginx's image runs /docker-entrypoint.d/*.sh before starting.
COPY railway/console-config.sh /docker-entrypoint.d/40-fleetbase-config.sh
RUN chmod +x /docker-entrypoint.d/40-fleetbase-config.sh

EXPOSE 4200

CMD ["nginx", "-g", "daemon off;"]
