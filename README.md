# SwiftSMS Bulk Sender (File-based)

A minimal PHP 8+ web UI and CLI worker for sending bulk SMS through the SwiftSMS API Gateway without a database. Phone numbers are persisted in per-campaign text files for easy inspection and recovery.

## Features
- Static login credentials sourced from environment variables.
- CSV upload (phone, optional name) with E.164 validation and de-duplication.
- Phone numbers stored in `storage/campaign_<id>/recipients.txt` plus JSON metadata for statuses.
- Start, stop, and resume actions per campaign; resumable after restarts.
- Background worker with rate limiting and retry support.
- Downloadable CSV report of delivery outcomes.
- CSRF protection, file size/type validation, and XSS-safe output.

## Quick start (local)
1. Ensure PHP 8+ with the cURL extension is installed.
2. Copy the example environment file and set credentials and API settings:
   ```bash
   cp sms_tool/.env.example sms_tool/.env
   ```
3. Serve the `public` folder with PHP's built-in server (for quick local testing only):
   ```bash
   php -S localhost:8080 -t sms_tool/public sms_tool/public/index.php
   ```
4. Log in with the credentials defined in `.env`, create a campaign by uploading a CSV, and click **Start** to queue it.
5. In another terminal, process queued messages:
   ```bash
   php sms_tool/worker/worker.php
   ```

## Deployment on Ubuntu (Nginx or Apache)
1. Install dependencies:
   ```bash
   sudo apt update
   sudo apt install -y php php-cli php-fpm php-curl php-zip unzip nginx
   ```
   For Apache substitute `apache2` and enable PHP as needed.
2. Place this repository on the server (e.g., `/var/www/bulk-sms`).
3. Copy `sms_tool/.env.example` to `sms_tool/.env` and set:
   - `APP_USERNAME` / `APP_PASSWORD` (login)
   - `SWIFTSMS_BASE_URL` and `SWIFTSMS_API_KEY`
   - Optional `SWIFTSMS_SENDER_ID`, `SMS_RATE_LIMIT_PER_SEC`, `SMS_MAX_ATTEMPTS`
4. Point your web server root to `sms_tool/public` using one of the sample configs in `sms_tool/config/` (update paths and server names).
5. Create a systemd service for the worker:
   ```bash
   sudo cp sms_tool/config/swiftsms-worker.service /etc/systemd/system/
   sudo systemctl daemon-reload
   sudo systemctl enable --now swiftsms-worker.service
   ```
6. Ensure `sms_tool/storage` is writable by the web and worker users (`www-data` on Ubuntu):
   ```bash
   sudo chown -R www-data:www-data /var/www/bulk-sms/sms_tool/storage
   sudo chmod -R 775 /var/www/bulk-sms/sms_tool/storage
   ```

## SwiftSMS API
Requests are POSTed to `${SWIFTSMS_BASE_URL}/messages` with JSON body `{"to":"+1...","message":"...","senderId":"..."}` and Bearer auth using `SWIFTSMS_API_KEY`. Responses are stored per recipient for reporting but API keys are never logged.

## CSV format
```
+15551234567,Jane Doe
+15559876543,John Smith
```
Only the first column (phone) is required; additional columns are ignored. Numbers must be E.164 formatted and duplicates are removed automatically.

## Background worker
- Command: `php sms_tool/worker/worker.php`
- Reads queued/running campaigns, honors **Stop** by checking the latest status file, and retries failed sends up to `SMS_MAX_ATTEMPTS` times.
- Rate limiting per second is controlled by `SMS_RATE_LIMIT_PER_SEC`.

## Security notes
- Static login only; update `.env` before deploying.
- CSRF tokens are added to all forms.
- Upload validation limits files to CSV/plain text under 2 MB.
- Storage uses JSON/text files only—no database required.

## AWS EC2 deployment (Ubuntu)
Below is a minimal, battle-tested path for deploying to a fresh Ubuntu EC2 instance (e.g., t3.small) using Nginx and PHP-FPM.

1. **Launch EC2 and connect**
   - Choose Ubuntu 22.04 LTS (or newer) and open SSH (22) and HTTP/HTTPS (80/443) in the security group.
   - SSH in: `ssh -i /path/to/key.pem ubuntu@<ec2-public-ip>`.

2. **System prep and packages**
   ```bash
   sudo apt update && sudo apt upgrade -y
   sudo apt install -y git unzip php php-cli php-fpm php-curl php-zip nginx
   ```

3. **Fetch the app**
   ```bash
   sudo mkdir -p /var/www/bulk-sms
   sudo chown ubuntu:ubuntu /var/www/bulk-sms
   cd /var/www/bulk-sms
   git clone https://github.com/<your-org>/<your-repo>.git .
   ```

4. **Environment configuration**
   ```bash
   cp sms_tool/.env.example sms_tool/.env
   nano sms_tool/.env   # set APP_USERNAME/APP_PASSWORD, SWIFTSMS_BASE_URL, SWIFTSMS_API_KEY, optional SENDER_ID
   ```

5. **Permissions**
   ```bash
   sudo chown -R www-data:www-data /var/www/bulk-sms/sms_tool/storage
   sudo chmod -R 775 /var/www/bulk-sms/sms_tool/storage
   ```

6. **Nginx + PHP-FPM**
   - Copy and adjust the sample config:
     ```bash
     sudo cp sms_tool/config/nginx.conf /etc/nginx/sites-available/swiftsms
     sudo sed -i 's#/var/www/html#/var/www/bulk-sms/sms_tool/public#g' /etc/nginx/sites-available/swiftsms
     sudo ln -s /etc/nginx/sites-available/swiftsms /etc/nginx/sites-enabled/swiftsms
     sudo nginx -t && sudo systemctl restart nginx
     sudo systemctl enable nginx php8.1-fpm
     ```
   - Replace `php8.1-fpm` with your installed PHP-FPM service name if different (`systemctl list-units | grep fpm`).

7. **Background worker (systemd)**
   ```bash
   sudo cp sms_tool/config/swiftsms-worker.service /etc/systemd/system/
   sudo sed -i 's#/var/www/html#/var/www/bulk-sms#g' /etc/systemd/system/swiftsms-worker.service
   sudo systemctl daemon-reload
   sudo systemctl enable --now swiftsms-worker.service
   ```

8. **SSL (optional but recommended)**
   - Point your domain’s DNS at the instance.
   - Install certbot and issue a certificate:
     ```bash
     sudo apt install -y certbot python3-certbot-nginx
     sudo certbot --nginx -d sms.yourdomain.com
     ```

9. **Verify**
   - Visit `http://<ec2-public-ip>/` (or your domain) and log in with the credentials set in `.env`.
   - Upload a CSV, start a campaign, and confirm the worker updates statuses under **Reports**.

10. **Updates and maintenance**
    ```bash
    cd /var/www/bulk-sms
    git pull
    sudo systemctl restart nginx php8.1-fpm swiftsms-worker.service
    ```

