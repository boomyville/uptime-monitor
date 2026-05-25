# Uptime Monitor

A lightweight PHP uptime dashboard that tracks website status, stores ping history in MySQL/MariaDB, and shows response-time graphs in the browser.

## Prerequisites

- PHP 8+ with the `curl` extension enabled
- MariaDB or MySQL
- A web server or PHP runtime that can serve `index.php`
- Cron access for the periodic monitor job
- Docker if you are running the monitor inside the `swag` container setup used by `setup.sh`

## Folder Structure

```text
uptime-monitor/
├── config.php
├── index.php
├── init.sql
├── pinger.php
├── setup.sh
├── .gitignore
└── README.md
```

## What Each File Does

### `index.php`
The main dashboard page.

- Lists all monitored websites
- Shows current status codes
- Loads response-time graphs from the database
- Lets you add a site
- Lets you manually ping a site
- Lets you delete a site with confirmation
- Lets you clean up old logs
- Lets you configure quiet time hours
- Lets you enter Telegram bot and chat details for alerts
- Lets you send a test Telegram message from the dashboard

The graph library is loaded from a CDN in this file. It currently uses ApexCharts.

### `pinger.php`
The scheduled monitor script.

- Reads active sites from the database
- Sends HTTP requests to each site
- Stores status codes and response times in `logs`
- Updates `sites.last_status`
- Respects the quiet time window from the saved settings
- Optionally sends Telegram alerts based on your config values

### `config.php`
Database connection and PHP runtime settings.

- Defines database credentials
- Creates the PDO connection helper used by the other scripts
- Enables error reporting for debugging
- Optionally defines `NOTIFIER_URL` so Telegram messages can link back to the dashboard

Create this file locally and add your own database details. It is ignored by git because it contains local secrets.

```php
<?php
// Database Credentials
define('DB_HOST', 'your-db-host');
define('DB_NAME', 'your-db-name');
define('DB_USER', 'your-db-user');
define('DB_PASS', 'your-db-password');

// Error Reporting (Turn off in production if you want)
error_reporting(E_ALL);
ini_set('display_errors', 1);

function getDbConnection() {
	$dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
	try {
		$pdo = new PDO($dsn, DB_USER, DB_PASS, [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES => false,
		]);
		return $pdo;
	} catch (PDOException $e) {
		die("Database connection failed: " . $e->getMessage());
	}
}

// Optional absolute URL for Telegram links back to the dashboard.
define('NOTIFIER_URL', 'https://your-host.example/uptime-monitor');
```

Make sure the file exists at the project root as `config.php` before running the dashboard.

### `init.sql`
Database bootstrap script.

- Creates the `uptime` database
- Creates the `sites`, `logs`, and `config` tables
- Seeds initial config values such as timezone and Telegram settings

### `setup.sh`
Container cron setup helper.

- Verifies that `pinger.php` exists inside the `swag` container
- Installs the cron entry that runs the monitor every 5 minutes
- Is useful when your dashboard and cron job are running inside the container-based setup

### `.gitignore`
Prevents local secrets from being committed.

- Ignores `config.php`

## Setup

1. Import the schema:

```bash
mysql -u <user> -p < init.sql
```

2. Update `config.php` with your database credentials.

3. Make sure `config.php` is available to the dashboard and pinger script.

4. Open `index.php` in your browser through your PHP web server.

## Cron / Scheduling

You have two options:

- Use `setup.sh` if you are running the monitor inside the `swag` Docker container
- Add your own cron job that runs `php /path/to/pinger.php` every 5 minutes

If you are using the container setup, `setup.sh` installs the cron entry for you. If your cron is already configured, you do not need to run it again.

## Notes

- The dashboard graph library is loaded from a CDN, so no local npm install is required
- Manual pinging is done from the dashboard UI and writes a fresh log entry
- Deleting a site removes the site record and its related logs through the database foreign key cascade
