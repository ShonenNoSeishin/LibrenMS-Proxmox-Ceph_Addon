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
wget https://raw.githubusercontent.com/ShonenNoSeishin/LibrenMS-Proxmox-Ceph_Addon/refs/heads/main/proxmox_ssh_script -O /usr/local/bin/proxmox
wget https://raw.githubusercontent.com/ShonenNoSeishin/LibrenMS-Proxmox-Ceph_Addon/refs/heads/main/proxmox_perl_script -O /usr/local/bin/proxmox_perl_script
# Give the corrects rights to the script 
chmod 755 /usr/local/bin/proxmox
chmod 755 /usr/local/bin/proxmox_perl_script
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
wget https://raw.githubusercontent.com/ShonenNoSeishin/LibrenMS-Proxmox-Ceph_Addon/refs/heads/main/proxmox_ssh_script -O /usr/lib/check_mk_agent/local/proxmox
cp /opt/librenms-agent/agent-local/ceph /usr/lib/check_mk_agent/local/ceph
chmod +x /usr/lib/check_mk_agent/local/proxmox
chmod +x /usr/lib/check_mk_agent/local/ceph
cp /opt/librenms-agent/check_mk@.service /opt/librenms-agent/check_mk.socket /etc/systemd/system
systemctl daemon-reload
systemctl enable check_mk.socket && systemctl start check_mk.socket
systemctl restart snmpd
# Define the lines to add
sudoers_entry="Debian-snmp ALL=(ALL) NOPASSWD: /usr/local/bin/proxmox\nDebian-snmp ALL=(ALL) NOPASSWD: /usr/lib/customPVE.sh"

# Check if the lines already exist in the sudoers file to avoid duplicates
if ! sudo grep -Fxq "Debian-snmp ALL=(ALL) NOPASSWD: /usr/local/bin/proxmox" /etc/sudoers && \
   ! sudo grep -Fxq "Debian-snmp ALL=(ALL) NOPASSWD: /usr/lib/customPVE.sh" /etc/sudoers; then
    # Append the entries to the sudoers file securely
    echo -e "$sudoers_entry" | sudo tee -a /etc/sudoers > /dev/null
    echo "Permissions have been added to the sudoers file."
else
    echo "Permissions already exist in the sudoers file."
fi

echo "\$config['enable_proxmox'] = 1;" >> /opt/librenms/config.php

wget https://raw.githubusercontent.com/ShonenNoSeishin/LibrenMS-Proxmox-Ceph_Addon/refs/heads/main/returnPveInfo.sh -O /usr/lib/returnPveInfo.sh
wget https://raw.githubusercontent.com/ShonenNoSeishin/LibrenMS-Proxmox-Ceph_Addon/refs/heads/main/customPVE.sh -O /usr/lib/check_mk_agent/local/customPVE.sh

read -p "Entrez le nouveau nom du Ceph Pool : " NewCephPoolName && \
sed -i "s/\$CephPoolName=\"[^\"]*\"/\$CephPoolName=\"$NewCephPoolName\"/" /usr/lib/returnPveInfo.sh

# Make them executable : 
chmod +x /usr/lib/check_mk_agent/local/customPVE.sh
chmod +x /usr/lib/returnPveInfo.sh

# Make a crontab that execute the script every 3 minutes 
# (better because the script take a bit time to exec so I prefer work like this and not by directly polling from LibrenMS)
(crontab -l 2>/dev/null; echo "*/3 * * * * nice /usr/lib/returnPveInfo.sh") | crontab -
# "Nice" allow to execute the script only if ressources are currently ok, if it isn't optimal to do it, it will wait for exec

download-mibs
systemctl restart snmpd

## Token creation
# Ask for token password
read -sp "Entrez le mot de passe pour lnms_user_api : " PASSWORD
echo ""

useradd -m -s /bin/bash lnms_user_api && echo "lnms_user_api:$PASSWORD" | sudo chpasswd
pveum user add lnms_user_api@pam --comment "LibrenMS user for API access"
TOKEN=$(pveum user token add lnms_user_api@pam api_token --output-format json | jq -r '.value')
echo "PVE_TOKEN=$TOKEN" > /usr/lib/.env
echo "PVE_USER=lnms_user_api@pam" >> /usr/lib/.env
set +H
Authorization="Authorization='Authorization: PVEAPIToken=${PVE_USER}!api_token=${PVE_TOKEN}'"
set -H
echo "$Authorization" >> /usr/lib/.env
pveum aclmod / -token 'lnms_user_api@pam!api_token' -role Administrator
pveum acl modify / --roles Administrator --users lnms_user_api@pam
echo $PVE_IP >> /usr/lib/.env 
source /usr/lib/.env



for i in {1..5}; do
    snmpd_entry="extend customPVE$i /usr/bin/sudo /usr/lib/customPVE.sh $i"

    if ! grep -Fxq "$snmpd_entry" /etc/snmp/snmpd.conf; then
        echo "$snmpd_entry" | sudo tee -a /etc/snmp/snmpd.conf > /dev/null
        echo "Entry added: $snmpd_entry"
    else
        echo "Entry already exists: $snmpd_entry"
    fi
done


# Define the line to add to snmpd.conf
snmpd_entry="extend proxmox /usr/bin/sudo /usr/local/bin/proxmox"

# Check if the line already exists in snmpd.conf
if ! grep -Fxq "$snmpd_entry" /etc/snmp/snmpd.conf; then
    # Append the line to snmpd.conf if it's not already present
    echo "$snmpd_entry" | sudo tee -a /etc/snmp/snmpd.conf > /dev/null
    echo "Entry added to /etc/snmp/snmpd.conf."
else
    echo "Entry already exists in /etc/snmp/snmpd.conf."
fi

systemctl restart snmpd
