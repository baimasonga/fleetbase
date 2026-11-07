# Deploying Invendis on Railway

Invendis runs on Fleetbase. On Railway it is seven services in one project, built from this repository:

| Service | What it is | Built from | Public domain |
|---|---|---|---|
| **MySQL** | the database | Railway's MySQL | no |
| **Redis** | cache and job queue | Railway's Redis | no |
| **api** | the Fleetbase API with Invendis, and the **field app** at `/pod-field/` | `docker/Dockerfile` | yes, port 8000 |
| **queue** | background jobs | `docker/Dockerfile` | no |
| **scheduler** | timed jobs, every minute | `docker/Dockerfile` | no |
| **socket** | live updates in the console | `socket/Dockerfile` | yes, port 8000 |
| **console** | the web console (administrators, managers, supervisors, monitors) | `railway/console.Dockerfile` | yes, port 4200 |

The whole project is described in [`.railway/railway.ts`](../.railway/railway.ts), Railway's **Infrastructure as Code** format, and applied with the Railway CLI. Railway no longer reads `railway.toml` / `railway.json` for new services: that format ("Config as Code") is deprecated and stops working altogether on 1 December 2026. A service created in the dashboard without the steps below falls back to Railpack and fails with *"Railpack could not determine how to build the app"*.

## Before you start

- A Railway account on a paid plan (Hobby is enough for the pilot). Seven services with a database: expect roughly US$20–40 a month at pilot size, mostly the API and MySQL. Check Railway's pricing page for current figures.
- The Railway GitHub app given access to **`baimasonga/fleetbase`** (not Fleetbase's original `fleetbase/fleetbase`, which doesn't contain Invendis).
- On your computer: Node.js and the [Railway CLI](https://docs.railway.com/guides/cli), version 5.42.1 or newer, signed in (`railway login`).

## 1. The secrets

Generate them on your computer:

```bash
sh railway/generate-secrets.sh
```

In your Railway project, open **Project Settings → Shared Variables** and add the four values it prints: `APP_KEY`, `POD_CARD_SECRET`, `POD_ID_HASH_KEY`, `SOCKETCLUSTER_AUTH_KEY`. Keep a copy in a password manager. `.railway/railway.ts` only refers to them by name; they're never written into the repository.

## 2. Create everything with one command

From the root of this repository:

```bash
npm install --prefix .railway
```

```bash
railway link
```

Choose your project (for example `chic-trust`) and its `production` environment. Then preview the changes; this changes nothing:

```bash
railway config plan
```

It should list MySQL, Redis, a volume, and the services `api`, `queue`, `scheduler`, `socket` and `console`, all from `baimasonga/fleetbase` on `main`, built from Dockerfiles. Then apply, after checking the changes it shows you:

```bash
railway config apply
```

The file only manages what it declares (`partial = "invendis"`), so other services in the project are left alone. If you created a service by hand earlier (for example one called `fleetbase`), delete it in the dashboard: the file creates `api` in its place.

## 3. Public addresses

The file can't create Railway's generated addresses, so add them in the dashboard. On each service: **Settings → Networking → Generate Domain**.

| Service | Port |
|---|---|
| `api` | `8000` |
| `socket` | `8000` |
| `console` | `4200` |

Then **Deploy** (top of the canvas), or redeploy the five services.

The first deploy takes a while: the API image compiles PHP extensions, and the console is a large build. Before the api starts, Railway runs its pre-deploy step: it creates the databases, runs the migrations, creates the roles and permissions, and loads the Sierra Leone admin areas. It's safe to repeat, and it runs again on every deploy.

## 4. Settings to choose yourself

- **Region:** under each service's Settings → Region, choose the European one (closest to Sierra Leone). Railway has no West African region.
- **Email:** the file sets `MAIL_MAILER=log`, so emails only appear in the logs. Before inviting staff, set a real mailer on `api`, `queue` and `scheduler`, for example `MAIL_MAILER=smtp` with `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION=tls` and `MAIL_FROM_ADDRESS`. Add them to `.railway/railway.ts` (with secrets as shared variables) and apply again; otherwise the next apply removes variables it doesn't know.

### Without the CLI: the same setup by hand

If you'd rather use the dashboard, create MySQL (`MySQL`), Redis (`Redis`) and five services from `baimasonga/fleetbase` on `main`, named exactly as below, with these settings:

| Service | Source → Root directory | Variable `RAILWAY_DOCKERFILE_PATH` | Deploy → Custom start command | Pre-deploy command | Healthcheck path |
|---|---|---|---|---|---|
| `api` | *(empty)* | `docker/Dockerfile` | `sh -c 'exec php artisan octane:frankenphp --max-requests=1000 --host=0.0.0.0 --port=${PORT:-8000}'` | `sh -c './deploy.sh && php artisan pod:seed-admin-areas SL'` | `/pod-field/` |
| `queue` | *(empty)* | `docker/Dockerfile` | `php artisan queue:work --tries=3 --timeout=300` | | |
| `scheduler` | *(empty)* | `docker/Dockerfile` | `go-crond --verbose root:./crontab` | | |
| `socket` | `/socket` | `Dockerfile` | *(empty)* | | `/health-check` |
| `console` | *(empty)* | `railway/console.Dockerfile` | *(empty)* | | `/` |

`RAILWAY_DOCKERFILE_PATH` is what makes Railway build with Docker instead of Railpack. Add a volume to `api` mounted at `/fleetbase/api/storage/app`, generate the domains as in step 3, and paste the variables from [`variables.md`](variables.md).

## 5. Create the super administrator

With the [Railway CLI](https://docs.railway.com/guides/cli), from this repository:

```bash
railway link
```

```bash
railway ssh --service api
```

Then, inside the container:

```bash
php artisan pod:create-admin --email=<your email> --organisation="<your organisation>"
```

It asks for the password twice, without showing it. Don't put the password in a variable or on the command line.

Then sign in to the console at `https://<console domain>`. Give each person their Invendis role under **IAM → Users** (see the package README, "Roles").

## 6. The field app

Officers open **`https://<api domain>/pod-field/`** in Chrome on their phone, then **Add to Home screen**. It's served by the api service, over HTTPS, which the camera and offline storage need.

## Checking it works

- `https://<api domain>/pod-field/` shows the Invendis sign-in.
- The console signs in and shows the organisation.
- In the api service's **Deploy Logs**, the pre-deploy step ends without errors, and the last lines mention `pod:seed-admin-areas` (`1,504` areas for Sierra Leone the first time, `0 added` afterwards).
- In **queue**, the log shows it processing jobs.

## Deploying through GitHub

Railway deploys from GitHub by itself: each service follows `main`, and every push to it redeploys the services whose files changed (the watch patterns in `.railway/railway.ts`). The api's pre-deploy step then applies any new migrations.

**Wait for CI** is on for every service (`checkSuites` in the file): Railway deploys a push only once GitHub's checks have passed. The **Invendis** workflow (`.github/workflows/invendis.yml`) runs on every push that touches Invendis, the Docker build or this folder:

- the unit and integration tests;
- the field app end to end in headless Chrome (an offline day on two phones, then a supervisor's review), with the screenshots kept as an artifact;
- `.railway/railway.ts`, type-checked against Railway's SDK;
- a full build of the API image Railway deploys, checking that Invendis is inside it.

A suggested flow:

1. Work on a branch and open a pull request to `main`. The checks run on the pull request.
2. Merge when they pass. Railway deploys `main`, so production only ever runs merged work.
3. Optionally, add a second Railway environment (for example `staging`) that follows a `staging` branch, to try changes on real infrastructure first.

The checks need no secrets: Railway pulls from GitHub, and GitHub never needs Railway's credentials.

Changes to the infrastructure itself (a new service or variable) go into `.railway/railway.ts` and are applied with `railway config plan` and `railway config apply`; a push alone doesn't apply them. Railway's [railwayapp/config](https://github.com/railwayapp/config) GitHub Action can do that on merge if you want it automated; it needs a Railway token stored as a GitHub secret.


## Good to know

- **Backups.** Turn on MySQL backups in Railway (Database → Backups), and back up the api volume too: it holds the proof photos and signatures. Keep the shared secrets in a password manager.
- **Uploads on a volume** suit the pilot. A Railway volume attaches to one service, here the api, which handles every upload and download. For larger programmes, move files to S3-compatible storage (`FILESYSTEM_DRIVER=s3` with the `AWS_*` variables) so they don't depend on one service's disk.
- **The console's API address** is read when the console container starts (`railway/console-config.sh`). After changing a domain, restart the console; there's no need to rebuild it.
- **Live updates (socket) authentication** is off for the pilot, as in Fleetbase's own default. To turn it on, follow `socket/README.md` and set `SOCKETCLUSTER_AUTH_ENABLED=true` in api, queue, scheduler and socket.
- **The proof guard** (`POD_GUARD_MODE`) starts in `log` mode. Watch the logs for a while, then set it to `enforce`.
- **Email.** With `MAIL_MAILER=log`, password resets and invitations only appear in the logs. Set up a real mailer before inviting staff.
- **Region.** The field app works offline and sends when there's signal, so a European region (step 4) is fine for Sierra Leone.
