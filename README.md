# GSC App (WordPress) — Local Development Setup

This guide sets up the complete application on your machine using Docker. You do **not** need to install PHP, Apache, MySQL, or Composer locally — everything runs inside containers and matches the production server environment (PHP 8.1 + Apache + MySQL 8).

---

## 1. Prerequisites

| Requirement | Notes |
|---|---|
| **Docker Desktop** | Download from https://www.docker.com/products/docker-desktop/. Restart your PC after installation (required for WSL2). |
| **Git** | For cloning and pulling the repository. |
| **Two files from the team lead** | `.env` (environment config) and `ecwid_dump.sql` (database dump). These are intentionally **not** in the repository — never commit them. |

Before starting, open Docker Desktop and wait until the bottom-left status shows **"Engine running"** (green). If it hangs on *"Starting the Docker Engine…"*, run `wsl --shutdown` in PowerShell (as Administrator) and reopen Docker Desktop.

> **Shell note:** Commands below are written for **CMD** (Command Prompt). If you use PowerShell instead, two things differ: replace `%cd%` with `${PWD}`, and wrap any command containing `<` in `cmd /c "..."` (PowerShell does not support the `<` redirect operator).

---

## 2. First-Time Setup

### Step 1 — Clone the repository
```cmd
git clone https://github.com/pappukushwaha-dev/gsc-app-ecwid.git
cd gsc-app-ecwid
```

### Step 2 — Add the two private files
Place both files in the **project root** (same folder as the `Dockerfile`):

- `.env` — provided by the team lead
- `ecwid_dump.sql` — provided by the team lead

### Step 3 — Install Composer dependencies (do this BEFORE the first build)

> **Why this step exists:** the `google/apiclient-services` package contains thousands of small files. Extracting it directly onto a Windows-mounted folder is extremely slow and hits Composer's process timeout (we have seen it fail even after 33 minutes). The reliable method is to install inside a container's own filesystem — where extraction takes seconds — and then copy the finished `vendor/` folder out in one bulk operation.

Run these four commands one by one from the project root:

```cmd
:: 1. Remove any partial vendor folder from a previous failed attempt (skip if none exists)
rmdir /s /q vendor

:: 2. Install dependencies inside a temporary container (fast — no Windows mount involved)
docker run --name composer_tmp -v "%cd%\composer.json:/app/composer.json" -v "%cd%\composer.lock:/app/composer.lock" -w /app composer:2 composer install --ignore-platform-reqs --no-interaction

:: 3. Copy the completed vendor folder to your project (one bulk copy, 2–5 minutes for ~145 MB)
docker cp composer_tmp:/app/vendor .\vendor

:: 4. Remove the temporary container
docker rm composer_tmp
```

What to expect: step 2 shows a package progress bar and ends with "Generating autoload files"; step 3 ends with "Successfully copied ... to ...\vendor". If step 2 fails with a lock-file error ("package is not present in the lock file"), run `git pull` first — the fixed `composer.lock` is in the repository.

### Step 4 — Build and start the containers
```cmd
docker compose up -d --build
```
The first build takes **5–15 minutes** (base images + PHP extensions). Subsequent builds are much faster because layers are cached.

### Step 5 — Wait for the database to initialise
The MySQL container needs ~30 seconds on first run. Check:
```cmd
docker compose logs db --tail 3
```
Proceed only when you see **`ready for connections`** with **`port: 3306`**. (An earlier "Temporary server started" line with `port: 0` means it is still initialising — wait and check again.)

### Step 6 — Import the database dump
```cmd
docker exec -i gsc_ecwid_db mysql -uroot -proot wordpress-googlesearchconsole < ecwid_dump.sql
```
Notes:
- (PowerShell users: `cmd /c "docker exec -i gsc_ecwid_db mysql -uroot -proot wordpress-googlesearchconsole < ecwid_dump.sql"`)
- The command prints only a password warning and then appears to hang — **this is normal**. Large dumps take several minutes. It is finished when the prompt returns.
- To watch progress, open a second terminal and run:
  ```cmd
  docker exec -i gsc_ecwid_db mysql -uroot -proot -e "SHOW TABLES;" wordpress-googlesearchconsole
  ```
  The table list grows as the import proceeds.

### Step 7 — Open the app
Visit **http://localhost:8082**

Both URL styles work locally:
- `http://localhost:8082/admin/sign-in.php`
- `http://localhost:8082/wordpress/googlesearchconsole/...` (production-style path, mapped via an Apache alias)

---

## 3. Daily Workflow

| Action | Command |
|---|---|
| Start containers | `docker compose up -d` |
| Stop containers | `docker compose down` (database data is preserved in a Docker volume) |
| View app logs (PHP errors) | `docker compose logs -f web` |
| View database logs | `docker compose logs db --tail 20` |
| Open a shell inside the app container | `docker exec -it gsc_ecwid_app bash` |
| Check what is running | `docker ps` |

**Code changes reflect instantly.** The project folder is volume-mounted into the container, so editing any PHP/JS/CSS file and refreshing the browser is enough. A rebuild (`docker compose up -d --build`) is only needed when `Dockerfile`, `docker-compose.yml`, `composer.json`, or `composer.lock` change.

**If `composer.json` / `composer.lock` change** (after a `git pull`): repeat Step 3 (the four composer commands) so your local `vendor/` folder matches, then rebuild.

**Database access from a GUI** (TablePlus / MySQL Workbench / HeidiSQL):
- Host: `127.0.0.1` · Port: `3308` · User: `root` · Password: `root` · Database: `wordpress-googlesearchconsole`

**Running multiple GSC apps side by side:** each app in this suite uses its own ports, so they can all run at the same time — HL on 8080, BigCommerce on 8081/3307, Ecwid on 8082/3308.

---

## 4. Troubleshooting

### A. Composer timeout: "exceeded the timeout of ... seconds" during unzip/extract
This is the exact problem Step 3 is designed to avoid. If you tried `composer install` or `composer update` directly against the project folder and it timed out on `google/apiclient-services`, abandon that approach and run the four commands from **Step 3** instead. Do not simply raise the timeout further — extraction onto the Windows mount can take 30+ minutes or never finish, while the container-internal method completes in seconds.

Optional permanent improvement: add your workspace folder (e.g. `D:\WorkSpace`) to Windows Defender exclusions (Windows Security → Virus & threat protection → Exclusions). Real-time scanning of thousands of small files is the main cause of the slowness.

### B. "Lock file is not up to date" / packages missing from lock file
Do **not** edit the Dockerfile to use `composer update`. Run `git pull` — the regenerated `composer.lock` is committed in the repository — then rebuild. If the error persists after pulling, inform the team lead.

### C. `${PWD}` error: "includes invalid characters for a local volume name"
You ran a PowerShell-style command in CMD. In CMD use `"%cd%"` for the current directory; in PowerShell use `${PWD}`. Or avoid the issue entirely by writing the absolute path, which works in both shells.

### D. `The '<' operator is reserved for future use`
You ran the database import in PowerShell. Either switch to CMD, or wrap the command: `cmd /c "docker exec -i ... < ecwid_dump.sql"`.

### E. `unable to get image` / `cannot connect to the Docker daemon`
Docker Desktop is not running. Open it, wait for "Engine running", and retry. If it never reaches that state: `wsl --shutdown` in an Administrator PowerShell, then reopen Docker Desktop.

### F. `No such container: gsc_ecwid_db`
The containers have not been created yet (the build likely failed earlier — commonly at the Composer step, see A/B). Fix the build first (`docker compose up -d --build` must end with containers **Started**), confirm with `docker ps`, then retry the import.

### G. Port conflict on startup
**Symptom:** `Bind for 0.0.0.0:8082 failed: port is already allocated` (or the same for 3308).
**Fix:** something else on your machine uses that port. Edit the left-hand side of the port mapping in `docker-compose.yml` (e.g. `"8083:80"` / `"3309:3306"`), run `docker compose up -d`, and use the new port in the browser. Do not commit this personal change.

### H. Database connection failed in the app
1. Confirm the `db` container is running: `docker ps` should list `gsc_ecwid_db`.
2. Confirm `.env` exists in the project root with `DB_HOST=db` (not `localhost` — inside Docker, the database hostname is the service name `db`).
3. Restart after any `.env` change: `docker compose up -d`.

### I. Page loads but images or API calls fail / load slowly
Open DevTools → Network and filter by `makkpressapps.com`. Any request in that list is a hardcoded production URL going to the live server instead of your local container. Report these to the team lead — they are code-level fixes (the URL should come from the `APP_URL` config constant), not environment issues.

### J. SQL error mentioning `only_full_group_by`
This should not occur — the compose file already sets the SQL mode to match production. If you see it, you are running an outdated `docker-compose.yml`; run `git pull` and `docker compose up -d`.

---

## 5. Rules

1. **Never commit** `.env`, `*.sql` dumps, or the `vendor/` folder. They are gitignored — do not force-add them.
2. The database dump contains **real production data**. Do not share it, upload it anywhere, or copy it outside your development machine.
3. Environment changes (`Dockerfile`, `docker-compose.yml`) go through the team lead — do not commit personal port changes or config tweaks.
4. When something breaks, capture the **full terminal output** and share it — the exact error text is what makes it fixable quickly.
