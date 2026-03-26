# Pickleball Deployment

This repo is configured to auto-deploy the pickleball app from the `pickleball-port` branch to `pickleball.courtmaster.online` by GitHub Actions.

## Assumptions

- The server is Linux-based and reachable by SSH.
- The subdomain document root points directly to the deployed `public` app directory.
- The server already has PHP and MySQL available.

## GitHub Secrets

Add these repository secrets before using the workflow:

- `DEPLOY_HOST`
- `DEPLOY_USER`
- `DEPLOY_SSH_PRIVATE_KEY`
- `DEPLOY_SSH_PORT`
  Optional. Defaults to `22`.
- `DEPLOY_PATH`
  Example: `/home/youruser/public_html/pickleball.courtmaster.online`
- `PICKLEBALL_DB_HOST`
- `PICKLEBALL_DB_NAME`
- `PICKLEBALL_DB_USER`
- `PICKLEBALL_DB_PASS`

## What The Workflow Does

- runs on every push to `pickleball-port`
- syncs the repo's `public/` directory to the server document root
- keeps uploaded payment proof files in place by excluding `uploads/payment-proofs/`
- writes a production-only `public/includes/db.credentials.php` on the server from GitHub Secrets

## Server Notes

- Point `pickleball.courtmaster.online` to the server IP in DNS.
- Set the subdomain document root to the same path used in `DEPLOY_PATH`.
- Make sure the web server user can write to:
  - `uploads/payment-proofs`
  - any other upload directories you use in production

## Suggested Production Secrets

Based on the current pickleball hosting plan, these are the expected values to add in GitHub repository secrets:

- `DEPLOY_HOST=pickleball.courtmaster.online`
- `DEPLOY_USER=olanpelayo0788`
- `DEPLOY_SSH_PORT=22`
- `DEPLOY_PATH`
  Confirm the real cPanel subdomain document root before saving this value.
- `PICKLEBALL_DB_HOST=localhost`
  Use a different host only if cPanel lists one for MySQL connections.
- `PICKLEBALL_DB_NAME=pickleball_courtmaster`
- `PICKLEBALL_DB_USER`
  Use the production MySQL username exactly as shown in cPanel.
- `PICKLEBALL_DB_PASS`
  Use the production MySQL password you created.

## First Production Import

This workflow deploys code and writes the production database credentials file. It does not import the initial schema or seed data into the production database.

Before the first live launch, import the SQL schema into `pickleball_courtmaster` through phpMyAdmin or the MySQL CLI.

## Local Development

Local XAMPP still works because `public/includes/db.php` keeps local fallback credentials unless a `db.credentials.php` override or `COURTMASTER_*` environment variables are present.
