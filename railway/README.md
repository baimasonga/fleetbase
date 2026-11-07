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

## Two ways to set it up

- **A. From the Railway website**: clicks and copy-paste only, nothing to install. Best on Windows.
- **B. With the Railway command-line tool**: one command creates everything from [`.railway/railway.ts`](../.railway/railway.ts). For people comfortable with a terminal.

Both give the same result. Before either:

- A Railway account on a paid plan (Hobby is enough for the pilot). Seven services with a database: expect roughly US$20–40 a month at pilot size, mostly the API and MySQL. Check Railway's pricing page for current figures.
- The Railway GitHub app given access to **`baimasonga/fleetbase`** (not Fleetbase's original `fleetbase/fleetbase`, which doesn't contain Invendis).

## A. From the Railway website (Windows-friendly)

### A1. Make the secrets (PowerShell, once)

Press **Start**, type **PowerShell**, open **Windows PowerShell**, paste these lines and press **Enter**:

```powershell
$rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
function New-Secret([int]$Bytes) { $b = New-Object byte[] $Bytes; $rng.GetBytes($b); [Convert]::ToBase64String($b) }
"APP_KEY=base64:" + (New-Secret 32)
"POD_CARD_SECRET=" + (New-Secret 48)
"POD_ID_HASH_KEY=" + (New-Secret 48)
"SOCKETCLUSTER_AUTH_KEY=" + (New-Secret 48)
```

It prints four lines (the same script is in `railway/generate-secrets.ps1`). Save them in a password manager. Then, in your Railway project, open **Settings → Shared Variables** and add each one: the part before `=` is the name, the part after is the value.

### A2. Databases

On the project canvas: **Create → Database → MySQL**, then **Create → Database → Redis**. Keep their names `MySQL` and `Redis`.

### A3. The five services

For each row: **Create → GitHub Repo → baimasonga/fleetbase**. Then, in the new service:

1. **Settings**, at the top: rename it exactly as in the first column. The services refer to each other by these names.
2. **Settings → Source:** branch `main`. Set the root directory only for `socket`. Turn on **Wait for CI**.
3. **Settings → Deploy:** fill in the commands below (leave a box empty where the table has nothing).
4. **Variables → Raw Editor:** paste that service's block from [`variables.md`](variables.md). Each block starts with `RAILWAY_DOCKERFILE_PATH`, the variable that makes Railway build with Docker instead of failing with Railpack.

| Name | Root directory | Custom start command | Pre-deploy command | Healthcheck path | Variables block |
|---|---|---|---|---|---|
| `api` | *(empty)* | `sh -c 'exec php artisan octane:frankenphp --max-requests=1000 --host=0.0.0.0 --port=${PORT:-8000}'` | `sh -c './deploy.sh && php artisan pod:seed-admin-areas SL && if [ -n "$POD_ADMIN_PASSWORD" ]; then php artisan pod:create-admin --no-interaction; fi'` | `/pod-field/` | "api, queue and scheduler", plus "api: one more" |
| `queue` | *(empty)* | `php artisan queue:work --tries=3 --timeout=300` | | | "api, queue and scheduler" |
| `scheduler` | *(empty)* | `go-crond --verbose root:./crontab` | | | "api, queue and scheduler" |
| `socket` | `/socket` | | | `/health-check` | "socket" |
| `console` | *(empty)* | | | `/` | "console" |

If you created a service earlier that failed (for example one called `fleetbase`), you can rename it to `api` and set it up as above, or delete it.

### A4. Storage and addresses

- **api → Settings → Volumes → Add volume**, mount path `/fleetbase/api/storage/app`. Photos, signatures, imported farmer lists and printed cards are kept there.
- **Settings → Networking → Generate Domain** on `api` (port `8000`), `socket` (port `8000`) and `console` (port `4200`).
- **Settings → Region** on each service: choose the European one, closest to Sierra Leone.

### A5. Your super administrator, without a terminal

On **api → Variables**, add two variables for the first deploy only:

- `POD_ADMIN_EMAIL`: your email.
- `POD_ADMIN_PASSWORD`: a strong password, at least 12 characters, mixing upper case, lower case, digits and symbols.

### A6. Deploy

Click **Deploy** at the top of the canvas. The first deploy takes a while: the API image compiles PHP extensions, and the console is a large build. Before the api starts, its pre-deploy step creates the databases, runs the migrations, creates the roles and permissions, loads the Sierra Leone admin areas, and creates your super administrator. It's safe to repeat, and it runs again on every deploy.

When the api's deploy shows **Success**, **delete `POD_ADMIN_PASSWORD`** from its variables. The account stays, and the password is no longer stored in Railway. Sign in to the console at `https://<console domain>` with that email and password.

## B. With the Railway command-line tool

You need Node.js and the [Railway CLI](https://docs.railway.com/guides/cli) (version 5.42.1 or newer), signed in with `railway login`. On Windows, run these in PowerShell from the folder where you cloned the repository.

1. **Secrets:** make them as in A1 (on macOS/Linux, `sh railway/generate-secrets.sh`) and add them as shared variables.
2. **Create everything:**

   ```bash
   npm install --prefix .railway
   ```

   ```bash
   railway link
   ```

   ```bash
   railway config plan
   ```

   ```bash
   railway config apply
   ```

   `railway link` asks for your project and environment. `plan` changes nothing: it should list MySQL, Redis, a volume, and the services `api`, `queue`, `scheduler`, `socket` and `console`, all from `baimasonga/fleetbase` on `main`, built from Dockerfiles. `apply` asks before changing anything. The file only manages what it declares (`partial = "invendis"`), so other services in the project are left alone; delete any service you made by hand earlier.
3. **Addresses and region** as in A4 (the file can't create Railway's generated domains).
4. **Super administrator:** as in A5 and A6, or from a terminal:

   ```bash
   railway ssh --service api
   ```

   Then, inside the container:

   ```bash
   php artisan pod:create-admin --email=<your email> --organisation="<your organisation>"
   ```

   It asks for the password twice, without showing it.

Changes to the setup itself (a new variable or service) go into `.railway/railway.ts`, then `railway config plan` and `railway config apply`. Variables set only in the dashboard are removed by the next `apply`, so add them to the file too (secrets as shared variables).

## Email

Both routes start with `MAIL_MAILER=log`: emails only appear in the logs. Before inviting staff, set a real mailer on `api`, `queue` and `scheduler`, for example `MAIL_MAILER=smtp` with `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION=tls` and `MAIL_FROM_ADDRESS`.

## After the first deploy

Give each person their Invendis role in the console under **IAM → Users** (see the package README, "Roles").

## The field app

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


## If a deploy fails

| What the log says | What to do |
|---|---|
| *Railpack could not determine how to build the app* | The service isn't building with Docker: add the variable `RAILWAY_DOCKERFILE_PATH` (see [`variables.md`](variables.md)), and check the repository is `baimasonga/fleetbase`. |
| *APP_KEY is not valid* (pre-deploy step) | `APP_KEY` must be `base64:` followed by the generated value, with nothing else. Make a new one and replace the value. |
| *APP_URL is "https://", which is not a web address* | Generate the service's public domain (Settings → Networking), and set `APP_URL` to `https://${{RAILWAY_PUBLIC_DOMAIN}}`. |
| *worker script has failed on restart* | A setting the web server needs is wrong. The pre-deploy step's `pod:check-runtime` names it: read the lines just above the end of the pre-deploy log. |
| Healthcheck *service unavailable* until it gives up | The server didn't start: see the deploy log for one of the messages above. |

## Good to know

- **Backups.** Turn on MySQL backups in Railway (Database → Backups), and back up the api volume too: it holds the proof photos and signatures. Keep the shared secrets in a password manager.
- **Uploads on a volume** suit the pilot. A Railway volume attaches to one service, here the api, which handles every upload and download. For larger programmes, move files to S3-compatible storage (`FILESYSTEM_DRIVER=s3` with the `AWS_*` variables) so they don't depend on one service's disk.
- **The console's API address** is read when the console container starts (`railway/console-config.sh`). After changing a domain, restart the console; there's no need to rebuild it.
- **Live updates (socket) authentication** is off for the pilot, as in Fleetbase's own default. To turn it on, follow `socket/README.md` and set `SOCKETCLUSTER_AUTH_ENABLED=true` in api, queue, scheduler and socket.
- **The proof guard** (`POD_GUARD_MODE`) starts in `log` mode. Watch the logs for a while, then set it to `enforce`.
- **Email.** With `MAIL_MAILER=log`, password resets and invitations only appear in the logs. Set up a real mailer before inviting staff.
- **Region.** The field app works offline and sends when there's signal, so a European region (step 4) is fine for Sierra Leone.
