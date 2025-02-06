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

        => mysql -u root -p -e "SET GLOBAL time_zone = '+02:00';" && timedatectl set-timezone Europe/Brussels && systemctl restart mysql

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
    - { Field: disk, Type: text, 'Null': true, Extra: '' }
    - { Field: netin, Type: bigint, 'Null': true, Extra: '' }
    - { Field: netout, Type: bigint, 'Null': true, Extra: '' }
    - { Field: uptime, Type: 'int', 'Null': true, Extra: '' }
    - { Field: bigger_disk_percent_usage, Type: float, 'Null': true, Extra: '' }
    - { Field: ceph_snapshots, Type: text, 'Null': true, Extra: '' }
    - { Field: ceph_total_snapshots, Type: float, 'Null': true, Extra: '' }
    - { Field: oldest_snapshot, Type: int, 'Null': true, Extra: '' }
    - { Field: qemu_info, Type: text, 'Null': true, Extra: '' }
    - { Field: node_name, Type: varchar(255), 'Null': true, Extra: '' }
    - { Field: last_update, Type: varchar(255), 'Null': true, Extra: '' }
  Indexes:
    PRIMARY: { Name: PRIMARY, Columns: [id], Unique: true, Type: BTREE }
proxmox_ports:
  Columns:
    - { Field: id, Type: 'int unsigned', 'Null': false, Extra: auto_increment }
    - { Field: vm_id, Type: int, 'Null': false, Extra: '' }
    - { Field: port, Type: varchar(10), 'Null': false, Extra: '' }
    - { Field: last_seen, Type: timestamp, 'Null': false, Extra: '', Default: CURRENT_TIMESTAMP }
  Indexes:
    PRIMARY: { Name: PRIMARY, Columns: [id], Unique: true, Type: BTREE }
    proxmox_ports_vm_id_port_unique: { Name: proxmox_ports_vm_id_port_unique, Columns: [vm_id, port], Unique: true, Type: BTREE }
````

Note that you should delete the following line because if you have more then one cluster, it's possible to have some VMs with the same VMID : 

````bash
`  - proxmox_cluster_vmid_unique: { Name: proxmox_cluster_vmid_unique, Columns: [cluster, vmid], Unique: true, Type: BTREE }
````

- in the same file, add this to "devices" section :

````bash
  - { Field: ceph_state, Type: varchar(200), 'Null': false, Extra: '', Default: '0' }
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
