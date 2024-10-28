#!/bin/bash
chown librenms:librenms /opt/librenms/config.php

# Update apt source list to install snmp-mibs-downloader
echo "# non-free for snmp-mibs-downloader" >> /etc/apt/sources.list
echo "deb http://http.us.debian.org/debian stable main contrib non-free" >> /etc/apt/sources.list
apt update -y
apt-get install snmp-mibs-downloader -y
download-mibs

# The script that take proxmox informations during polling operation
rm /opt/librenms/includes/polling/applications/proxmoxCustom.inc.php
wget https://raw.githubusercontent.com/ShonenNoSeishin/LibrenMS-Proxmox-Ceph_Addon/refs/heads/main/proxmoxCustom.inc.php -O /opt/librenms/includes/polling/applications/proxmoxCustom.inc.php

# The script that show Ceph Informations
rm /opt/librenms/includes/html/pages/device/apps/ceph.inc.php
wget https://raw.githubusercontent.com/ShonenNoSeishin/LibrenMS-Proxmox-Ceph_Addon/refs/heads/main/ceph.inc.php -O /opt/librenms/includes/html/pages/device/apps/ceph.inc.php

# The script that show all PVE informations in "apps"
rm /opt/librenms/includes/html/pages/apps/proxmox.inc.php
wget https://raw.githubusercontent.com/ShonenNoSeishin/LibrenMS-Proxmox-Ceph_Addon/refs/heads/main/proxmox.inc_apps.php -O /opt/librenms/includes/html/pages/apps/proxmox.inc.php

# The script that show PVE informations for a specific device
rm /opt/librenms/includes/html/pages/device/apps/proxmox.inc.php
wget https://raw.githubusercontent.com/ShonenNoSeishin/LibrenMS-Proxmox-Ceph_Addon/refs/heads/main/proxmox.inc.php -O /opt/librenms/includes/html/pages/device/apps/proxmox.inc.php

cd /opt/librenms
