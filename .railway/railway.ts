/**
 * Invendis on Railway, as Infrastructure as Code.
 *
 * Railway no longer reads railway.toml for new services (Config as Code is
 * deprecated), so the whole project is described here and applied with the
 * Railway CLI, from the repository root:
 *
 *   railway link                 # choose your project
 *   railway config plan          # shows what will change; changes nothing
 *   railway config apply         # asks before changing anything
 *
 * Before applying, add the four secrets from `sh railway/generate-secrets.sh`
 * as shared variables (Project Settings → Shared Variables): APP_KEY,
 * POD_CARD_SECRET, POD_ID_HASH_KEY, SOCKETCLUSTER_AUTH_KEY. They are only
 * referenced here, never written into the repository.
 *
 * Services: MySQL, Redis, api (Fleetbase API + Invendis + the field app at
 * /pod-field/), queue, scheduler, socket and console. See railway/README.md.
 */
import { defineRailway, github, mysql, redis, service, volume } from "railway/iac";

/** This file only manages the resources it declares; anything else in the project is left alone. */
export const partial = "invendis";

const REPO = "baimasonga/fleetbase";
const BRANCH = "main";

/** Runs before each api deploy. Also used by the manual setup in railway/README.md. */
export const PRE_DEPLOY =
  "sh -c './deploy.sh && php artisan pod:seed-admin-areas SL && if [ -n \"$POD_ADMIN_PASSWORD\" ]; then php artisan pod:create-admin --no-interaction; fi'";

/** A Railway reference inside a longer value, e.g. "https://" + ref("api", "RAILWAY_PUBLIC_DOMAIN"). */
const ref = (resource: string, variable: string) => "${{" + resource + "." + variable + "}}";

export default defineRailway((ctx, project) => {
  const db = mysql("MySQL");
  const cache = redis("Redis");
  const files = volume("api-storage");

  // Railway deploys a push only once GitHub's checks pass (checkSuites: "Wait for CI").
  const source = (rootDirectory?: string) => github(REPO, { branch: BRANCH, checkSuites: true, ...(rootDirectory ? { rootDirectory } : {}) });

  const apiImage = {
    builder: "DOCKERFILE" as const,
    dockerfilePath: "docker/Dockerfile",
    watchPatterns: ["api/**", "packages/pod/**", "docker/**", "Caddyfile", ".railway/**"],
  };

  // Settings shared by the three services built from the API image.
  const apiEnv = {
    APP_NAME: "Invendis",
    APP_ENV: "production",
    APP_DEBUG: "false",
    APP_KEY: ctx.shared.APP_KEY,
    LOG_CHANNEL: "stdout",
    TRUSTED_PROXIES: "*",

    DATABASE_URL: db.env.MYSQL_URL,
    DB_PORT: db.env.MYSQLPORT,
    CACHE_URL: cache.env.REDIS_URL,
    REDIS_URL: cache.env.REDIS_URL,
    REDIS_PORT: cache.env.REDISPORT,
    QUEUE_CONNECTION: "redis",
    CACHE_DRIVER: "redis",
    CACHE_PATH: "/fleetbase/api/storage/framework/cache",

    APP_URL: "https://" + ref("api", "RAILWAY_PUBLIC_DOMAIN"),
    CONSOLE_HOST: "https://" + ref("console", "RAILWAY_PUBLIC_DOMAIN"),
    SESSION_DOMAIN: ref("api", "RAILWAY_PUBLIC_DOMAIN"),

    BROADCAST_DRIVER: "socketcluster",
    SOCKETCLUSTER_PUBLISH_URL: "http://" + ref("socket", "RAILWAY_PRIVATE_DOMAIN") + ":8001",
    SOCKETCLUSTER_ORIGIN: "https://" + ref("console", "RAILWAY_PUBLIC_DOMAIN"),
    SOCKETCLUSTER_AUTH_ENABLED: "false",
    SOCKETCLUSTER_AUTH_KEY: ctx.shared.SOCKETCLUSTER_AUTH_KEY,

    REGISTRY_HOST: "https://registry.fleetbase.io",
    REGISTRY_PREINSTALLED_EXTENSIONS: "true",
    OSRM_HOST: "https://router.project-osrm.org",

    // Emails only go to the logs until a real mailer is set (see railway/README.md).
    MAIL_MAILER: "log",
    MAIL_FROM_NAME: "Invendis",

    POD_GUARD_MODE: "log",
    POD_DEFAULT_COUNTRY: "SL",
    POD_CARD_SECRET: ctx.shared.POD_CARD_SECRET,
    POD_ID_HASH_KEY: ctx.shared.POD_ID_HASH_KEY,
  };

  const api = service("api", {
    source: source(),
    build: apiImage,
    deploy: {
      // Replaces the image's ENTRYPOINT (which would read settings from AWS Parameter Store).
      startCommand: "sh -c 'exec php artisan octane:frankenphp --max-requests=1000 --host=0.0.0.0 --port=${PORT:-8000}'",
      // Databases, migrations, seed data, roles and permissions, Sierra Leone admin areas. Safe to repeat.
      // With POD_ADMIN_EMAIL and POD_ADMIN_PASSWORD set (temporarily, in the dashboard), it also
      // creates the super administrator; remove POD_ADMIN_PASSWORD afterwards.
      preDeployCommand: [PRE_DEPLOY],
      preDeployTimeoutSeconds: 1800,
      healthcheckPath: "/pod-field/",
      healthcheckTimeout: 300,
      restartPolicyType: "ON_FAILURE",
      restartPolicyMaxRetries: 10,
    },
    env: { ...apiEnv, PORT: "8000" },
    // Photos, signatures, imported farmer lists and printed cards.
    volumeMounts: { "/fleetbase/api/storage/app": files },
  });

  const queue = service("queue", {
    source: source(),
    build: apiImage,
    deploy: { startCommand: "php artisan queue:work --tries=3 --timeout=300", restartPolicyType: "ALWAYS" },
    env: apiEnv,
  });

  const scheduler = service("scheduler", {
    source: source(),
    build: apiImage,
    deploy: { startCommand: "go-crond --verbose root:./crontab", restartPolicyType: "ALWAYS" },
    env: apiEnv,
  });

  const socket = service("socket", {
    source: source("socket"),
    build: { builder: "DOCKERFILE", dockerfilePath: "Dockerfile", watchPatterns: ["socket/**", ".railway/**"] },
    deploy: { healthcheckPath: "/health-check", restartPolicyType: "ALWAYS" },
    env: {
      SOCKETCLUSTER_PORT: "8000",
      SOCKETCLUSTER_OPTIONS: '{"origins":"https://' + ref("console", "RAILWAY_PUBLIC_DOMAIN") + ':*"}',
      SOCKETCLUSTER_AUTH_ENABLED: "false",
      SOCKETCLUSTER_AUTH_KEY: ctx.shared.SOCKETCLUSTER_AUTH_KEY,
      SOCKETCLUSTER_AUTHORIZE_URL: "http://" + ref("api", "RAILWAY_PRIVATE_DOMAIN") + ":8000/int/v1/socket/authorize",
    },
  });

  const consoleApp = service("console", {
    source: source(),
    build: {
      builder: "DOCKERFILE",
      dockerfilePath: "railway/console.Dockerfile",
      watchPatterns: ["console/**", "railway/console.Dockerfile", "railway/console-config.sh", ".railway/**"],
    },
    deploy: { healthcheckPath: "/", restartPolicyType: "ALWAYS" },
    env: {
      PORT: "4200",
      API_HOST: "https://" + ref("api", "RAILWAY_PUBLIC_DOMAIN"),
      SOCKETCLUSTER_HOST: ref("socket", "RAILWAY_PUBLIC_DOMAIN"),
      SOCKETCLUSTER_PORT: "443",
      SOCKETCLUSTER_SECURE: "true",
      OSRM_HOST: "https://router.project-osrm.org",
    },
  });

  return project(ctx.projectName ?? "invendis", { resources: [db, cache, files, api, queue, scheduler, socket, consoleApp] });
});
