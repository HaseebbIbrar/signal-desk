# Signal Desk: PHP version

A plain PHP 8.1+ port of Signal Desk for 20i shared hosting. There's no build step, no Composer and no Node on the server. The Next.js version lives on the `nextjs` branch.

**Workflow**
1. **Signals:** verify (append to "Signals to verify decision make") or reject (stored app-side only).
2. **Verified:** read M/S/T/U, and edit the LinkedIn link in column M.
3. **Approve:** append one row to "Decision Makers".

```
php/
├── public/            web root (upload to the subdomain's document root)
│   ├── index.php      front controller (pages + JSON API)
│   ├── health.php     login-protected setup check
│   ├── locate.inc.php finds the private folder (layout A or B)
│   ├── .htaccess      HTTPS, deny everything except entry points + assets
│   └── assets/        app.css (compiled CSS from the Next.js app), extra.css, app.js
├── private/           never web-served (outside the web root, or _private/ behind "Require all denied")
│   ├── config.example.php   copy to config.php (secrets; git-ignored)
│   ├── src/           the app
│   ├── bin/hash-password.php
│   └── storage/       runtime data (token cache, sessions, lockout, rejections, audit log)
├── tests/             php tests/run.php
└── DEPLOY-20I.md      deployment guide
```

## Local testing

```bash
cp private/config.example.php private/config.php   # mock mode; add an AUTH_USERS line (php private/bin/hash-password.php)
php tests/run.php
php -S localhost:8080 -t public public/index.php    # set APP_ORIGIN to http://localhost:8080 in config.php
```

To reset the mock data, delete `private/storage/mock-workbook.json`.
