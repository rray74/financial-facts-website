# Financial Facts

A PHP + MySQL site structured as a browsable hierarchy of financial facts:
**Category → Subcategory → Subject → Facts**. Each Subject is a single
keyword/search-phrase page (e.g. "2-Year Fixed Mortgage Rates") showing
its full pool of facts. Styled with Tailwind CSS + DaisyUI. Articles/news
are a deliberate later-stage addition — the groundwork exists but isn't
linked from navigation yet.

## How content works

- **`categories`** — top-level sections (Mortgages, Savings, Tax…).
- **`subcategories`** — groupings within a category (e.g. Mortgages →
  Fixed Rate Mortgages, Variable Rate Mortgages).
- **`subjects`** — a single keyword/search-phrase target, e.g.
  "2-Year Fixed Mortgage Rates". Its slug is the fact page's URL
  (`/fact.php?slug=2-year-fixed-mortgage-rates`).
- **`facts`** — the pool of data points belonging to a subject (value,
  unit, source, last-updated date, review cadence). A fact page shows
  every fact in its subject's pool — nothing is hidden or rotated.

**Why no randomization**: an earlier version of this scaffold rotated a
random subset of facts per day for "freshness." That's backwards —
reshuffling which facts are visible doesn't add information, so it earns
no SEO benefit, and it hides some facts from some crawls for no reason.
What genuinely helps is explained below.

**Articles** (`article_templates`, `article.php`, `renderArticleBody()`)
still work — a body can contain `{{fact:key:value}}`-style placeholders
resolved live — but nothing currently links to them. That's intentional;
wire them in whenever you're ready to start the articles/news stage.

## Fact update procedure

This is the actual freshness mechanism: genuinely updating a fact's
`value` and `last_updated` when the real-world figure changes is what
search engines reward — not display tricks. The site supports this with
a review cadence and a dashboard to act on it.

**1. Every fact has its own `review_frequency_days`.** Different data
types go stale at very different rates, so one blanket schedule doesn't
fit:

| Data type | Suggested cadence | Why |
|---|---|---|
| Mortgage/savings rates (best-buy, average) | 7 days | Move weekly in normal markets |
| Bank of England base rate | 42 days | Set at MPC meetings (~every 6 weeks) |
| Product fees, typical costs | 30 days | Change occasionally, worth a monthly glance |
| Statutory allowances (ISA, personal allowance, thresholds) | 365 days | Fixed for the tax year, rarely mid-year changes |

Set this per fact when you add it — it's a column on `facts`, not a
global setting.

**2. `/admin/review.php` lists everything currently overdue**, most
overdue first, with a direct link to each fact's source to check against
and to its live fact page. Nothing is auto-updated — you check the real
figure and, in phpMyAdmin, update `value` (if it changed) and always
`last_updated` (even if it didn't — that confirms it was checked and
resets the countdown).

**3. Protect that page before it goes anywhere near production** —
it's currently open to anyone who finds the URL. `public/admin/.htaccess`
is set up for HTTP Basic Auth but needs a `.htpasswd` file, which is
deliberately not part of this scaffold (same treatment as DB
credentials):

```bash
# Generate it once, locally or on the server:
htpasswd -c .htpasswd yourusername
# (MAMP PRO ships one too, at /Applications/MAMP/Library/bin/htpasswd)
```

Then:
- **Locally**: drop the resulting `.htpasswd` into `public/admin/` and
  edit `AuthUserFile` in `public/admin/.htaccess` to the real path on
  your machine (MAMP PRO's document root, not this repo's path).
- **On Hostinger**: upload `.htpasswd` into `public/admin/` on the server
  and set `AuthUserFile` to the real server path (hPanel's File Manager
  shows this, or check via SSH with `pwd`). It's gitignored and excluded
  from the GitHub Actions rsync, so it's set once per environment and
  survives every redeploy untouched.

**4. Suggested cadence for actually doing the reviews**: check
`/admin/review.php` weekly. Because cadences are staggered by data type,
most weeks it'll be short — mainly rate-type facts — with the annual
allowances only surfacing once a year around the tax-year change.

## Project structure

```
financial-facts/
├── config/
│   ├── database.php              # connection logic (PDO)
│   └── database.local.php.example # copy to database.local.php with real creds
├── includes/
│   ├── functions.php             # DB queries, daily-seeded fact selection
│   ├── header.php                # <head>, nav, links to compiled CSS/JS
│   └── footer.php
├── public/                       # <-- point your Hostinger domain here
│   ├── index.php                 # browse categories > subcategories > subjects
│   ├── category.php              # subcategories within a category
│   ├── subcategory.php           # subjects within a subcategory
│   ├── fact.php                  # a subject's full fact pool
│   ├── article.php               # dormant — not linked yet, see "How content works"
│   ├── admin/
│   │   ├── review.php            # facts overdue for review, see "Fact update procedure"
│   │   └── .htaccess             # Basic Auth — needs a .htpasswd you generate yourself
│   ├── .htaccess
│   └── assets/css/tailwind.css   # compiled output — see "Node build tooling"
│   └── assets/js/main.js         # compiled output
├── src/
│   ├── input.css                 # Tailwind v4 + DaisyUI v5 source, theme tokens
│   └── main.js                   # JS entry point for esbuild
├── sql/
│   └── schema.sql                # tables + seed data (categories>subcategories>subjects>facts)
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

1. **New category/subcategory**: insert a row into `categories` or
   `subcategories` (phpMyAdmin, or a future admin form).
2. **New subject**: insert a row into `subjects` with a unique `slug` —
   this becomes the fact page's URL and should target a specific search
   phrase.
3. **New facts**: insert rows into `facts` with that subject's `id`.
   Aim for ~20 per subject so the daily rotation (10 shown at a time)
   is meaningful — fewer than 10 just means the page always shows all
   of them.

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
# financial-facts-website
