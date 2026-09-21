# Financial Facts

A PHP + MySQL site where articles are assembled at request time from
individually-dated facts stored in the database, rather than written as
static blog posts. Styled with Tailwind CSS + DaisyUI.

## How content works

- **`facts`** — atomic data points (a rate, an allowance, a threshold),
  each with its own value, unit, source and last-updated date.
- **`article_templates`** — article text containing placeholders like
  `{{fact:uk_base_rate:value}}`. `includes/functions.php` resolves these
  live against the `facts` table on every page load, so updating one row
  in `facts` updates every article that references it, immediately.

Placeholder syntax: `{{fact:KEY}}` or `{{fact:KEY:FIELD}}` where FIELD is
one of `value`, `unit`, `label`, `context`, `source`, `updated`. A bare
`{{fact:KEY}}` renders as `label: value unit`.

## Project structure

```
financial-facts/
├── config/
│   ├── database.php              # connection logic (PDO)
│   └── database.local.php.example # copy to database.local.php with real creds
├── includes/
│   ├── functions.php             # DB queries + placeholder rendering
│   ├── header.php                # <head>, nav, links to compiled CSS/JS
│   └── footer.php
├── public/                       # <-- point your Hostinger domain here
│   ├── index.php
│   ├── category.php
│   ├── article.php
│   ├── .htaccess
│   └── assets/css/tailwind.css   # compiled output — see "Node build tooling"
│   └── assets/js/main.js         # compiled output
├── src/
│   ├── input.css                 # Tailwind v4 + DaisyUI v5 source, theme tokens
│   └── main.js                   # JS entry point for esbuild
├── sql/
│   └── schema.sql                # tables + seed data
├── package.json                  # build tooling only — not used at runtime
└── README.md
```

## Deploying to Hostinger

1. **Create the database**: hPanel → Databases → MySQL Databases → create
   a database and user, note the host/name/user/password.
2. **Import the schema**: hPanel → phpMyAdmin → select your new database →
   Import → upload `sql/schema.sql`.
3. **Upload files**: upload everything to your hosting account, but point
   the domain's document root at the `public/` folder specifically (hPanel
   → Websites → your domain → Advanced → set document root to
   `public_html/financial-facts/public` or wherever you place it). This
   keeps `config/` and `includes/` outside what a browser can request
   directly, even without the `.htaccess` rule.
4. **Set credentials**: copy `config/database.local.php.example` to
   `config/database.local.php` on the server and fill in the real values
   from step 1. This file is gitignored, so it's set once directly on
   the server, not committed anywhere.
5. Visit the domain — you should see the seeded Mortgages/Savings/Tax
   categories and the one example article.

## Deploying from GitHub

Two ways to get this repo onto Hostinger. Pick one — don't run both against
the same site.

### Option A — hPanel's built-in Git deploy (simplest)

1. Push this repo to GitHub as normal (`git init`, `git add .`,
   `git commit`, then create the repo on GitHub and push).
2. In hPanel, go to your website → **Advanced → Git** and connect the
   repository (you may need to add a deploy key or authorize via OAuth,
   hPanel walks you through it).
3. Set the **branch** to deploy (usually `main`) and the **install path**
   — this is where the *whole repo* gets cloned to, e.g.
   `public_html/financial-facts`.
4. Because the repo root isn't the web root (see project structure above),
   go back to **Websites → your domain → Advanced → Document Root** and
   point it at `<install path>/public`, e.g.
   `public_html/financial-facts/public`.
5. On the server, create `config/database.local.php` from the `.example`
   file with real credentials — this file is gitignored, so it survives
   redeploys but is never overwritten by them, and never touches GitHub.
6. Trigger a deploy from hPanel (manual button, or enable auto-deploy on
   push if offered on your plan).

### Option B — GitHub Actions (rsync over SSH)

Use this if you want deploys to fire automatically on every push without
relying on hPanel's Git integration, or if your plan doesn't include it.

1. In hPanel, find your **SSH access** details (Advanced → SSH Access) —
   host, port (often not 22 on shared hosting), and username. Enable SSH
   if it isn't already.
2. Generate a dedicated deploy key pair (don't reuse your personal key):
   ```bash
   ssh-keygen -t ed25519 -C "github-actions-deploy" -f deploy_key -N ""
   ```
   Add `deploy_key.pub` to the server via hPanel → SSH Access → Manage
   SSH Keys, and add the *private* key as a GitHub Actions secret named
   `HOSTINGER_SSH_KEY` (repo → Settings → Secrets and variables →
   Actions). Also add `HOSTINGER_HOST`, `HOSTINGER_PORT`, `HOSTINGER_USER`,
   and `HOSTINGER_PATH` (the server path to `financial-facts/`) as secrets.
3. Add the workflow file at `.github/workflows/deploy.yml` (included in
   this scaffold) — it rsyncs the repo to the server on every push to
   `main`, excluding `.git` and the local credentials file.
4. Create `config/database.local.php` on the server once, manually, the
   same way as Option A step 5 — the workflow's `.gitignore`-respecting
   rsync will never touch or overwrite it.

Either option leaves your real DB password out of GitHub entirely, which
is the important part regardless of which you pick.

## Adding content

- New fact: insert a row into `facts` (via phpMyAdmin, or build a small
  admin form later).
- New article: insert a row into `article_templates`, writing HTML in
  `body` and dropping in `{{fact:key:field}}` wherever a live figure
  should appear. Set `status = 'published'` when ready.

## Node build tooling

Node is **build-time only** — it compiles CSS/JS into static files that
PHP then serves. Hostinger never runs Node or npm; nothing about the live
site depends on it.

```bash
nvm use 20          # or any recent LTS
npm install
npm run build        # compiles src/input.css -> public/assets/css/tailwind.css
                      # and bundles src/main.js -> public/assets/js/main.js
npm run watch         # rebuilds both on file change, for local dev
```

- **CSS**: `src/input.css` holds the Tailwind v4 + DaisyUI v5 setup,
  including the `financialfacts` theme tokens (ink navy / sage / mustard /
  paper) and the `.fact-card` / `.article-body` custom rules. Edit colors,
  fonts or custom classes there, not in the compiled output.
- **JS**: `src/main.js` is the entry point esbuild bundles and minifies.
  Add imports/code there as the site grows.
- The **compiled output** (`public/assets/css/tailwind.css`,
  `public/assets/js/main.js`) is committed to the repo, not gitignored —
  that way the site works even deploying straight from GitHub without a
  Node step (Option A above). The GitHub Actions deploy (Option B) runs
  `npm run build` automatically before every deploy, so the committed
  files are really a fallback/local-dev convenience there.
- If using Option A, run `npm run build` and commit the result before
  pushing — hPanel's Git deploy won't build anything for you.
