# Raspberry Pi Deployment Guide

This guide moves HotspotFreeRAD from local Windows/XAMPP development to the Raspberry Pi that already runs MySQL/MariaDB and FreeRADIUS.

## Target Shape

```text
Raspberry Pi
    +-- Nginx or Apache
    +-- PHP-FPM
    +-- Laravel HotspotFreeRAD
    +-- MySQL/MariaDB radius database
    +-- FreeRADIUS
    +-- WireGuard
```

Laravel and FreeRADIUS should read/write the same `radius` database locally:

```env
DB_HOST=127.0.0.1
DB_DATABASE=radius
```

## 1. Install Runtime Packages

On the Raspberry Pi:

```bash
sudo apt update
sudo apt install -y nginx git unzip curl php-fpm php-cli php-mysql php-curl php-xml php-mbstring php-zip php-bcmath php-intl
```

Install Composer if missing:

```bash
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php
sudo mv composer.phar /usr/local/bin/composer
rm composer-setup.php
```

## 2. Clone The Repository

```bash
cd /var/www
sudo git clone https://github.com/mondiison/hotspotfreerad.git hotspotfreerad
sudo chown -R $USER:www-data /var/www/hotspotfreerad
cd /var/www/hotspotfreerad
```

## 3. Install Laravel Dependencies

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

If Node is installed on the Pi, build assets there:

```bash
npm install
npm run build
```

If Node is not installed, build assets on your development machine and deploy the generated `public/build` folder separately.

## 4. Configure `.env`

Edit:

```bash
nano .env
```

Use values like:

```env
APP_NAME=HotspotFreeRAD
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-public-portal-domain

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=radius
DB_USERNAME=radius
DB_PASSWORD=your_radius_mysql_password

RADIUS_SERVER_IP=10.8.0.1
RADIUS_AUTH_PORT=1812
RADIUS_ACCT_PORT=1813

WIREGUARD_ENDPOINT_HOST=your_public_ip_or_ddns_name
WIREGUARD_ENDPOINT_PORT=13231
WIREGUARD_PUBLIC_KEY=your_pi_wireguard_public_key

HOTSPOT_DNS_NAME=hotspot.local
```

## 5. Run Migrations Carefully

Use normal migrate:

```bash
php artisan migrate --force
```

Do not run `migrate:fresh` on the Pi. It drops tables and can destroy FreeRADIUS data.

## 6. Set Permissions

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rwx storage bootstrap/cache
```

## 7. Configure Nginx

Create:

```bash
sudo nano /etc/nginx/sites-available/hotspotfreerad
```

Example:

```nginx
server {
    listen 80;
    server_name your-public-portal-domain;
    root /var/www/hotspotfreerad/public;

    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php-fpm.sock;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

The exact `fastcgi_pass` path can vary by PHP version. Check available sockets:

```bash
ls /run/php/
```

Enable the site:

```bash
sudo ln -s /etc/nginx/sites-available/hotspotfreerad /etc/nginx/sites-enabled/hotspotfreerad
sudo nginx -t
sudo systemctl reload nginx
```

## 8. Optimize Laravel

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## 9. Verify

```bash
php artisan test
php artisan migrate:status
curl -I http://127.0.0.1
```

Then open:

```text
http://your-public-portal-domain/hotspot/portal?mac=AA:BB:CC:DD:EE:FF&nasid=YOUR_ROUTER_NAS_ID
```

## 10. Updating Later

After new code is pushed to GitHub:

```bash
cd /var/www/hotspotfreerad
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo systemctl reload nginx
```
