<a href="https://www.buymeacoffee.com/thibaut_watrisse" target="_blank"><img src="https://cdn.buymeacoffee.com/buttons/default-orange.png" alt="Buy Me A Coffee" height="41" width="174"></a>

# Introduction

This project is a customization of LibrenMS and LibrenMS agent to allow users to monitore more informations about Proxmox VE VMs and Ceph informations on the PVE.

# Functionnalities overview

## Apps -> Proxmox

Hyperlink for each VM running :

![image](https://github.com/user-attachments/assets/b3888973-8141-43da-802e-54c297587390)


If you click on, it show you the traffic of each network interfaces of the VM selected :

![image](https://github.com/user-attachments/assets/2faf47f4-b8d5-407f-9207-1614421a902b)

Tab with every VMs monitored informations :

![image](https://github.com/user-attachments/assets/9a0fbba9-9515-4fcc-a81f-0a2f70f28de7)

## Device -> Apps -> Proxmox

Tab with every VMs informations of the device monitored :

![image](https://github.com/user-attachments/assets/b50cc176-466e-4645-9a8a-7fd1d3918154)

Network graphs of each VMs :

![image](https://github.com/user-attachments/assets/2a00592e-9aa3-4d08-9f8d-e7c5e4420e84)

## Device -> Apps -> Ceph

Tab with Ceph disk state (if it has a disk that isn't 'up', it show it) :

![image](https://github.com/user-attachments/assets/f05db53f-c083-43db-ab42-ffd97800b9fa)

Ceph informations :

![image](https://github.com/user-attachments/assets/d2f437fb-7255-42e1-a7b0-1ea747b32dee)

...

# Requirements

You already should have a configured PVE with Ceph (I think it should work without Ceph but I havent tested it). You also should have a VM or machine to run LibrenMS. Personnally, I have used an Ubuntu 24.04.

# LibrenMS Script Installation 

## Notes

- The default password defined by the scripts for the database is 'Password666'
- The default community for every snmp instances defined by the script is 'LibrenMSPublic'
- I've run these scripts with root user
- If you change the snmp community name, you also have to change it on the following files at the end of the installation :

  -> includes/polling/applications/proxmoxCustom.inc.php

## Download requirements

Download script 1 and 3 on LibrenMS machine :

````bash
wget https://raw.githubusercontent.com/ShonenNoSeishin/LibrenMS-Proxmox-Ceph_Addon/refs/heads/main/script1.sh
wget https://raw.githubusercontent.com/ShonenNoSeishin/LibrenMS-Proxmox-Ceph_Addon/refs/heads/main/script3.sh
````
Download script 2 on PVE node :


````bash
wget https://raw.githubusercontent.com/ShonenNoSeishin/LibrenMS-Proxmox-Ceph_Addon/refs/heads/main/ClientScript2.sh
````

# Install LibrenMS

On LibrenMS machine, run the script 1 :

````bash
chmod +x script1.sh
./script1.sh
````

To do after : 

- Go to web interface and configure what it ask to you

  -> If you get some NTP issue, please adapt PHP, Mysql and Linux NTP settings to be the same 

    => timezone Mysql

        => mysql -u root -p -e "SET GLOBAL time_zone = '+02:00';" && timedatectl set-timezone Europe/Brussels

    => timezone PHP

        => nano /etc/php/8.3/cli/php.ini --> date.timezone = "Europe/Paris"

        => nano /etc/php/8.3/fpm/php.ini --> date.timezone = "Europe/Paris"

        => systemctl restart phpsessionclean.timer && systemctl restart phpsessionclean.service && systemctl restart php8.3-fpm.service

- setup the followings by the web interface

  -> global -> discovery -> check applications and Hypervisor VM info

  -> global -> poller -> check applications, Hypervisor VM info and Unix Agent

## Install LibrenMS agent and custom addon on PVE node

````bash
chmod +x ClientScript2.sh
./ClientScript2.sh
````

To do after : 

- modify /opt/librenms/misc/db_schema.yaml file on the LibrenMS machine to make the proxmox part similar

````bash
proxmox:
  Columns:
    - { Field: id, Type: 'int unsigned', 'Null': false, Extra: auto_increment }
    - { Field: device_id, Type: 'int unsigned', 'Null': false, Extra: '', Default: '0' }
    - { Field: vmid, Type: int, 'Null': false, Extra: '' }
    - { Field: hostname, Type: varchar(255), 'Null': true, Extra: '' }
    - { Field: cluster, Type: varchar(255), 'Null': false, Extra: '' }
    - { Field: description, Type: varchar(255), 'Null': true, Extra: '' }
    - { Field: last_seen, Type: timestamp, 'Null': false, Extra: '', Default: CURRENT_TIMESTAMP }
    - { Field: name, Type: varchar(255), 'Null': true, Extra: '' }
    - { Field: status, Type: varchar(50), 'Null': true, Extra: '' }
    - { Field: cpu, Type: 'int', 'Null': true, Extra: '' }
    - { Field: cpus, Type: 'int', 'Null': true, Extra: '' }
    - { Field: cpu_percent, Type: float, 'Null': true, Extra: '' }
    - { Field: mem, Type: bigint, 'Null': true, Extra: '' }
    - { Field: maxmem, Type: bigint, 'Null': true, Extra: '' }
    - { Field: disk, Type: bigint, 'Null': true, Extra: '' }
    - { Field: maxdisk, Type: bigint, 'Null': true, Extra: '' }
    - { Field: netin, Type: bigint, 'Null': true, Extra: '' }
    - { Field: netout, Type: bigint, 'Null': true, Extra: '' }
    - { Field: uptime, Type: 'int', 'Null': true, Extra: '' }
    - { Field: ceph_disks, Type: text, 'Null': true, Extra: '' }
    - { Field: ceph_bigger_disk_percent_usage, Type: float, 'Null': true, Extra: '' }
    - { Field: ceph_snapshots, Type: text, 'Null': true, Extra: '' }
    - { Field: ceph_total_snapshots, Type: float, 'Null': true, Extra: '' }
    - { Field: oldest_snapshot, Type: int, 'Null': true, Extra: '' }
    - { Field: qemu_info, Type: text, 'Null': true, Extra: '' }
  Indexes:
    PRIMARY: { Name: PRIMARY, Columns: [id], Unique: true, Type: BTREE }
````

Note that you should delete the following line because if you have more then one cluster, it's possible to have some VMs with the same VMID : 

````bash
`  - proxmox_cluster_vmid_unique: { Name: proxmox_cluster_vmid_unique, Columns: [cluster, vmid], Unique: true, Type: BTREE }
````

- in the same file, add this to "devices" section :

````bash
  - { Field: ceph_state, Type: varchar(50), 'Null': false, Extra: '', Default: '0' }
````

and run :

````bash
systemctl restart mysql
````

## Update LibrenMS files to implement the addon 

On LibrenMS machine, run the script 3 :

````bash
chmod +x script3.sh
./script3.sh
````

To do after : 

- add your pve device

  -> After map a PVE device, please go to device and select the new device -> apps -> parameters -> check "Proxmox" (and "Ceph" if you need it)

- force polling and update data

````bash
sudo -u librenms lnms device:poll all && sudo -u librenms php discovery.php -h * && sudo -u librenms ./daily.sh
````

# LibrenMS Manual Installation 

## Base installation 

Firstly, you should install LibrenMS following the installation guide --> https://docs.librenms.org/Installation/Install-LibreNMS/ 

Here are the steps I followed to install LibrenMS :

````bash
# Update and requirements installation
apt update -y && apt upgrade -y
apt install acl curl fping git graphviz imagemagick mariadb-client mariadb-server mtr-tiny nginx-full nmap php-cli php-curl php-fpm php-gd php-gmp php-json php-mbstring php-mysql php-snmp php-xml php-zip rrdtool snmp snmpd unzip python3-command-runner python3-pymysql python3-dotenv python3-redis python3-setuptools python3-psutil python3-systemd python3-pip whois traceroute jq
# Create LibrenMS user
useradd librenms -d /opt/librenms -M -r -s "$(which bash)"

# LibrenMS installation and rights attribution
cd /opt
git clone https://github.com/librenms/librenms.git
chown -R librenms:librenms /opt/librenms && chmod 771 /opt/librenms
setfacl -d -m g::rwx /opt/librenms/rrd /opt/librenms/logs /opt/librenms/bootstrap/cache/ /opt/librenms/storage/
setfacl -R -m g::rwx /opt/librenms/rrd /opt/librenms/logs /opt/librenms/bootstrap/cache/ /opt/librenms/storage/

# PHP dependanties installation 
su librenms
cd /opt/librenms
# Execute installation script
./scripts/composer_wrapper.php install --no-dev
exit 

# Timezone configuration (please verify if it's the good php.ini file location)
sed -i "s,;date.timezone =,date.timezone = \"Etc/UTC\",g" /etc/php/8.3/fpm/php.ini
sed -i "s,;date.timezone =,date.timezone = \"Etc/UTC\",g" /etc/php/8.3/cli/php.ini

timedatectl set-timezone Etc/UTC


# MariaDB configuration
sed -i '/\[mysqld\]/a innodb_file_per_table=1\nlower_case_table_names=0' "/etc/mysql/mariadb.conf.d/50-server.cnf"

systemctl enable mariadb && systemctl restart mariadb

# Mysql initial configuration (please change the password)
mysql -u root -e "CREATE DATABASE librenms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER 'librenms'@'localhost' IDENTIFIED BY 'PASSWORD_TO_CHANGE'; GRANT ALL PRIVILEGES ON librenms.* TO 'librenms'@'localhost'; SET GLOBAL time_zone = '+02:00'; FLUSH PRIVILEGES;"

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

# Go to web interface and follow installation instructions

chown librenms:librenms /opt/librenms/config.php

# Replace the librenMS password by the one you entered before
vim /opt/librenms/.env 
````

## Download snmp-mibs-downloader

As we are going to configure a custom OID, we should download snmp-mibs-download on both LibrenMS machine and PVE machine.

````bash
# Update apt source list to install snmp-mibs-downloader
echo "# non-free for snmp-mibs-downloader" >> /etc/apt/sources.list
echo "deb http://http.us.debian.org/debian stable main contrib non-free" >> /etc/apt/sources.list
apt update -y
apt-get install snmp-mibs-downloader -y
download-mibs
````

## Configure PVE as LibrenMS client

Once LibrenMS is installed, you can configure PVE as LibrenMS client. Note that you should do this configuration for each Proxmox node.

### Classic agent installation

Before installing the custom functionalities, let's install the classic LibrenMS agent. 

```bash 
# Update and upgrade
apt update -y && apt upgrade -y
# Install the requirements
apt-get install libpve-apiclient-perl snmp snmpd git -y
# Install the LibrenMS agent Proxmox script
wget https://raw.githubusercontent.com/librenms/librenms-agent/master/agent-local/proxmox -O /usr/local/bin/proxmox
# Give the corrects rights to the script 
chmod 755 /usr/local/bin/proxmox
# Check the "minimal_snmpd_conf.conf" file of this Git and edit your SNMP conf
# Please change the default community name to prevent easy access to your SNMP data
vim /etc/snmp/snmpd.conf
# Install the LibrenMS agent
cd /opt/
git clone https://github.com/librenms/librenms-agent.git
cd librenms-agent
cp check_mk_agent /usr/bin/check_mk_agent
chmod +x /usr/bin/check_mk_agent
mkdir -p /usr/lib/check_mk_agent/local/ /usr/lib/check_mk_agent/plugins
cp /opt/librenms-agent/agent-local/proxmox /usr/lib/check_mk_agent/local/proxmox
chmod +x /usr/lib/check_mk_agent/local/proxmox
cp /opt/librenms-agent/check_mk@.service /opt/librenms-agent/check_mk.socket /etc/systemd/system
systemctl daemon-reload
systemctl enable check_mk.socket && systemctl start check_mk.socket
systemctl restart snmpd
# Type the following "visudo" command and add this content in the end of the file : 
Debian-snmp ALL=(ALL) NOPASSWD: /usr/local/bin/proxmox
Debian-snmp ALL=(ALL) NOPASSWD: /usr/lib/check_mk_agent/local/customPVE.sh

# Verify if the script works using the dedicated user
sudo -u Debian-snmp /usr/bin/sudo /usr/local/bin/proxmox
```

As this agent is using telnet connection, if you want to limit incoming connections, you can follow these steps : 

1: Edit /etc/systemd/system/check_mk.socket

2: Under the [Socket] section, add a new line BindToDevice= and the name of your network adapter.

3: If the script has already been enabled in systemd, you may need to issue a systemctl daemon-reload and then systemctl restart check_mk.socket

Once these steps are done, please verify your firewall allow incoming connections to port 6556 and you can verify that the telnet connection is effective by executing this command from the LibrenMS machine : 

````bash
telnet <PVE_IP> 6556
````

You should receive agent informations. 

## Enable agents modules

On the LibrenMS machine, there are some steps you need to follow to enable theses functionnalities. 

Add a line to the configuration file with this command :

````bash 
echo "\$config['enable_proxmox'] = 1;" >> /opt/librenms/config.php
````

In the web interface follow these steps :

- In global -> Discovery -> check "Applications" and "Hypervisor informations"
- In Global -> Poller -> check "Applications", "Hypervisor VM informations" and "Unix Agent"
- Add your Proxmox with "new device" option
- Go in your proxmox device pannel (by selecting device -> <your_PVE>) -> select "Apps" and click on the parameter logo. After that, enable "Ceph" and "Proxmox" (and other things you want to see)

If you still have issue with your PVE Dashboards, please refer to the issue i reported here : 

--> https://github.com/librenms/librenms/issues/16509

## Custom addon

Modify the /opt/librenms/misc/db_schema.yaml for the proxmox bloc :

````bash
proxmox:
  Columns:
    - { Field: id, Type: 'int unsigned', 'Null': false, Extra: auto_increment }
    - { Field: device_id, Type: 'int unsigned', 'Null': false, Extra: '', Default: '0' }
    - { Field: vmid, Type: int, 'Null': false, Extra: '' }
    - { Field: hostname, Type: varchar(255), 'Null': true, Extra: '' }
    - { Field: cluster, Type: varchar(255), 'Null': false, Extra: '' }
    - { Field: description, Type: varchar(255), 'Null': true, Extra: '' }
    - { Field: last_seen, Type: timestamp, 'Null': false, Extra: '', Default: CURRENT_TIMESTAMP }
    - { Field: name, Type: varchar(255), 'Null': true, Extra: '' }
    - { Field: status, Type: varchar(50), 'Null': true, Extra: '' }
    - { Field: cpu, Type: 'int', 'Null': true, Extra: '' }
    - { Field: cpus, Type: 'int', 'Null': true, Extra: '' }
    - { Field: cpu_percent, Type: float, 'Null': true, Extra: '' }
    - { Field: mem, Type: bigint, 'Null': true, Extra: '' }
    - { Field: maxmem, Type: bigint, 'Null': true, Extra: '' }
    - { Field: disk, Type: bigint, 'Null': true, Extra: '' }
    - { Field: maxdisk, Type: bigint, 'Null': true, Extra: '' }
    - { Field: netin, Type: bigint, 'Null': true, Extra: '' }
    - { Field: netout, Type: bigint, 'Null': true, Extra: '' }
    - { Field: uptime, Type: 'int', 'Null': true, Extra: '' }
    - { Field: ceph_disks, Type: text, 'Null': true, Extra: '' }
    - { Field: ceph_bigger_disk_percent_usage, Type: float, 'Null': true, Extra: '' }
    - { Field: ceph_snapshots, Type: text, 'Null': true, Extra: '' }
    - { Field: ceph_total_snapshots, Type: float, 'Null': true, Extra: '' }
    - { Field: oldest_snapshot, Type: int, 'Null': true, Extra: '' }
    - { Field: qemu_info, Type: text, 'Null': true, Extra: '' }
  Indexes:
    PRIMARY: { Name: PRIMARY, Columns: [id], Unique: true, Type: BTREE }
````

in the same file, add this line to the "devices" bloc :

````bash
    - { Field: ceph_state, Type: varchar(50), 'Null': false, Extra: '', Default: '0' }
````

Apply changes in the database :

```bash
mysql -u root -p<enter the databse password here> -e "Use librenms; ALTER TABLE proxmox ADD name varchar(255) NULL AFTER last_seen, ADD status varchar(50) NULL AFTER name, ADD hostname varchar(255) NULL AFTER vmid, ADD cpu int NULL AFTER status, ADD cpus int NULL AFTER cpu, ADD mem bigint NULL AFTER cpus, ADD maxmem bigint NULL AFTER mem, ADD disk bigint NULL AFTER maxmem, ADD maxdisk bigint NULL AFTER disk, ADD netin bigint NULL AFTER maxdisk, ADD netout bigint NULL AFTER netin, ADD uptime int NULL AFTER netout; ALTER TABLE proxmox MODIFY cluster VARCHAR(255) DEFAULT NULL; ALTER TABLE proxmox CHANGE cluster cluster varchar(255) NOT NULL ; ALTER TABLE proxmox ADD ceph_disks text NULL AFTER uptime; ALTER TABLE proxmox ADD ceph_bigger_disk_percent_usage float NULL AFTER ceph_disks; ALTER TABLE proxmox ADD ceph_snapshots text NULL AFTER ceph_bigger_disk_pourcent_usage; ALTER TABLE proxmox ADD ceph_total_snapshots float NULL AFTER ceph_snapshots; ALTER TABLE proxmox ADD qemu_info text NULL AFTER ceph_total_snapshots; ALTER TABLE proxmox ADD oldest_snapshot int NULL AFTER ceph_total_snapshots; ALTER TABLE devices ADD ceph_state varchar(50) NULL; ALTER TABLE proxmox DROP INDEX proxmox_cluster_vmid_unique; ALTER TABLE proxmox ADD cpu_percent float NULL AFTER cpus;" && systemctl restart mysql
```

### In the PVE node

Get the Custom scripts and configure them :

````bash
wget https://raw.githubusercontent.com/ShonenNoSeishin/LibrenMS-Proxmox-Ceph_Addon/refs/heads/main/returnPveInfo.sh -O /usr/lib/returnPveInfo.sh
wget https://raw.githubusercontent.com/ShonenNoSeishin/LibrenMS-Proxmox-Ceph_Addon/refs/heads/main/customPVE.sh -O /usr/lib/check_mk_agent/local/customPVE.sh

# Make them executable : 
chmod +x /usr/lib/check_mk_agent/local/customPVE.sh
chmod +x /usr/lib/returnPveInfo.sh

# Test the script
/usr/lib/returnPveInfo.sh
/usr/lib/check_mk_agent/local/customPVE.sh

# Make a crontab that execute the script every 3 minutes 
# (better because the script take a bit time to exec so I prefer work like this and not by directly polling from LibrenMS)
(crontab -l 2>/dev/null; echo "*/3 * * * * nice /usr/lib/returnPveInfo.sh") | crontab -
# "Nice" allow to execute the script only if ressources are currently ok, if it isn't optimal to do it, it will wait for exec

### Execute these queries on LibrenMS AND PVE node ###
download-mibs
sudo systemctl restart snmpd
````

Note : the returnPveInfo.sh script is set to not be run if it has already an instance of the script currently running.

Add the following line to /etc/snmp/snmpd.conf to make the script pullable via snmp :
````bash
extend customPVE /usr/bin/sudo /usr/lib/check_mk_agent/local/customPVE.sh
````

Now, you should be able to trigger the script via snmp like this from the LibrenMS VM :
````bash
snmpwalk -v 2c -c <community> <PVE_MACHINE_IP> NET-SNMP-EXTEND-MIB::nsExtendOutputFull.\"customPVE\"
````

To recover the real OID behind this snmp path :
````bash
snmptranslate -On NET-SNMP-EXTEND-MIB::nsExtendOutputFull.\"customPVE\"
# It returns the following OID : .1.3.6.1.4.1.8072.1.3.2.3.1.2.9.99.117.115.116.111.109.80.86.69
````

Now, you should be able to trigger the script via snmp like this from the LibrenMS VM :
````bash
snmpwalk -v 2c -c <community> <PVE_MACHINE_IP> .1.3.6.1.4.1.8072.1.3.2.3.1.2.9.99.117.115.116.111.109.80.86.69
````

### Modify LibrenMS files

It has several files I have modified to pimp the dashboard, here are commands to download them from this git and put them to the right place.

```bash
# The script that take proxmox informations during polling operation
rm includes/polling/applications/proxmoxCustom.inc.php
wget https://raw.githubusercontent.com/ShonenNoSeishin/LibrenMS-Proxmox-Ceph_Addon/refs/heads/main/proxmoxCustom.inc.php -O includes/polling/applications/proxmoxCustom.inc.php

# The script that show Ceph Informations
rm includes/html/pages/device/apps/ceph.inc.php
wget https://raw.githubusercontent.com/ShonenNoSeishin/LibrenMS-Proxmox-Ceph_Addon/refs/heads/main/ceph.inc.php -O includes/html/pages/device/apps/ceph.inc.php

# The script that show all PVE informations in "apps"
rm includes/html/pages/apps/proxmox.inc.php
wget https://raw.githubusercontent.com/ShonenNoSeishin/LibrenMS-Proxmox-Ceph_Addon/refs/heads/main/proxmox.inc_apps.php -O includes/html/pages/apps/proxmox.inc.php

# The script that show PVE informations for a specific device
rm includes/html/pages/device/apps/proxmox.inc.php
wget https://raw.githubusercontent.com/ShonenNoSeishin/LibrenMS-Proxmox-Ceph_Addon/refs/heads/main/proxmox.inc.php -O includes/html/pages/device/apps/proxmox.inc.php

# you also need to add an include to add the proxmodCustom in existing proxmox addon, please run this command
sed -i '1a include "includes/polling/applications/proxmoxCustom.inc.php";' /opt/librenms/includes/polling/applications/proxmox.inc.php

```

## Useful commands

Mysql commands :
````bash
mysql -u root -p<MYSQL_PASSWORD> -e "Use librenms; SELECT * FROM proxmox"
mysql -u root -p<MYSQL_PASSWORD> -e "SET GLOBAL time_zone = '+02:00';" && timedatectl set-timezone Europe/Brussels
````

LibrenMS commands :
````bash
# Exec the poller for a specific device
sudo -u librenms php /opt/librenms/poller.php -h <IP>
# Exec the discover for a specific device
sudo -u librenms php /opt/librenms/discovery.php -h <IP>
# Exec the daily script
sudo -u librenms /opt/librenms/daily.sh
# Exec the validate script
sudo -u librenms /opt/librenms/validate.php
````

## Alerting

### Examples of alerts

![image](https://github.com/user-attachments/assets/406f7b18-954c-4a91-984c-432183bbeb6b)


### Alerting by email

I followed this documentation -> https://ws.learn.ac.lk/wiki/NSM2021/Agenda/AlertsLibrenms

## Logging

It's also possible to send logs from PVE to LibrenMS. Unlike the LibrenMS doc for activating syslog-ng, I do not put the "flags(syslog-protocol)" filter for the source of the flow, because otherwise, logs which do not have the format in question will not are not taken into account (VM state change logs, backup, etc.)

I made two scripts to make this configuration easier. 

On LibrenMS machine : 

`````bash
wget https://raw.githubusercontent.com/ShonenNoSeishin/LibrenMS-Proxmox-Ceph_Addon/refs/heads/main/Configure_Syslog_LNMS.sh -O ./Configure_Syslog_LNMS.sh
chmod +x ./Configure_Syslog_LNMS.sh
./Configure_Syslog_LNMS.sh
`````

And then on the PVE machine : 

`````bash
wget https://raw.githubusercontent.com/ShonenNoSeishin/LibrenMS-Proxmox-Ceph_Addon/refs/heads/main/Configure_Syslog_PVE.sh -O ./Configure_Syslog_PVE.sh
chmod +x ./Configure_Syslog_PVE.sh
./Configure_Syslog_PVE.sh
`````

After that, you should be able to see the node logs in the LibrenMS machine view :

![image](https://github.com/user-attachments/assets/c11ab6d4-afff-43b0-9e02-12db6f9e9e73)
