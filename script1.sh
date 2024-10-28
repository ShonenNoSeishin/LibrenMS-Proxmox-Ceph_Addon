#!/bin/bash
# Update and requirements installation
apt update -y && apt upgrade -y
apt install acl curl fping git graphviz imagemagick mariadb-client mariadb-server mtr-tiny nginx-full nmap php-cli php-curl php-fpm php-gd php-gmp php-json php-mbstring php-mysql php-snmp php-xml php-zip rrdtool snmp snmpd unzip python3-command-runner python3-pymysql python3-dotenv python3-redis python3-setuptools python3-psutil python3-systemd python3-pip whois traceroute jq -y
# Create LibrenMS user
useradd librenms -d /opt/librenms -M -r -s "$(which bash)"

# LibrenMS installation and rights attribution
cd /opt
git clone https://github.com/librenms/librenms.git
chown -R librenms:librenms /opt/librenms && chmod 771 /opt/librenms
setfacl -d -m g::rwx /opt/librenms/rrd /opt/librenms/logs /opt/librenms/bootstrap/cache/ /opt/librenms/storage/
setfacl -R -m g::rwx /opt/librenms/rrd /opt/librenms/logs /opt/librenms/bootstrap/cache/ /opt/librenms/storage/

# PHP dependanties installation 
sudo -u librenms /opt/librenms/scripts/composer_wrapper.php install --no-dev

# Timezone configuration (please verify if it's the good php.ini file location)
sed -i "s,;date.timezone =,date.timezone = \"Etc/UTC\",g" /etc/php/8.3/fpm/php.ini
sed -i "s,;date.timezone =,date.timezone = \"Etc/UTC\",g" /etc/php/8.3/cli/php.ini

timedatectl set-timezone Etc/UTC


# MariaDB configuration
sed -i '/\[mysqld\]/a innodb_file_per_table=1\nlower_case_table_names=0' "/etc/mysql/mariadb.conf.d/50-server.cnf"

systemctl enable mariadb && systemctl restart mariadb

# Mysql initial configuration (please change the password)
mysql -u root -e "CREATE DATABASE librenms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER 'librenms'@'localhost' IDENTIFIED BY 'Password666'; GRANT ALL PRIVILEGES ON librenms.* TO 'librenms'@'localhost'; SET GLOBAL time_zone = '+02:00'; FLUSH PRIVILEGES;"
timedatectl set-timezone Europe/Brussels
# PHP-FPM configuration
cp /etc/php/8.3/fpm/pool.d/www.conf /etc/php/8.3/fpm/pool.d/librenms.conf
sed -i 's/^\[www\]/[librenms]/' /etc/php/8.3/fpm/pool.d/librenms.conf

# change "user = www-data" with "user = librenms" and "group = www-data" with "group = librenms"
sed -i 's/^user = www-data/user = librenms/' /etc/php/8.3/fpm/pool.d/librenms.conf
sed -i 's/^group = www-data/group = librenms/' /etc/php/8.3/fpm/pool.d/librenms.conf

# replace "listen = /run/" with "listen = /run/php-fpm-librenms.sock"
sed -i 's/^listen = \/run\/.*$/listen = \/run\/php-fpm-librenms.sock/' /etc/php/8.3/fpm/pool.d/librenms.conf

# Nginx configuration
bash -c 'read -p "Please enter the IP address for the LibrenMS server_name: " My_IP && cat <<EOF > /etc/nginx/sites-enabled/librenms.vhost
server {
 listen      80;
 server_name $(echo $My_IP);
 root        /opt/librenms/html;
 index       index.php;

 charset utf-8;
 gzip on;
 gzip_types text/css application/javascript text/javascript application/x-javascript image/svg+xml text/plain text/xsd text/xsl text/xml image/x-icon;
 location / {
  try_files \$uri \$uri/ /index.php?\$query_string;
 }
 location ~ [^/]\.php(/|$) {
  fastcgi_pass unix:/run/php-fpm-librenms.sock;
  fastcgi_split_path_info ^(.+\.php)(/.+)$;
  include fastcgi.conf;
 }
 location ~ /\.(?!well-known).* {
  deny all;
 }
}
EOF'

rm /etc/nginx/sites-enabled/default /etc/nginx/sites-available/default
systemctl reload nginx && systemctl restart php8.3-fpm

# Enable lnms autocompletion
ln -s /opt/librenms/lnms /usr/bin/lnms
cp /opt/librenms/misc/lnms-completion.bash /etc/bash_completion.d/

# snmpd configuration
cp /opt/librenms/snmpd.conf.example /etc/snmp/snmpd.conf
# Change community
sudo sed -i 's/RANDOMSTRINGGOESHERE/LibrenMSPublic/' /etc/snmp/snmpd.conf

curl -o /usr/bin/distro https://raw.githubusercontent.com/librenms/librenms-agent/master/snmp/distro
chmod +x /usr/bin/distro
systemctl enable snmpd && systemctl restart snmpd

### Cron job configuration
cp /opt/librenms/dist/librenms.cron /etc/cron.d/librenms

cp /opt/librenms/dist/librenms-scheduler.service /opt/librenms/dist/librenms-scheduler.timer /etc/systemd/system/
systemctl enable librenms-scheduler.timer && systemctl start librenms-scheduler.timer

cp /opt/librenms/misc/librenms.logrotate /etc/logrotate.d/librenms

systemctl stop ufw && systemctl disable ufw
