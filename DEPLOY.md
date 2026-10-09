# Deploy + security notes (WordPress platform)

This server hosts many apps. The WordPress platform gets its own
database and its own database user - nothing else on the box may read
or write it.

## 1. Database setup (run once)

```
CREATE DATABASE `wordpress-googlesearchconsole`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'gscwp_user'@'localhost' IDENTIFIED BY '<STRONG PASSWORD>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE
  ON `wordpress-googlesearchconsole`.* TO 'gscwp_user'@'localhost';
FLUSH PRIVILEGES;
```

No DROP, no GRANT, no access to any other database. Then run the
migration as this user:

```
mysql -u gscwp_user -p wordpress-googlesearchconsole < 01-wp-sites.sql
```

## 2. Panel credentials

Set as real environment variables (or in the panel's env file) - do
not commit them:

```
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=wordpress-googlesearchconsole
DB_USER=gscwp_user
DB_PASS=<STRONG PASSWORD>
```

The code never prints these; errors go to the server error log.

## 3. What register-site.php allows (and refuses)

| Rule | Value |
|---|---|
| Method | POST only |
| Body | JSON, max 4 KB |
| site_url | full http(s) URL, host required |
| Rate limit | 30 attempts per hashed IP per hour |
| SQL | prepared statements only |
| Errors | logged server-side, client gets a short message |
| Tables it can touch | WpSite, gscwp_rate_log - nothing else |

## 4. The plugin's panel URL

Default (production): https://makkpressapps.com/wordpress/googlesearchconsole/

Only override it in wp-config.php for a STAGING panel, and only with a
full https:// or http://host:port URL. A filesystem path such as
/var/www/html/... is rejected by the plugin and the default is used -
an earlier build once built broken links from exactly such an
override.

## 5. Checklist before going live

- [ ] dedicated DB user in place (no root)
- [ ] migration run twice, second run reports present
- [ ] register-site tested: valid site registers, junk gets 422/429
- [ ] error log free of warnings during the flow
- [ ] panel served over HTTPS only
