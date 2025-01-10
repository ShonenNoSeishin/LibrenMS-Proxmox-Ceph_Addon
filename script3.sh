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

# Modify the $vm variable definition in /opt/librenms/includes/html/pages/apps/proxmox/vm.inc.php
sed -i '/^\$vm /s/^/\/\//; /\/\/\$vm /a\$vm = proxmox_vm_info($vars['\''vmid'\''], $vars['\''instance'\'']);' /opt/librenms/includes/html/pages/apps/proxmox/vm.inc.php

echo "\$config['enable_proxmox'] = 1;" >> /opt/librenms/config.php
mysql -u root -pPassword00 -e "Use librenms; ALTER TABLE proxmox ADD name varchar(255) NULL AFTER last_seen, ADD status varchar(50) NULL AFTER name, ADD hostname varchar(255) NULL AFTER vmid, ADD cpu int NULL AFTER status, ADD cpus int NULL AFTER cpu, ADD mem bigint NULL AFTER cpus, ADD maxmem bigint NULL AFTER mem, ADD disk text NULL AFTER maxmem, ADD maxdisk bigint NULL AFTER disk, ADD netin bigint NULL AFTER maxdisk, ADD netout bigint NULL AFTER netin, ADD uptime int NULL AFTER netout; ALTER TABLE proxmox MODIFY cluster VARCHAR(255) DEFAULT NULL; ALTER TABLE proxmox CHANGE cluster cluster varchar(255) NOT NULL; ALTER TABLE proxmox ADD bigger_disk_percent_usage float NULL AFTER uptime; ALTER TABLE proxmox ADD ceph_snapshots text NULL AFTER bigger_disk_percent_usage; ALTER TABLE proxmox ADD ceph_total_snapshots float NULL AFTER ceph_snapshots; ALTER TABLE proxmox ADD qemu_info text NULL AFTER ceph_total_snapshots; ALTER TABLE proxmox ADD oldest_snapshot int NULL AFTER ceph_total_snapshots; ALTER TABLE devices ADD ceph_state varchar(50) NULL; ALTER TABLE proxmox DROP INDEX proxmox_cluster_vmid_unique; ALTER TABLE proxmox ADD cpu_percent float NULL AFTER cpus;"

sed -i '1a include "includes/polling/applications/proxmoxCustom.inc.php";' /opt/librenms/includes/polling/applications/proxmox.inc.php

cd /opt/librenms
