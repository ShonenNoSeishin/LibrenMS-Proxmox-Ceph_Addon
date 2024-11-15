#!/bin/bash
apt-get install syslog-ng-core
echo "\$config['enable_syslog'] = 1;" >> /opt/librenms/config.php
wget https://raw.githubusercontent.com/ShonenNoSeishin/LibrenMS-Proxmox-Ceph_Addon/refs/heads/main/librenms.conf -O /etc/syslog-ng/conf.d/librenms.conf
service syslog-ng restart
