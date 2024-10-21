#!/bin/bash
# Proxmox VE virtual machine listing
# (c) 2015-2019, Tom Laermans for Observium
#!/bin/bash

if [ $(wc -l < "/usr/lib/PVE_INFO.txt") -le 2 ]; then
    # Si le fichier contient 2 lignes ou moins, exécute customPVE.sh
    /usr/lib/customPVE.sh
fi

cat /usr/lib/PVE_INFO.txt
