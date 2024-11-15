#!/bin/bash

# Prompt user for the LibreNMS server IP
read -p "Enter the IP address of the LibreNMS server: " syslog_ip

# Check if the input is not empty
if [[ -z "$syslog_ip" ]]; then
    echo "IP address cannot be empty. Exiting."
    exit 1
fi

# Add the syslog configuration line to /etc/rsyslog.conf
echo "*.* @$syslog_ip:514" | sudo tee -a /etc/rsyslog.conf > /dev/null

# Reload rsyslog service to apply the changes
sudo systemctl restart rsyslog

# echo "Syslog configuration updated and rsyslog restarted."
