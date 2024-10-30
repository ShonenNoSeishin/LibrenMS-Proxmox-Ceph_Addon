#!/bin/bash
# Update and upgrade
apt update -y && apt upgrade -y
# Install the requirements
apt-get install libpve-apiclient-perl snmp snmpd git jq -y
# Update apt source list to install snmp-mibs-downloader
echo "# non-free for snmp-mibs-downloader" >> /etc/apt/sources.list
echo "deb http://http.us.debian.org/debian stable main contrib non-free" >> /etc/apt/sources.list
apt update -y
apt-get install snmp-mibs-downloader -y
download-mibs
# Install the LibrenMS agent Proxmox script
wget https://raw.githubusercontent.com/librenms/librenms-agent/master/agent-local/proxmox -O /usr/local/bin/proxmox
# Give the corrects rights to the script 
chmod 755 /usr/local/bin/proxmox
read -p "enter the IP address of Librenms machine : " LibrenmsIP

if [[ -z "$LibrenmsIP" ]]; then
    echo "Error : please give the LibreNMS IP address."
    exit 1
fi

read -p "Enter PVE IP address : " PVE_IP

if [[ -z "$PVE_IP" ]]; then
    echo "Error : please give the PVE IP address."
    exit 1
fi

sed -i '/^rocommunity /s/^/# /' /etc/snmp/snmpd.conf

# Add new rocommunity lines for LibreNMS IP and localhost
echo -e "rocommunity LibrenMSPublic $LibrenmsIP\nrocommunity LibrenMSPublic 127.0.0.1" | sudo tee -a /etc/snmp/snmpd.conf > /dev/null

# Update "rocommunity6" to use "LibrenMSPublic" instead of "public"
sed -i 's/^rocommunity6 public/rocommunity6 LibrenMSPublic/' /etc/snmp/snmpd.conf

echo "agentaddress $PVE_IP" >> /etc/snmp/snmpd.conf

if [[ $? -eq 0 ]]; then
    echo "Update community and agentaddress successfully in /etc/snmp/snmpd.conf."
else
    echo "Error updating community and agentaddress."
fi

# Install the LibrenMS agent
cd /opt/
git clone https://github.com/librenms/librenms-agent.git
cd librenms-agent
cp check_mk_agent /usr/bin/check_mk_agent
chmod +x /usr/bin/check_mk_agent
mkdir -p /usr/lib/check_mk_agent/local/ /usr/lib/check_mk_agent/plugins
cp /opt/librenms-agent/agent-local/proxmox /usr/lib/check_mk_agent/local/proxmox
cp /opt/librenms-agent/agent-local/ceph /usr/lib/check_mk_agent/local/ceph
chmod +x /usr/lib/check_mk_agent/local/proxmox
chmod +x /usr/lib/check_mk_agent/local/ceph
cp /opt/librenms-agent/check_mk@.service /opt/librenms-agent/check_mk.socket /etc/systemd/system
systemctl daemon-reload
systemctl enable check_mk.socket && systemctl start check_mk.socket
systemctl restart snmpd
# Define the lines to add
sudoers_entry="Debian-snmp ALL=(ALL) NOPASSWD: /usr/local/bin/proxmox\nDebian-snmp ALL=(ALL) NOPASSWD: /usr/lib/check_mk_agent/local/customPVE.sh"

# Check if the lines already exist in the sudoers file to avoid duplicates
if ! sudo grep -Fxq "Debian-snmp ALL=(ALL) NOPASSWD: /usr/local/bin/proxmox" /etc/sudoers && \
   ! sudo grep -Fxq "Debian-snmp ALL=(ALL) NOPASSWD: /usr/lib/check_mk_agent/local/customPVE.sh" /etc/sudoers; then
    # Append the entries to the sudoers file securely
    echo -e "$sudoers_entry" | sudo tee -a /etc/sudoers > /dev/null
    echo "Permissions have been added to the sudoers file."
else
    echo "Permissions already exist in the sudoers file."
fi

echo "\$config['enable_proxmox'] = 1;" >> /opt/librenms/config.php

wget https://raw.githubusercontent.com/ShonenNoSeishin/LibrenMS-Proxmox-Ceph_Addon/refs/heads/main/returnPveInfo.sh -O /usr/lib/returnPveInfo.sh
wget https://raw.githubusercontent.com/ShonenNoSeishin/LibrenMS-Proxmox-Ceph_Addon/refs/heads/main/customPVE.sh -O /usr/lib/check_mk_agent/local/customPVE.sh

# Make them executable : 
chmod +x /usr/lib/check_mk_agent/local/customPVE.sh
chmod +x /usr/lib/returnPveInfo.sh

# Make a crontab that execute the script every 3 minutes 
# (better because the script take a bit time to exec so I prefer work like this and not by directly polling from LibrenMS)
(crontab -l 2>/dev/null; echo "*/3 * * * * nice /usr/lib/returnPveInfo.sh") | crontab -
# "Nice" allow to execute the script only if ressources are currently ok, if it isn't optimal to do it, it will wait for exec

### Execute these queries on LibrenMS AND PVE node ###
download-mibs
systemctl restart snmpd

# Define the line to add to snmpd.conf
snmpd_entry="extend customPVE /usr/bin/sudo /usr/lib/check_mk_agent/local/customPVE.sh"

# Check if the line already exists in snmpd.conf
if ! grep -Fxq "$snmpd_entry" /etc/snmp/snmpd.conf; then
    # Append the line to snmpd.conf if it's not already present
    echo "$snmpd_entry" | sudo tee -a /etc/snmp/snmpd.conf > /dev/null
    echo "Entry added to /etc/snmp/snmpd.conf."
else
    echo "Entry already exists in /etc/snmp/snmpd.conf."
fi


systemctl restart snmpd
