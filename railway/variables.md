# Railway variables

`.railway/railway.ts` sets all of these when you run `railway config apply`. You only need this page for the manual route ("Without the CLI" in the guide) or to check a value.

`${{...}}` are Railway reference variables: paste them as they are, and Railway fills them in (`shared.` for shared variables, `MySQL.`/`api.` and so on for another service's). Service names must match the ones in the guide (`api`, `queue`, `scheduler`, `socket`, `console`, `MySQL`, `Redis`). Values in `<...>` are yours to fill in.

## Shared variables: the secrets (Project Settings → Shared Variables)

Enter these once. Generate them on Windows with `railway/generate-secrets.ps1` (or the PowerShell lines in the guide), or on macOS/Linux with `sh railway/generate-secrets.sh`. Keep a copy in your password manager: `POD_CARD_SECRET` signs every printed farmer card and `POD_ID_HASH_KEY` protects ID numbers, so losing or changing them breaks the cards already printed.

```
APP_KEY=base64:...
POD_CARD_SECRET=...
POD_ID_HASH_KEY=...
SOCKETCLUSTER_AUTH_KEY=...
```

## api, queue and scheduler: the same block

Paste this into each of the three services (Variables → Raw Editor):

```
RAILWAY_DOCKERFILE_PATH=docker/Dockerfile
APP_NAME=Invendis
APP_ENV=production
APP_DEBUG=false
APP_KEY=${{shared.APP_KEY}}
LOG_CHANNEL=stdout
TRUSTED_PROXIES=*

DATABASE_URL=${{MySQL.MYSQL_URL}}
DB_PORT=${{MySQL.MYSQLPORT}}
CACHE_URL=${{Redis.REDIS_URL}}
REDIS_URL=${{Redis.REDIS_URL}}
REDIS_PORT=${{Redis.REDISPORT}}
QUEUE_CONNECTION=redis
CACHE_DRIVER=redis
CACHE_PATH=/fleetbase/api/storage/framework/cache

APP_URL=https://${{api.RAILWAY_PUBLIC_DOMAIN}}
CONSOLE_HOST=https://${{console.RAILWAY_PUBLIC_DOMAIN}}
SESSION_DOMAIN=${{api.RAILWAY_PUBLIC_DOMAIN}}

BROADCAST_DRIVER=socketcluster
SOCKETCLUSTER_PUBLISH_URL=http://${{socket.RAILWAY_PRIVATE_DOMAIN}}:8001
SOCKETCLUSTER_ORIGIN=https://${{console.RAILWAY_PUBLIC_DOMAIN}}
SOCKETCLUSTER_AUTH_ENABLED=false
SOCKETCLUSTER_AUTH_KEY=${{shared.SOCKETCLUSTER_AUTH_KEY}}

REGISTRY_HOST=https://registry.fleetbase.io
REGISTRY_PREINSTALLED_EXTENSIONS=true
OSRM_HOST=https://router.project-osrm.org

MAIL_MAILER=log
MAIL_FROM_ADDRESS=<no-reply@your-domain>
MAIL_FROM_NAME=Invendis

POD_GUARD_MODE=log
POD_DEFAULT_COUNTRY=SL
POD_CARD_SECRET=${{shared.POD_CARD_SECRET}}
POD_ID_HASH_KEY=${{shared.POD_ID_HASH_KEY}}
```

Mail: `log` only writes emails to the logs. For password resets and invitations, set a real mailer (for example `MAIL_MAILER=smtp` with `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION=tls`, or `resend` with `RESEND_API_KEY`).

## api: one more

```
PORT=8000
```

For the first deploy only, also add these two to create the super administrator, then delete `POD_ADMIN_PASSWORD` once the deploy has succeeded:

```
POD_ADMIN_EMAIL=<your email>
POD_ADMIN_PASSWORD=<a strong password: 12+ characters, mixing upper case, lower case, digits, symbols>
```

Networking: generate a public domain on port **8000**. Attach a volume at `/fleetbase/api/storage/app` (photos, signatures, imported farmer lists, printed cards).

## queue, scheduler

Nothing more. No public domain.

## socket

```
RAILWAY_DOCKERFILE_PATH=Dockerfile
SOCKETCLUSTER_PORT=8000
SOCKETCLUSTER_OPTIONS={"origins":"https://${{console.RAILWAY_PUBLIC_DOMAIN}}:*"}
SOCKETCLUSTER_AUTH_ENABLED=false
SOCKETCLUSTER_AUTH_KEY=${{shared.SOCKETCLUSTER_AUTH_KEY}}
SOCKETCLUSTER_AUTHORIZE_URL=http://${{api.RAILWAY_PRIVATE_DOMAIN}}:8000/int/v1/socket/authorize
```

Networking: generate a public domain on port **8000**.

## console

```
RAILWAY_DOCKERFILE_PATH=railway/console.Dockerfile
PORT=4200
API_HOST=https://${{api.RAILWAY_PUBLIC_DOMAIN}}
SOCKETCLUSTER_HOST=${{socket.RAILWAY_PUBLIC_DOMAIN}}
SOCKETCLUSTER_PORT=443
SOCKETCLUSTER_SECURE=true
OSRM_HOST=https://router.project-osrm.org
```

Networking: generate a public domain on port **4200**.

## With your own domains

When you add custom domains (for example `api.invendis.org`, `app.invendis.org`, `socket.invendis.org`), replace the `RAILWAY_PUBLIC_DOMAIN` references above with them, set `SESSION_DOMAIN=.invendis.org`, and redeploy `api`, `queue` and `scheduler`, then restart `console` and `socket`.
