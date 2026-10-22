English | [中文](/README.md)

# NexusPHP Forum Edition (No Torrent · No Redis · No Composer)

A forum build based on [NexusPHP](https://nexusphp.org/) 1.10.2 (Laravel 12 + FilamentPHP 5).

- Torrent upload/download/tracker features removed. Forum, users, messages, medals, attendance, exams, admin panel and the original layout are kept.
- **No Redis required.** Cache, sessions, rate limiting and locks use a local file store. Only **PHP + MySQL** are needed.
- **No Composer required.** `vendor/` is bundled (production dependencies only), upload and run.

## Requirements

- PHP 8.2 – 8.5 with extensions: `bcmath ctype curl fileinfo json mbstring openssl pdo_mysql tokenizer xml mysqli gd gmp zip intl sqlite3 pdo_sqlite`
- MySQL 5.7.8+ / 8.0+ (8.0+ recommended)
- Document root must point to `public/`
- No Redis, Composer or Node.js

## Quick start

1. Upload this directory to your server.
2. Point the website document root to `public/`.
3. Nginx: `try_files $uri $uri/ /nexus.php?$query_string;`
4. Open `/install/install.php` and fill in the MySQL connection.
5. Delete `public/install/` after installation.
6. Add cron jobs: `php artisan schedule:run` every minute and `php public/cron.php` every 5 minutes.

See the detailed Chinese guide: [INSTALL-zh.md](INSTALL-zh.md).

## Admin panel

```
https://your-domain/nexusphp
```

## License

GPL-2.0, following the upstream project: https://github.com/xiaomlove/nexusphp
