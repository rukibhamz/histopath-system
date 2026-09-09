# Deployment Guide
## National Hospital Abuja — Histopathology Records System

This guide covers deploying the system on a dedicated intranet server, from a blank machine to a running, backed-up, auto-starting service.

---

## 1. Server Requirements

- A dedicated machine (physical or VM) that stays powered on — not a shared workstation or laptop
- Ubuntu Server 22.04 or 24.04 LTS recommended (instructions below assume this; adjust package manager commands for other distros)
- Minimum: 2 CPU cores, 4GB RAM, 40GB storage — comfortably enough for a system with under 10 concurrent users
- A UPS on the server, and BIOS/UEFI set to auto-power-on after power loss (Settings vary by hardware — look for "Restore on AC Power Loss" or "After Power Failure")
- A static local IP address on your intranet (e.g. `192.168.1.50`) — set this as a DHCP reservation on your router so it never changes

---

## 2. Choose and Prepare the Database

The system runs on either **SQLite** or **PostgreSQL**. Pick one before you go further.

| | SQLite | PostgreSQL |
|---|---|---|
| Server to install and maintain | None | Yes |
| Where the data lives | One file | A database service |
| Backup | Copy the file | `pg_dump` |
| Database must be on the web server | Yes | No |
| Simultaneous writers | One at a time (queued) | Many |

**For a single department of under ten people on one server, choose SQLite.** There is
nothing to install, nothing to patch, no database password to leak, and a backup is one
file. Choose PostgreSQL if the database must live on a separate machine, if many people
will save reports at the same instant, or if your IT team already runs PostgreSQL backups.

Either way, the setup wizard in Step 5 creates all the tables for you.

---

### Option A: SQLite (recommended for this deployment)

Install the driver:
```bash
sudo apt install php-sqlite3 -y
sudo systemctl restart apache2
```

Create a folder for the database **outside the web root**, owned by the web server:
```bash
sudo mkdir -p /var/lib/histopath
sudo chown www-data:www-data /var/lib/histopath
sudo chmod 750 /var/lib/histopath
```

That's all. The wizard asks for the file location (suggesting one for you), creates the
file, and builds the tables.

> **Never put the database file inside `/var/www`.** Anything under the web root can be
> downloaded by anyone who can reach the site, and this file contains every patient record.
> The wizard refuses such a location, but if you create the file by hand, the responsibility
> is yours.

To create it by hand instead:
```bash
sudo -u www-data sqlite3 /var/lib/histopath/histopath.sqlite < schema.sqlite.sql
```

---

### Option B: PostgreSQL

```bash
sudo apt update
sudo apt install postgresql postgresql-contrib php-pgsql -y

# Confirm it's running and enabled on boot
sudo systemctl enable postgresql
sudo systemctl status postgresql
```

#### Create the database and user

```bash
sudo -u postgres psql
```
```sql
CREATE DATABASE histopath_system;
CREATE USER histopath_app WITH PASSWORD 'choose_a_strong_password_here';
GRANT ALL PRIVILEGES ON DATABASE histopath_system TO histopath_app;
\q
```

#### Allow connections from your app server (skip if PHP runs on the same machine)

Edit `/etc/postgresql/*/main/postgresql.conf`:
```
listen_addresses = '*'
```

Edit `/etc/postgresql/*/main/pg_hba.conf`, add a line for your intranet subnet:
```
host    histopath_system    histopath_app    192.168.1.0/24    scram-sha-256
```

Restart:
```bash
sudo systemctl restart postgresql
```

#### Loading the schema

You do **not** need to do this by hand &mdash; the wizard creates every table when it first
connects. Load it manually only if the database user you gave the app is not allowed to
create tables:
```bash
psql -h localhost -U histopath_app -d histopath_system -f schema.sql
```
If you do, delete the seed `admin` row afterwards &mdash; the wizard creates your real
administrator account:
```sql
DELETE FROM users WHERE username = 'admin' AND password_hash = 'CHANGE_ME_HASH';
```

---

### Upgrading an existing PostgreSQL installation

If you already loaded `schema.sql` on an earlier version of this system, run the upgrade
script (reloading the full schema would destroy your data):

```bash
psql -h localhost -U histopath_app -d histopath_system -f schema_upgrade.sql
```

It adds the `login_attempts` table, the indexes, and the `updated_at` triggers, and is safe
to run more than once. The setup wizard also recognises an existing database and leaves its
data alone.

---

## 3. Install PHP and Apache

```bash
sudo apt install apache2 php libapache2-mod-php -y
# Plus the driver for the database you chose in Step 2:
sudo apt install php-sqlite3 -y     # Option A
# sudo apt install php-pgsql -y     # Option B
sudo systemctl enable apache2
sudo systemctl status apache2
```

Confirm the driver is active:
```bash
php -m | grep -E 'sqlite|pgsql'
```
You should see `pdo_sqlite` (Option A) or `pgsql` and `pdo_pgsql` (Option B). If missing,
install the package above and restart Apache. The setup wizard also checks this for you and
says exactly what to run.

---

## 4. Deploy the Application Files

```bash
# Extract the project into Apache's web root
sudo unzip histopath_system.zip -d /var/www/
sudo mv /var/www/system /var/www/histopath

# Set ownership so Apache can read/write as needed
sudo chown -R www-data:www-data /var/www/histopath
sudo chmod -R 755 /var/www/histopath
```

### Database connection

There is nothing to edit. The setup wizard (Step 5) asks for these details and writes
`includes/config.php` itself.

To configure it by hand instead, copy `includes/config.sample.php` to
`includes/config.php` and fill in your values &mdash; the wizard then steps aside, because
the presence of that file is what marks the system as installed.

### Configure Apache to serve the app

Create `/etc/apache2/sites-available/histopath.conf`:
```apache
<VirtualHost *:80>
    ServerName histopath.intranet
    DocumentRoot /var/www/histopath

    <Directory /var/www/histopath>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/histopath_error.log
    CustomLog ${APACHE_LOG_DIR}/histopath_access.log combined
</VirtualHost>
```

`AllowOverride All` matters: the app ships `.htaccess` files that block direct web access to
`schema.sql`, `DEPLOYMENT.md`, and the whole `includes/` directory (which holds your database
password). Without it those files would be downloadable by anyone who can reach the site.

Enable the site and reload:
```bash
sudo a2ensite histopath.conf
sudo a2dissite 000-default.conf
sudo systemctl reload apache2
```

### Make it reachable by name (optional but recommended)

On your intranet DNS server (or each client's `hosts` file if you don't run one), point `histopath.intranet` to the server's static IP. Otherwise staff just use `http://192.168.1.50/`.

---

## 5. First-Run Setup

Open a browser on any machine on the intranet and go to the server, e.g.
`http://histopath.intranet/` or `http://192.168.1.50/`. Because nothing is configured yet,
you land on the setup wizard automatically. It has four steps:

1. **Server check** &mdash; confirms PHP's version, the PostgreSQL driver, and that the
   `includes/` folder is writable. Anything missing is listed with the command that fixes it.
2. **Database** &mdash; choose SQLite or PostgreSQL. For SQLite, give the file location
   (it suggests one outside the web root and refuses anything inside it); for PostgreSQL,
   the connection details from Step 2. Either way it tests the connection before continuing
   and creates the tables if they don't exist. If it finds tables already there, it uses them
   and does not touch your data.
3. **Administrator** &mdash; your name, email, username and password. There is no
   "forgot password" email, so record this somewhere safe.
4. **Hospital & network** &mdash; the hospital and department names for the report
   letterhead, and the address other computers use to reach the system. The wizard suggests
   one based on how you reached the page; if it says `localhost`, replace it with the
   server's fixed address (`http://192.168.1.50/`), because other machines cannot use
   `localhost`.

The final screen shows that address so you can circulate it to staff. It is stored as a
setting and can be changed later under **System Settings**.

### Immediately after setup

```bash
# The wizard refuses to run again once includes/config.php exists,
# but removing it entirely is better.
sudo rm /var/www/histopath/install.php
```

Then sign in and:

1. Go to **Manage Users** and create your reviewer and staff accounts &mdash; each gets a
   temporary password shown once, and must set their own on first login
2. Go to **System Settings** for colours and a logo
3. If you have legacy Access data, go to **Import Legacy Records** (export each Access table
   to CSV first)

---

## 6. Keeping the System Running

Nobody should have to log into the server and start anything by hand. After a reboot or a
power cut, the machine must come back on its own with the system already serving.

There are three separate things to get right.

### 6.1 Start the services at boot

`systemctl start` runs a service now. `systemctl enable` makes it start at every boot. You
need **enable** — starting it once is not enough, and this is the single most common reason
a system "stops working" after a power cut.

```bash
sudo systemctl enable apache2
sudo systemctl enable postgresql    # Option B only - SQLite has no service to start
```

Confirm:
```bash
systemctl is-enabled apache2        # should print: enabled
systemctl is-active apache2         # should print: active
```

> **SQLite users:** there is no database service at all. The data is a file the web server
> opens directly, so Apache starting is the only thing that has to happen. That is one of
> the reasons SQLite suits a site with thin IT cover.

### 6.2 Restart automatically if it crashes

Enabling at boot handles restarts. It does **not** handle Apache dying while the machine
stays up — by default systemd leaves it dead. Tell it to bring Apache back:

```bash
sudo systemctl edit apache2
```

Put this in the editor that opens, then save:
```ini
[Service]
Restart=on-failure
RestartSec=5s
```

Apply it:
```bash
sudo systemctl daemon-reload
sudo systemctl restart apache2
```

Now a crashed Apache is back within five seconds, without anyone noticing.

### 6.3 Come back after a power cut

- Fit a **UPS**, so brief cuts never reach the machine at all.
- Set the BIOS/UEFI to power on when mains returns — look for *Restore on AC Power Loss* or
  *After Power Failure* and set it to **Power On**. The default on most machines is
  *Stay Off*, which is exactly wrong for a server.

### 6.4 Prove it actually works

Do not assume. Reboot the server once, deliberately, before it goes into service:

```bash
sudo reboot
```

Wait, then from a **different** machine on the intranet open the system's address. If it
loads and you can log in, it is genuinely automatic. If it doesn't, something above was
missed — find out now rather than during a power cut on a working day.

---

### Running on Windows (XAMPP)

If you are running this on Windows rather than Ubuntu, the XAMPP Control Panel starts
Apache **only for as long as you leave it open**, and nothing comes back after a restart.
Install Apache as a Windows service instead, which starts it automatically at boot.

**Using the Control Panel:** close XAMPP, right-click `xampp-control.exe`, choose
**Run as administrator**, then tick the checkbox to the left of *Apache*. The red cross
becomes a green tick and the service is registered as Automatic.

**Or from an Administrator Command Prompt:**
```
C:\xampp\apache\bin\httpd.exe -k install -n "Apache2.4"
sc config Apache2.4 start= auto
net start Apache2.4
```

Check it took:
```
sc qc Apache2.4
```
`START_TYPE` should read `2   AUTO_START`. Then restart Windows and confirm the site is
reachable from another machine without anyone logging in.

To undo it:
```
net stop Apache2.4
C:\xampp\apache\bin\httpd.exe -k uninstall -n "Apache2.4"
```

> Windows is fine for trying the system out. For the live deployment prefer the Ubuntu
> setup in this guide: unattended security updates, cleaner service management, and it
> doesn't invite anyone to use the server as a desktop.

---

## 7. Backups (do this before real data goes in)

### Automated nightly database backup

Create `/usr/local/bin/backup_histopath.sh` for the database you chose.

**SQLite (Option A).** Copy the file with `sqlite3 .backup`, not `cp` &mdash; the system runs
in WAL mode, so a plain copy taken mid-write can be inconsistent:
```bash
#!/bin/bash
BACKUP_DIR="/mnt/backup-drive/histopath"   # point this at a DIFFERENT physical drive
DATE=$(date +%Y-%m-%d)
mkdir -p "$BACKUP_DIR"
sqlite3 /var/lib/histopath/histopath.sqlite ".backup '$BACKUP_DIR/histopath_$DATE.sqlite'"

# Keep 30 days of backups, delete older ones
find "$BACKUP_DIR" -name "histopath_*.sqlite" -mtime +30 -delete
```

**PostgreSQL (Option B):**
```bash
#!/bin/bash
BACKUP_DIR="/mnt/backup-drive/histopath"   # point this at a DIFFERENT physical drive
DATE=$(date +%Y-%m-%d)
mkdir -p "$BACKUP_DIR"
pg_dump -h localhost -U histopath_app histopath_system > "$BACKUP_DIR/histopath_$DATE.sql"

find "$BACKUP_DIR" -name "histopath_*.sql" -mtime +30 -delete
```

Then schedule it:
```bash
sudo chmod +x /usr/local/bin/backup_histopath.sh
sudo crontab -e
```
Add a nightly run (2 AM). SQLite needs no password:
```
0 2 * * * /usr/local/bin/backup_histopath.sh
```
PostgreSQL does:
```
0 2 * * * PGPASSWORD='the_db_password' /usr/local/bin/backup_histopath.sh
```

### Test the restore — do this once, now, not after something breaks

**SQLite:** open the backup and confirm the records are in it.
```bash
sqlite3 /mnt/backup-drive/histopath/histopath_2026-01-01.sqlite "SELECT COUNT(*) FROM histology_reports;"
```
To restore for real: stop Apache, copy the backup over
`/var/lib/histopath/histopath.sqlite`, make sure it is owned by `www-data`, start Apache.

**PostgreSQL:**
```bash
psql -h localhost -U histopath_app -d histopath_system_test -f /mnt/backup-drive/histopath/histopath_2026-01-01.sql
```
(Create a throwaway `histopath_system_test` database first if you want to test without
touching production.)

### Point backups at a genuinely separate location

A backup on the same drive as the live database doesn't protect you from a drive failure. Ideally: a second physical drive in the same machine at minimum, and ideally a copy also stored on a separate machine or removable media taken offsite periodically.

---

## 8. Ongoing Maintenance Checklist

- [ ] Confirm the nightly backup cron job is actually producing new files (`ls -la /mnt/backup-drive/histopath/`)
- [ ] Periodically review **Access Logs** in the admin panel for unexpected activity
- [ ] Deactivate accounts promptly when staff leave (never delete — preserves audit history)
- [ ] Keep the server's OS and PHP/PostgreSQL packages patched: `sudo apt update && sudo apt upgrade`
- [ ] Confirm the UPS is functioning and the server survives a simulated power cut gracefully
- [ ] After any server maintenance, confirm `systemctl is-enabled apache2` still says
      `enabled` - some upgrades and manual fixes quietly leave a service disabled
- [ ] Confirm `install.php` has been deleted from the server
- [ ] Check that `http://<server>/schema.sql` and `http://<server>/includes/db_connect.php`
      both return "Forbidden" - if either downloads, `AllowOverride All` is not set (Step 4)
- [ ] SQLite only: confirm the database file is still outside the web root, and that
      `http://<server>/histopath.sqlite` (and similar) returns "Not Found"
- [ ] Trim old rows from `login_attempts` occasionally if it grows:
      `DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL '90 days';`

---

## 9. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| "Database connection failed" | Wrong credentials in `includes/config.php`, or PostgreSQL not accepting connections | Check `pg_hba.conf`/`postgresql.conf`, confirm `systemctl status postgresql` |
| Blank white page | PHP error with display_errors off (correct for production) | Check `/var/log/apache2/histopath_error.log` |
| Can't reach the site from other machines | Firewall blocking port 80, or Apache only listening on localhost | `sudo ufw allow 80/tcp` (only opened to your intranet, never the internet) |
| Forced into password reset loop | `must_reset_password` not clearing — check the `change_password.php` update query ran | Verify in `psql`: `SELECT must_reset_password FROM users WHERE username = '...'` |
| "This request could not be verified" | The login page sat open long enough for the session to expire, or the browser is blocking cookies | Reload the page and submit again |
| "Too many failed attempts" | Five wrong passwords for that username from that machine within 15 minutes | Wait 15 minutes, or have an admin use **Reset Password** (which clears the lockout immediately) |
| Colors/hospital name not changing | Browser cached the old page | Hard-reload (Ctrl+F5); settings apply immediately server-side |
| Import reports many skipped rows | Rows missing a Lab No, or Lab Nos already present | The result screen lists the first 10 row errors with reasons - one bad row no longer aborts the rest of the file |
| Every page redirects to the setup wizard | `includes/config.php` is missing or was deleted | Run the wizard again, or recreate the file from `includes/config.sample.php` |
| Wizard says "already set up" | `includes/config.php` exists | That is the safeguard working. Delete that file first if you genuinely mean to reconfigure |
| Wizard can't write `includes/config.php` | Apache doesn't own the folder | `sudo chown -R www-data:www-data /var/www/histopath` &mdash; or copy the file contents the wizard displays and create it by hand |
| Staff can't reach the address shown | The network address is set to `localhost` | Change it under **System Settings** to the server's fixed IP or hostname |
| "Database connection failed" on SQLite | Apache can't write the file or its folder | `sudo chown -R www-data:www-data /var/lib/histopath` &mdash; the folder must be writable too, not just the file, because WAL creates files beside it |
| "attempt to write a readonly database" | Same cause as above | As above |
| "database is locked" during a large import | Another write was in progress | The system waits up to 5 seconds automatically; if it persists, import in smaller files, or use PostgreSQL |
| Site is down after a reboot or power cut | Apache was started but never enabled | `sudo systemctl enable apache2`, then reboot and re-test (Section 6) |
| Site works until the XAMPP window is closed | Apache is running as an application, not a Windows service | Install it as a service (Section 6) |
| Server stays off after the power returns | BIOS is set to stay off after AC loss | Set *Restore on AC Power Loss* to **Power On** (Section 6.3) |

---

## Security Reminder

This system holds patient clinical data. Keep it strictly intranet-only — do not port-forward it to the public internet, and do not expose PostgreSQL's port (5432) or the web server outside your local network under any circumstances.
