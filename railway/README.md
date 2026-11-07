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

Each service's build and start settings are in this folder (`api.toml`, `queue.toml`, ...), so Railway reads them from the repository. The variables are in [`variables.md`](variables.md).

## Before you start

- A Railway account on a paid plan (Hobby is enough for the pilot). Seven services with a database: expect roughly US$20–40 a month at pilot size, mostly the API and MySQL. Check Railway's pricing page for current figures.
- The Railway GitHub app given access to `baimasonga/fleetbase`.
- The branch to deploy: `main` once this work is merged, or `claude/system-adoption-7v8woh` until then.

## 1. Create the project and the database

1. **New Project → Empty project.** Name it `invendis`.
2. **Create → Database → MySQL.** Leave the name `MySQL`.
3. **Create → Database → Redis.** Leave the name `Redis`.
4. Generate the secrets on your computer and add them under **Project Settings → Shared Variables** (see [`variables.md`](variables.md)):

   ```bash
   sh railway/generate-secrets.sh
   ```

## 2. Create the five services from GitHub

For each row below: **Create → GitHub Repo → baimasonga/fleetbase**, then in the new service's **Settings**:

| Name the service | Source → Branch | Source → Root directory | Config-as-code file |
|---|---|---|---|
| `api` | your branch | *(empty)* | `/railway/api.toml` |
| `queue` | your branch | *(empty)* | `/railway/queue.toml` |
| `scheduler` | your branch | *(empty)* | `/railway/scheduler.toml` |
| `socket` | your branch | `/socket` | `/railway/socket.toml` |
| `console` | your branch | *(empty)* | `/railway/console.toml` |

The names matter: the variables refer to the services by name.

## 3. Networking and storage

- **api:** Settings → Networking → **Generate Domain**, port `8000`. Then **Volume → New Volume**, mount path `/fleetbase/api/storage/app`. Photos, signatures, imported farmer lists and printed cards are kept there.
- **socket:** Generate Domain, port `8000`.
- **console:** Generate Domain, port `4200`.

## 4. Variables

Paste the blocks from [`variables.md`](variables.md) into each service (**Variables → Raw Editor**). Then **Deploy** (top of the canvas).

The first deploy takes a while: the API image compiles PHP extensions, and the console is a large build. Before the api starts, Railway runs its pre-deploy step: it creates the databases, runs the migrations, creates the roles and permissions, and loads the Sierra Leone admin areas. It's safe to repeat, and it runs again on every deploy.

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

Railway deploys from GitHub by itself: each service follows its branch, and every push to that branch redeploys the services whose files changed (each `.toml` lists what it watches). The api's pre-deploy step then applies any new migrations.

Turn on **Wait for CI** in each service's Settings → Source (`api`, `queue`, `scheduler`, `socket`, `console`). Railway then deploys a push only once GitHub's checks have passed. The **Invendis** workflow (`.github/workflows/invendis.yml`) runs on every push that touches Invendis, the Docker build or this folder:

- the unit and integration tests;
- the field app end to end in headless Chrome (an offline day on two phones, then a supervisor's review), with the screenshots kept as an artifact;
- the Railway config files;
- a full build of the API image Railway deploys, checking that Invendis is inside it.

A suggested flow:

1. Work on a branch and open a pull request to `main`. The checks run on the pull request.
2. Merge when they pass. Point the Railway services at `main` (Settings → Source → Branch), so production only ever runs merged work.
3. Optionally, add a second Railway environment (for example `staging`) that follows a `staging` branch, to try changes on real infrastructure first.

The checks need no secrets: Railway pulls from GitHub, and GitHub never needs Railway's credentials.


## Good to know

- **Backups.** Turn on MySQL backups in Railway (Database → Backups), and back up the api volume too: it holds the proof photos and signatures. Keep the shared secrets in a password manager.
- **Uploads on a volume** suit the pilot. A Railway volume attaches to one service, here the api, which handles every upload and download. For larger programmes, move files to S3-compatible storage (`FILESYSTEM_DRIVER=s3` with the `AWS_*` variables) so they don't depend on one service's disk.
- **The console's API address** is read when the console container starts (`railway/console-config.sh`). After changing a domain, restart the console; there's no need to rebuild it.
- **Live updates (socket) authentication** is off for the pilot, as in Fleetbase's own default. To turn it on, follow `socket/README.md` and set `SOCKETCLUSTER_AUTH_ENABLED=true` in api, queue, scheduler and socket.
- **The proof guard** (`POD_GUARD_MODE`) starts in `log` mode. Watch the logs for a while, then set it to `enforce`.
- **Email.** With `MAIL_MAILER=log`, password resets and invitations only appear in the logs. Set up a real mailer before inviting staff.
- **Region.** Railway has no West African region; choose the European one (closest to Sierra Leone) under each service's Settings → Region. The field app works offline anyway, and sends when there's signal.
