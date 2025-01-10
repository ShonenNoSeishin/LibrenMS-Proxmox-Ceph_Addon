#!/bin/bash
# Proxmox VE virtual machine listing
# (c) 2015-2019, Tom Laermans for Observium

CephPoolName="Pool-Replica-3"

# Verify if returnPveInfo script is already running
another_instance() {
    echo "Fill Cache is running already." >>/var/log/fill-cache.log
    exit 1
}

INSTANCES=$(lsof -t "$0" | wc -l)
if [ "$INSTANCES" -gt 1 ]; then
    another_instance
fi

PVESH=$(which pvesh)
if [ $? -eq 0 ]; then
    clustername=$(grep 'cluster_name' /etc/pve/corosync.conf | awk '{print $2}')
    VERSION=$(pveversion | awk -F/ '{print $2}' | sed 's/\..*//')
    if [[ ${VERSION} -ge 5 ]]; then
        VM_LIST=$(pvesh get /nodes/$(hostname)/qemu --output-format=json-pretty 2>/dev/null)
    else
        VM_LIST=$(pvesh get /nodes/$(hostname)/qemu 2>/dev/null)
    fi

    # Initialize Ceph variables as `null`
    ceph_data=""
    Ceph_Info="null"
    ceph_status="null"
    disks="null"
    ceph_snapshots="null"
    biggerDiskPourcentUsage="null"
    CephTotalSnapshots="null"

if command -v ceph >/dev/null 2>&1; then
    ceph_data=$(/usr/bin/ceph osd df -f json 2>/dev/null)

    # If the command failed, set ceph_data to empty and Ceph_Info to "null"
    if [ $? -ne 0 ] || [ -z "$ceph_data" ]; then
        echo "Ceph command failed or returned empty data; setting Ceph data to null."
        ceph_data=""
        Ceph_Info="null"
    else
        # Extract OSD status and initialize variables if Ceph data is present
        warning=false
        warned_disks=""

        while IFS= read -r osd; do
            name=$(echo "$osd" | jq -r '.name')
            status=$(echo "$osd" | jq -r '.status')
            if [[ "$status" != "up" ]]; then
                warning=true
                warned_disks+="$name "
            fi
        done < <(echo "$ceph_data" | jq -c '.nodes[]')

        if [ "$warning" = true ]; then
            Ceph_Info="WARNING: The following disks aren't 'up': $warned_disks"
        else
            Ceph_Info="Disks 'up'"
        fi

        ceph_status=$Ceph_Info
        CEPH_TOTAL_INFO=$(rbd du -p "$CephPoolName" 2>/dev/null || echo "null")
    fi
else
    # Set Ceph-related variables to null if Ceph is not installed
    Ceph_Info="null"
    ceph_status="null"
    disks="null"
    ceph_snapshots="null"
#    biggerDiskPourcentUsage="null"
    CephTotalSnapshots="null"
    echo "Ceph is not installed; setting Ceph data to null."
fi
    # Initialize an array for updated VM information
    UPDATED_VMS=()
    CPU_TOTAL_DATA=$(pvesh get cluster/resources --output-format json)

    for ELMNT in $(echo "$VM_LIST" | jq -c '.[]'); do
        VMID=$(echo "$ELMNT" | jq -r '.vmid')
        QEMU_INFO=$(/usr/sbin/qm agent $VMID network-get-interfaces 2>/dev/null | jq -r '.[] | ."ip-addresses" | .[] | ."ip-address"')
        CPU_DATA=$(echo "$CPU_TOTAL_DATA" | jq --arg id "qemu/$VMID" '.[] | select(.id == $id) | .cpu')
        CPU_PERCENT=$(echo "$CPU_TOTAL_DATA" | jq --arg id "qemu/$VMID" '.[] | select(.id == $id) | ((.cpu * 100 / .maxcpu) * 1000 | round / 1000)')

        ARRAY_DISKS=()
        BIGGER=0

        # Calculate the oldest snapshot
        snapshots=$(/usr/sbin/qm listsnapshot $VMID | grep -E "^[ ]*\`->|^[ ]* " | awk 'NF>=3 {print $2, $3, $4}')
        current_date=$(date +%s)
        max_days=0

        while read -r name date time; do
            if [[ $date =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]]; then
                snapshot_datetime="$date $time"
                snapshot_timestamp=$(date -d "$snapshot_datetime" +%s)
                days=$(( (current_date - snapshot_timestamp) / 86400 ))
                if [ $days -gt $max_days ]; then
                    max_days=$days
                fi
            fi
        done <<< "$snapshots"

        # Récupération des informations CEPH et disque
        CEPH_DISK_INFO=$(echo "$CEPH_TOTAL_INFO" | grep -E "vm-$VMID-disk-*" | grep -v '@')
        DISK_INFO=$(pvesh get /nodes/$(hostname)/qemu/$VMID/agent/get-fsinfo --output-format json | jq -r '
            .result[] | 
            select(.["used-bytes"] != null and .["total-bytes"] != null) | 
            "\(.mountpoint) \(.["used-bytes"] / 1024 / 1024 / 1024) GiB \(.["total-bytes"] / 1024 / 1024 / 1024) GiB"
        ')

        BIGGER=0
        max_disk=0
        used_disk=0
	if [[ "$DISK_INFO" != *"QEMU guest agent is not running"* && "$DISK_INFO" != *"is not running"* && "$DISK_INFO" != *"No QEMU guest agent configured"* ]]; then            
            while read -r disk_name GiB_used used_unit GiB_size size_unit; do
                if [[ -n "$disk_name" ]]; then
                    max_disk=$(echo "$GiB_size" | sed 's/[^0-9.]//g')
                    used_disk=$(echo "$GiB_used" | sed 's/[^0-9.]//g')
                    
		    POURCENT=0

		    if [[ -n "$used_disk" ]] && (( $(awk "BEGIN {print ($max_disk != 0)}") )); then
                        POURCENT=$(awk "BEGIN {print ($used_disk / $max_disk) * 100}")
			echo "YES" >> /tmp/debug.log
                    else
                        POURCENT=0
                    fi
#		    echo "DEBUG: disk_name=$disk_name used_disk=$used_disk max_disk=$max_disk POURCENT=$POURCENT" >> /tmp/debug.log		    
                    if (( $(echo "$BIGGER < $POURCENT" | bc -l) )); then
                        BIGGER=$POURCENT
                    fi
#                    echo "DEBUG: BIGGER=$BIGGER" >> /tmp/debug.log
		    used_disk_rounded=$(printf "%.2f" "$used_disk")
		    max_disk_rounded=$(printf "%.2f" "$max_disk")
                    ARRAY_DISKS+=("$disk_name : $used_disk_rounded / $max_disk_rounded $size_unit")
#		    ARRAY_DISKS+=("$disk_name : $used_disk / $max_disk $size_unit")
                fi
            done <<< "$DISK_INFO"
       fi

            CEPH_SNAPSHOTS=$(echo "$CEPH_TOTAL_INFO" | grep "vm-$VMID-disk-.*@")
            TOTAL_SNAPSHOTS=0
            ARRAY_SNAPSHOTS=()
            while read -r name size sizeUnit used usedUnit; do
                if [[ -n "$name" ]]; then
			size_num=$(echo "$size" | sed 's/[^0-9.]//g')
			used_num=$(echo "$used" | sed 's/[^0-9.]//g')

			# Convert to GiB
			case $usedUnit in
			    "GiB")
			        used_gib=$used_num
			        ;;
			    "MiB")
			        used_gib=$(awk "BEGIN {print $used_num / 1024}")
			        ;;
			    "KiB")
			        used_gib=$(awk "BEGIN {print $used_num / 1024 / 1024}")
			        ;;
			esac

			TOTAL_SNAPSHOTS=$(awk "BEGIN {print $TOTAL_SNAPSHOTS + $used_gib}")
			ARRAY_SNAPSHOTS+=("$name : $used_num $usedUnit / $size_num $sizeUnit")
#                    size_num=$(echo "$size" | sed 's/[^0-9.]//g')
#                    used_num=$(echo "$used" | sed 's/[^0-9.]//g')
#                    TOTAL_SNAPSHOTS=$(awk "BEGIN {print $TOTAL_SNAPSHOTS + $used_num}")
#                    ARRAY_SNAPSHOTS+=("$name : $used_num $usedUnit / $size_num $sizeUnit")
                fi
            done <<< "$CEPH_SNAPSHOTS"


        # Modify ELMNT with new values
        UPDATED_ELMNT=$(echo "$ELMNT" | jq \
            --arg cpu "$CPU_DATA" \
            --arg cpu_percent "$CPU_PERCENT" \
	    --argjson disk "$(printf '%s\n' "${ARRAY_DISKS[@]}" | jq -R . | jq -s .)" \
            --arg biggerDiskPercentUsage "$(printf '%.2f' $BIGGER)" \
            --arg totalSnapshots "$(printf '%.1f' $TOTAL_SNAPSHOTS)" \
            --argjson cephSnapshots "$(printf '%s\n' "${ARRAY_SNAPSHOTS[@]}" | jq -R . | jq -s .)" \
            --arg clustername "$clustername" \
            --arg qemuInfo "$QEMU_INFO" \
            --arg cephInfo "$Ceph_Info" \
            --arg oldestSnapshot "$max_days" \
            '. + {cpu: $cpu, cpu_percent: $cpu_percent, disk: $disk, biggerDiskPercentUsage: $biggerDiskPercentUsage, cephSnapshots: $cephSnapshots, CephTotalSnapshots: $totalSnapshots, clustername: $clustername, qemuInfo: $qemuInfo, cephInfo: $cephInfo, oldestSnapshot: $oldestSnapshot}')

        UPDATED_VMS+=("$UPDATED_ELMNT")
    done
    # Output the updated VM data
    echo "<<<proxmox-qemu>>>" > "/usr/lib/PVE_INFO.txt"
    echo "${UPDATED_VMS[@]}" | jq -s '.' >> "/usr/lib/PVE_INFO.txt"
fi
