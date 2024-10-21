#!/bin/bash
# Proxmox VE virtual machine listing
# (c) 2015-2019, Tom Laermans for Observium
#!/bin/bash

if [ $(wc -l < "/usr/lib/PVE_INFO.txt") -le 2 ]; then
    # If the file contains less then 3 lines, it exec the script to full the txt file
    /usr/lib/customPVE.sh
fi

cat /usr/lib/PVE_INFO.txt
