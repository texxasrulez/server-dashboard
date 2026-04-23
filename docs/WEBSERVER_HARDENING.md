# Web Server Hardening

Block direct HTTP access to runtime/config directories even if application code has guards.

## Nginx

Add inside your server block:

```nginx
location ~ ^/(config|data|state)(/|$) {
    deny all;
    return 403;
}
```

## Apache (vhost)

```apache
<Directory "/path/to/server-dashboard/config">
    Require all denied
</Directory>
<Directory "/path/to/server-dashboard/data">
    Require all denied
</Directory>
<Directory "/path/to/server-dashboard/state">
    Require all denied
</Directory>
```

## Apache (`.htaccess` alternative)

```apache
RewriteEngine On
RewriteRule ^(config|data|state)(/|$) - [F,L]
```

## IIS

`web.config` in this repo already hides `config`, `data`, and `state` via `hiddenSegments`.

## Verify

After deployment, these should return `403`:

- `/config/local.json`
- `/data/users.json`
- `/state/backup_status.json`
