# Deploying Brewski

Brewski runs as two services on [Render](https://render.com), with the database on
[Aiven](https://aiven.io).

| Piece | Where it runs | Why |
| --- | --- | --- |
| PHP app | Render, Docker | Render has no native PHP runtime, so it builds from `Dockerfile` |
| Email service | Render, Node | Already a Node/Express app; runs on Render's native Node runtime |
| MySQL database | Aiven | Render's managed databases are PostgreSQL only |

Render reaches Aiven over the public internet, so the app connects with TLS.

## 1. Create the Aiven MySQL service

1. Sign in to the Aiven console and create a **MySQL** service. The free plan is enough
   to start.
2. Pick the region closest to your Render region so query latency stays low.
3. When it is running, open the service's **Overview** page and note these four values:

   - **Host** (looks like `mysql-1a2b3c4d-yourname.aivencloud.com`)
   - **Port** (Aiven assigns its own port, **not** 3306)
   - **User** (defaults to `avnadmin`)
   - **Password** (click to reveal)

4. On the same page, download the **CA certificate** (`ca.pem`) if you want the
   connection to verify Aiven's certificate. This is optional — see step 5.

Aiven enforces `require_secure_transport`, so a plaintext connection is rejected. That is
why `DB_SSL` is set to `1` in `render.yaml`.

## 2. Load the schema

Use `Db/schema.aiven.sql`, **not** `Db/schema.sql`.

XAMPP runs MariaDB, and `Db/schema.sql` ends with `ALTER TABLE ... ADD COLUMN IF NOT
EXISTS` statements. That syntax is MariaDB-only and raises a syntax error on Aiven's
MySQL 8. `Db/schema.aiven.sql` contains only the portable part of the same file, so it
loads cleanly on both engines.

From the Aiven console, open the **Query editor** (or **Databases** tab) and run the whole
contents of `Db/schema.aiven.sql`. Or use the MySQL client:

```sh
mysql --host=<HOST> --port=<PORT> --user=avnadmin --password --ssl-mode=REQUIRED \
  < Db/schema.aiven.sql
```

The script creates the `brewski_db` database and all 21 tables, then seeds the default
rows in `settings`.

If Aiven reports that `CREATE DATABASE` is not permitted, delete lines 1 and 3 of
`Db/schema.aiven.sql` (the `CREATE DATABASE` and `USE` statements) and run it against the
existing `defaultdb` instead, then set `DB_NAME=defaultdb` in step 4.

For an existing installation, run `brewski/Db/migrations/20261024_add_customer_system_activity.sql`
against the application database. This enables customer-role rows in the system activity
table without removing existing activity.
Run `brewski/Db/migrations/20261026_allow_store_pickup.sql` as well so pickup orders can
be saved without a delivery address.

### Keeping the two schema files in sync

`Db/schema.sql` is the **source of truth**. Keep the corresponding `CREATE TABLE`
definitions in `Db/schema.aiven.sql` in sync; the Aiven file omits the MariaDB-only
`ALTER` block.

Whenever you add a table, column, or seed row to `Db/schema.sql`, update the Aiven copy too.

## 3. Generate the authenticator key

Login uses TOTP authenticator apps, and the stored secrets are encrypted with a key that
must be exactly 64 hexadecimal characters:

```sh
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Keep this value safe and use the **same** key every deploy. Changing it makes every
existing authenticator secret unreadable, locking all enrolled users out.

### Encrypt existing personal data

Generate a **different** 64-character hexadecimal key for `BREWSKI_PERSONAL_DATA_KEY`:

```sh
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Deploy the updated application first. It detects the unmigrated schema and keeps existing
login and signup working. Then confirm that a current database backup or point-in-time
restore is available. The migration briefly pauses application database access while it
converts records. Set the database connection variables and the same encryption key where
the migration runs. On a paid Render service with shell access, run:

```sh
php /var/www/html/brewski/Db/migrations/encrypt_personal_data.php
```

The free Render plan has no shell. From a local checkout with database access, use
`php brewski/Db/migrations/encrypt_personal_data.php` instead, with `DB_HOST`, `DB_PORT`,
`DB_NAME`, `DB_USER`, `DB_PASS`, `DB_SSL=1`, and the encryption key set in that process.
The script leaves maintenance mode on if it fails so it can be safely rerun with the same
key after correcting the error.

The migration encrypts user names, emails, profile photos, and PayMongo checkout URLs;
adds a keyed email lookup index; replaces emails in lockout records with keyed lookup
values; and removes personal names and free-text descriptions from historical activity
logs. It also hashes legacy plaintext passwords. The script can be restarted with the
**same key** if interrupted. After completion, the application detects the encrypted
schema and uses the keyed email lookup. Keep `BREWSKI_PERSONAL_DATA_KEY` set to the
same value on the application service. Never put either key in Git or in the database.
Losing the key makes encrypted user data and payment links unreadable.

If a separate personal data key has not been configured, the application can derive a
domain-separated personal data key from the existing `BREWSKI_TOTP_ENCRYPTION_KEY`.
In that case, run the migration in the same service environment and do not later add a
different personal data key or rotate the TOTP key without re-encrypting the data.

## 4. Deploy to Render

1. Push this repository to GitHub.
2. In Render, choose **New → Blueprint** and point it at the repository. Render reads
   `render.yaml` and creates both services.
3. Render prompts for every value marked `sync: false`. Fill in:

   | Variable | Service | Value |
   | --- | --- | --- |
   | `DB_HOST` | brewski-app | Aiven host from step 1 |
   | `DB_PORT` | brewski-app | Aiven port from step 1 |
   | `DB_NAME` | brewski-app | `brewski_db` (or `defaultdb`) |
   | `DB_USER` | brewski-app | `avnadmin` |
   | `DB_PASS` | brewski-app | Aiven password from step 1 |
   | `DB_SSL_CA` | brewski-app | leave blank for now — see step 5 |
   | `EMAIL_SERVICE_URL` | brewski-app | fill in after step 6 |
   | `BREWSKI_TOTP_ENCRYPTION_KEY` | brewski-app | the key from step 3 |
   | `BREWSKI_PERSONAL_DATA_KEY` | brewski-app | recommended separate key; optional when deriving from the existing TOTP key |
   | `BREWSKI_PAYMONGO_SECRET_KEY` | brewski-app | PayMongo test or live secret key |
   | `BREWSKI_PAYMONGO_WEBHOOK_SECRET` | brewski-app | signing secret for the Brewski payment webhook |
   | `BREWSKI_PUBLIC_URL` | brewski-app | optional; set the public application base URL when using a custom domain |
   | `EMAIL_USER` | brewski-email | the Gmail address that sends mail |
   | `EMAIL_PASSWORD` | brewski-email | its 16-character Google app password |

4. Change `region: singapore` in `render.yaml` if you would rather deploy somewhere else.
   Both services should use the same region.

## 5. Choose how strictly to verify TLS

`DB_SSL=1` is already set, which encrypts the connection but does not verify that the
server on the other end really is Aiven. That satisfies Aiven's requirement and is fine
for getting started.

`DB_SSL_CA` is **not** where you put `REQUIRED`. It takes the path to a CA certificate
**file inside the container**, and nothing else. Setting it to a word instead of a path
makes mysqli fail with:

```
Warning: failed loading cafile stream: `REQUIRED'
Warning: mysqli::real_connect(): (HY000/2002): Cannot connect to MySQL using SSL
```

If you see that, clear `DB_SSL_CA` to blank — `DB_SSL=1` alone is enough to reach Aiven.
The current code also detects this case, logs a line to the Render log, and continues with
TLS but without certificate verification rather than failing the connection, so a stray
value here no longer takes the site down.

Note that `--ssl-mode=REQUIRED` in the `mysql` command in step 2 is a flag for that command
line client. It is not a value for `DB_SSL_CA`, despite the similar wording.

To also verify Aiven's certificate, put its `ca.pem` into the image and point `DB_SSL_CA`
at the path:

1. Save the downloaded `ca.pem` as `ca.pem` in the repository root.
2. Add this line to `Dockerfile`, directly after the `COPY brewski/ ...` line:

   ```dockerfile
   COPY ca.pem /usr/local/share/ca-certificates/aiven-ca.pem
   ```

3. Set `DB_SSL_CA` on the `brewski-app` service to
   `/usr/local/share/ca-certificates/aiven-ca.pem`.

When `DB_SSL_CA` is set it takes priority over `DB_SSL`, and the connection is verified
against that certificate.

## 6. Point the app at the email service

`brewski-email` and `brewski-app` are separate containers, so the app cannot reach the
mailer at `127.0.0.1`. That address is still the default for local work, but on Render the
app needs the real URL.

1. Once `brewski-email` is live, copy its URL from the Render dashboard
   (something like `brewski-email.onrender.com`).
2. Set `EMAIL_SERVICE_URL` on the `brewski-app` service to that value. A bare hostname is
   fine — the app prepends `https://` when the value has no scheme.
3. Redeploy `brewski-app` so it picks up the new variable.

Then place a test order and confirm a receipt email actually arrives.

## 7. Check that it works

1. Open `https://<your-app>.onrender.com/` and confirm it redirects to
   `/Start-screen/start.php` and renders with its logo and stylesheet intact. A page that
   loads but looks unstyled means the redirect was replaced with an alias, which breaks
   the page's relative paths.
2. Open `https://<your-app>.onrender.com/login-signup/signup.php` and create an account.
3. Confirm the activation email arrives — that proves `EMAIL_SERVICE_URL` is wired.
4. Activate, sign in, and complete authenticator enrolment — see step 8.
5. Walk one order through the customer, staff, and admin views.
6. Open the admin **System Settings** page and confirm it saves, which proves the `mysqli`
   connection and the `settings` table both work.

## 8. Confirm the authenticator works

Enrolment is the step most likely to break in a container, because it depends on a PHP
extension and on an environment variable that have no equivalent in XAMPP.

The page shows the secret and an `otpauth://` URI as text — there is no QR image, so no
image extension is required. In Google Authenticator, choose **Enter a setup key** and type
the displayed secret rather than scanning.

What must be true:

- **The `openssl` extension is loaded.** Authenticator secrets are encrypted with
  AES-256-GCM via `openssl_encrypt`. Without it, `login-signup/totp.php` throws and the
  setup page returns *"Authenticator setup is temporarily unavailable."* The `Dockerfile`
  checks for the extension and installs it if absent, then asserts it is present so a
  missing extension fails the build rather than the first login.
- **`BREWSKI_TOTP_ENCRYPTION_KEY` is set** to exactly 64 hex characters. If it is missing
  or malformed, enrolment fails with a message naming the variable. Confirm it in
  Render's **Environment** tab for `brewski-app`.

To verify from the running container's logs or a Render shell:

```sh
php -m | grep -E '^(openssl|mbstring|mysqli|pdo_mysql|curl)$'
php -r "var_dump(strlen(getenv('BREWSKI_TOTP_ENCRYPTION_KEY')));"
```

The first command must list all five extensions. The second must print `int(64)`.

Then, end to end: enrol an authenticator, sign out, sign back in with a fresh six-digit
code. A code that is rejected usually means the container clock has drifted — TOTP allows
only a 30-second step — or the secret was encrypted with a different key than the one now
configured.

## Environment variables

| Variable | Default | Meaning |
| --- | --- | --- |
| `DB_HOST` | `localhost` | Database host |
| `DB_PORT` | `3306` | Database port |
| `DB_NAME` | `brewski_db` | Database name |
| `DB_USER` | `root` | Database user |
| `DB_PASS` | empty | Database password |
| `DB_SSL` | off | Encrypt the connection without verifying the server certificate |
| `DB_SSL_CA` | unset | Path to a CA certificate **file**; enables TLS **and** verifies the server. Leave blank to encrypt without verifying |
| `EMAIL_SERVICE_URL` | `http://127.0.0.1:3000` | Base URL of the email service |
| `BREWSKI_TOTP_ENCRYPTION_KEY` | unset | 64 hex characters; required for authenticator login |
| `BREWSKI_PERSONAL_DATA_KEY` | unset | Recommended separate 64-hex-character key; otherwise derived from the configured TOTP key |
| `BREWSKI_PAYMONGO_SECRET_KEY` | unset | PayMongo API secret; required for customer online checkout |
| `BREWSKI_PAYMONGO_WEBHOOK_SECRET` | unset | PayMongo signing secret for `checkout_session.payment.paid` events |
| `BREWSKI_PUBLIC_URL` | unset | Public application base URL; auto-detected unless using a custom domain |
| `HOST` | `127.0.0.1` | Email service bind address; set to `0.0.0.0` on Render |
| `PORT` | `3000` | Email service port; Render sets this itself |
| `MAIL_MODE` | unset | Set to `console` to print emails in the terminal instead of sending |

## Local development

Database variables still default to XAMPP values. An unmigrated local database continues
to work with login and signup. Back it up and run the personal data migration before
expecting encrypted storage. A migrated database needs the same encryption key on every
run. With the database variables
unset, the app connects to
`localhost` / `root` / `brewski_db` over `3306` exactly as before, reads
`Db/schema.sql`, and posts to `http://127.0.0.1:3000`.

To try the email service locally, copy the example file and fill it in:

```sh
cd brewski/email-service
cp .env.example .env
```

Set `MAIL_MODE=console` in that file if you would rather see the messages printed in your
terminal than sent through Gmail.

## Things to know

- **PayMongo checkout needs a webhook.** Apply `brewski/Db/migrations/20261019_add_order_payments.sql`
  to an existing database, configure the PayMongo secret and webhook signing secret, then
  register `https://<your-public-host>/customer/checkout/paymongo_webhook.php` in the
  PayMongo dashboard for `checkout_session.payment.paid`. Use test credentials and a
  reachable HTTPS endpoint before switching to live credentials. Pending payments are not
  shown in the staff fulfillment queue until PayMongo confirms them.

- **Free instances sleep.** Render's free plan spins a service down after about 15 minutes
  of inactivity, so the first request afterwards takes a few seconds to wake it. An order
  placed during that window may time out against the email service; retrying works.
- **Keep one instance.** PHP sessions are stored on the container's own filesystem. Running
  more than one instance would send different requests to different containers and
  randomly sign people out, which is why `render.yaml` does not set a scaling rule.
- **The container filesystem is temporary.** Anything written at runtime is lost on
  redeploy. Product images uploaded through the admin catalog are stored in the database
  as `LONGBLOB` data and served through `customer/product_image.php`, so they survive. The
  `brewski/images/` folder is baked into the image at build time, which covers any older
  product that still points at an `image_path` on disk.
- **A `Db/schema.sql` change needs a matching `Db/schema.aiven.sql`**, per step 2.
- **The image installs more than XAMPP needs.** `mbstring` is used by the profile, staff,
  and admin pages, and `openssl` by the authenticator — both are on in XAMPP but not
  guaranteed in a bare PHP image, so the `Dockerfile` installs and then asserts them.
