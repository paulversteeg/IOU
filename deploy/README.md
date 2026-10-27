# Deployment

The app is deployed by Plesk Git on the Waxine hosting.

| | Live | Staging |
|---|---|---|
| Address | https://iou.waxine.nl | https://staging-iou.waxine.nl |
| Branch | `main` | `staging` |
| Plesk pulls into | `~/iou-repo` | `~/iou-staging-repo` |
| Web root | `~/iou` | `~/iou-staging` |
| Post-deploy action | `sh deploy/deploy.sh` | `sh deploy/deploy.sh ../iou-staging` |

`deploy.sh` copies `site/` into the web root. `connection.php` holds the
database login and the statistics password; it is not in the repository and
is never touched on live.

`maintenance/` (`migrate.php`, `opschonen.php`) is kept in the repository but
never deployed: `opschonen.php` empties the whole database. To use one, copy
it into the web root by hand and remove it again afterwards.

## Staging

- Private: closed with a Plesk password-protected directory, search engines
  are told not to index it, and every page shows a staging bar. See
  `staging_guard.php`.
- Own database and user. `~/iou-staging/staging_db.php` (server only, never in
  the repository) holds the staging login:

  ```php
  <?php
  $username = '...';
  $password = '...';
  $dbname   = '...';
  ```

  `deploy.sh` builds the staging `connection.php` from the live one plus
  this file.
- Every night `refresh_staging.php` (Plesk scheduled task) replaces the
  staging database with a copy of live. Anything entered on staging is
  overwritten.

## Workflow

Push to `staging`, check on staging-iou.waxine.nl, then merge `staging` into
`main` to go live.
