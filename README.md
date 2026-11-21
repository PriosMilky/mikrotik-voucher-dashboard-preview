# MikroTik Voucher Dashboard (PHP + XAMPP)

Starter project for RouterOS Hotspot vouchers using the MikroTik RouterOS API over API-SSL.

## Local demo preview
Set `DEMO_MODE=true` to simulate three Hotspot profiles, two online users, and
voucher creation without opening a connection to a MikroTik router. The demo
WhatsApp page displays the prepared message locally and never redirects to
WhatsApp. Demo vouchers are still stored in MariaDB, with their passwords
encrypted using `APP_KEY`. Use fictitious customer data in this preview.

## Important behavior
- `limit-uptime=10h` means 10 hours of cumulative connected time, not 10 hours from first login.
- The router enforces the limit. PHP does not need to keep a timer running.
- Voucher usernames/passwords are generated randomly.
- The application stores voucher batch metadata and AES-256-GCM encrypted voucher passwords in MariaDB. Keep `APP_KEY` secret and backed up; losing it means saved voucher passwords cannot be decrypted.
- This is a starter implementation. Put it behind a trusted management LAN/VPN, use HTTPS for the web app, and change all default credentials.

## Requirements
- XAMPP with PHP 8.1+ (check `php -v`)
- Apache, MySQL/MariaDB
- RouterOS 7.x recommended
- MikroTik API-SSL enabled on TCP 8729 and restricted to this PC's LAN IP
- Composer
- PHP extensions `sockets` and `openssl` enabled in XAMPP (`php.ini`)

## 1. Install dependency
Open CMD in this project directory:
```bat
composer require evilfreelancer/routeros-api-php
```
The code uses `evilfreelancer/routeros-api-php`. Its repository currently displays an upstream maintenance notice, so pin/test the installed Composer version and review alternatives before a long-lived production deployment. API usage in `app/Router.php` follows the package's documented Client/Query interface.

## 2. Database
Create a database in phpMyAdmin named `mikrotik_vouchers`, then import `database/schema.sql`.

## 3. Configure
Copy `.env.example` to `.env` and fill:
- `APP_KEY`: random long string
- `DB_*`: MariaDB connection
- For the local demo, set `DEMO_MODE=true`. No router connection is made until a router configuration is saved from the dashboard.

Do not expose `.env` through Apache. Recommended: place project outside `htdocs` and set Apache DocumentRoot to the `public` directory. For a quick local test, if project is in `htdocs/mikrotik-voucher-dashboard`, browse `/public/`, but ensure `.env`, `vendor`, and `database` are not web-accessible. Prefer a virtual host with DocumentRoot `.../public`.

After signing in, use **Koneksi MikroTik dari PC ini** on the dashboard:
1. Enter the router's private LAN IPv4 address, API port, and dedicated API username/password.
2. Leave API-SSL unchecked for plain API on port `8728`, or enable it and enter the router's API-SSL port (commonly `8729`).
3. Select **Simpan konfigurasi**, then **Test Connection**. A saved configuration takes precedence over `DEMO_MODE`; the demo remains active until a router configuration is saved.
4. The password is encrypted with `APP_KEY` in `storage/router-config.json`, outside the `public` document root. Keep `.env`/`APP_KEY` and the storage folder private and backed up. The dashboard never fills the saved password back into the form.

The PC running Apache/PHP is the device that connects to the router. It must be on the management LAN or have a valid route to it. Do not enter a router IP that is reachable only from the browser's PC if Apache is running on another computer.

## 4. RouterOS preparation
First verify the actual LAN management address and interface. Do not paste blindly into a production router.

Example (replace `192.168.88.10` with the XAMPP PC's fixed management IP):
```routeros
/user group add name=voucher-api policy=read,write,api,!local,!telnet,!ssh,!ftp,!reboot,!policy,!test,!winbox,!password,!web,!sniff,!sensitive,!romon
/user add name=voucherapp group=voucher-api password="REPLACE_WITH_LONG_RANDOM_PASSWORD"

/ip service set api-ssl disabled=no port=8729 address=192.168.88.10/32
/ip firewall filter add chain=input action=accept protocol=tcp dst-port=8729 src-address=192.168.88.10 comment="Allow voucher dashboard API-SSL"
```
If you intentionally use unencrypted API instead, enable port `8728` and apply an equally narrow source-IP restriction:
```routeros
/ip service set api disabled=no port=8728 address=192.168.88.10/32
/ip firewall filter add chain=input action=accept protocol=tcp dst-port=8728 src-address=192.168.88.10 comment="Allow voucher dashboard API"
```
Important:
- Prefer API-SSL. Plain API sends credentials without TLS protection; use it only on a trusted, isolated management LAN.
- RouterOS API-SSL requires a certificate. Create/import a suitable certificate and bind it to the `api-ssl` service (`/ip service set api-ssl certificate=...`). Use a certificate trusted by the PHP host in production.
- Add the allow rule before any final input-chain drop rule.
- Do not expose TCP 8729 to WAN.
- RouterOS policies can vary by version; test the least-privilege group. Do not grant `sensitive` unless your workflow demonstrably needs it.
- Set a static DHCP lease or static IP for the XAMPP PC.

Example certificate creation (test/self-signed only; production should use a trusted certificate):
```routeros
/certificate add name=api-ca common-name=router-api-ca key-usage=key-cert-sign,crl-sign
/certificate sign api-ca
/certificate add name=api-server common-name=192.168.88.1 subject-alt-name=IP:192.168.88.1 key-usage=digital-signature,key-encipherment,tls-server
/certificate sign api-server ca=api-ca
/ip service set api-ssl certificate=api-server
```
Replace IP with router management IP. For production, use a certificate trusted by the PHP host; never disable TLS verification as a permanent fix. The connection form keeps TLS certificate verification enabled.

## 5. Run
Start Apache and MySQL in XAMPP. Browse to the virtual host, e.g. `http://voucher.local`.
The starter has no public self-registration. Create a strong admin password hash with:
```bat
php -r "echo password_hash('REPLACE_WITH_LONG_PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"
```
Insert it into `admins`:
```sql
INSERT INTO admins(username,password_hash) VALUES ('admin','PASTE_HASH');
```

## 6. Operational notes
- Use a Hotspot user profile with `shared-users=1` (or your intended value).
- The app assigns the selected profile and `limit-uptime=10h`.
- `limit-uptime` counts accumulated connected time. RouterOS disables the user after the total uptime reaches the limit.
- Deleting a voucher in the app deletes it from the router. Use disable first if you want reversible action.
- API-SSL connectivity is a privileged management-plane path. Restrict by firewall and network segmentation.
- If Test Connection fails, confirm that the PC running Apache can reach the router's LAN IP, the matching RouterOS API service and port are enabled, the API user has the required `api`, `read`, and `write` policies, and the router firewall allows the PC's source IP. For API-SSL, verify that the certificate is trusted and matches the IP/hostname used.
- Before production, add HTTPS for the dashboard, scheduled encrypted DB backups, audit review, CSRF review, and certificate validation.

## Customer + WhatsApp workflow (updated)
- Operator enters customer name, Indonesian WhatsApp number, and Hotspot profile.
- Each transaction creates a new client record and a daily sequential client number: `CC-YYMMDD-001`, `CC-YYMMDD-002`, etc.
- A returning customer receives a new client number for each new transaction, as requested.
- Each generated Hotspot user receives `limit-uptime=10h`.
- The WhatsApp button opens Click-to-Chat with a prefilled message and voucher credentials. The operator must press Send in WhatsApp; the app cannot verify delivery.
- After returning to the dashboard, the operator can mark the batch as sent. This is an operator confirmation, not WhatsApp delivery confirmation.
- Each customer transaction creates exactly one voucher: one unique username/ID and one password. The quantity is fixed to 1 by the server.
- Printing has been removed from the primary workflow.

### Existing database upgrade
If you already imported the earlier schema, back up the database first, then import `database/migration-client-whatsapp.sql` once using phpMyAdmin. Existing historical batches remain without client records; new transactions use the customer workflow. For a brand-new installation, import the updated `database/schema.sql` instead and do not run the migration file.
