#!/bin/bash
# Proxmox VE virtual machine listing
# (c) 2015-2019, Tom Laermans for Observium

$CephPoolName="MyCephPoolName"

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
    ceph_disks="null"
    ceph_snapshots="null"
    cephBiggerDiskPourcentUsage="null"
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
        CEPH_TOTAL_INFO=$(rbd du -p $CephPoolName 2>/dev/null || echo "null")
    fi
else
    # Set Ceph-related variables to null if Ceph is not installed
    Ceph_Info="null"
    ceph_status="null"
    ceph_disks="null"
    ceph_snapshots="null"
    cephBiggerDiskPourcentUsage="null"
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

        if command -v ceph >/dev/null 2>&1; then
            CEPH_DISK_INFO=$(echo "$CEPH_TOTAL_INFO" | grep -E "vm-$VMID-disk-*" | grep -v '@')
            while read -r name size sizeUnit used usedUnit; do
                if [[ -n "$name" ]]; then
                    size_num=$(echo "$size" | sed 's/[^0-9.]//g')
                    used_num=$(echo "$used" | sed 's/[^0-9.]//g')
                    POURCENT=$(awk "BEGIN {print ($used_num/$size_num) * 100}")
                    if (( $(echo "$BIGGER < $POURCENT" | bc -l) )); then
                        BIGGER=$POURCENT
                    fi
                    ARRAY_DISKS+=("$name : $used_num / $size_num $sizeUnit")
                fi
            done <<< "$CEPH_DISK_INFO"
            CEPH_SNAPSHOTS=$(echo "$CEPH_TOTAL_INFO" | grep "vm-$VMID-state")
            TOTAL_SNAPSHOTS=0
            ARRAY_SNAPSHOTS=()
            while read -r name size sizeUnit used usedUnit; do
                if [[ -n "$name" ]]; then
                    size_num=$(echo "$size" | sed 's/[^0-9.]//g')
                    used_num=$(echo "$used" | sed 's/[^0-9.]//g')
                    TOTAL_SNAPSHOTS=$(awk "BEGIN {print $TOTAL_SNAPSHOTS + $used_num}")
                    ARRAY_SNAPSHOTS+=("$name : $size_num $sizeUnit")
                fi
            done <<< "$CEPH_SNAPSHOTS"
        fi

        # Modify ELMNT with new values
        UPDATED_ELMNT=$(echo "$ELMNT" | jq \
            --arg cpu "$CPU_DATA" \
            --arg cpu_percent "$CPU_PERCENT" \
            --argjson cephDisks "$(printf '%s\n' "${ARRAY_DISKS[@]}" | jq -R . | jq -s .)" \
            --arg cephBiggerDiskPercentUsage "$(printf '%.2f' $BIGGER)" \
            --arg totalSnapshots "$(printf '%.1f' $TOTAL_SNAPSHOTS)" \
            --argjson cephSnapshots "$(printf '%s\n' "${ARRAY_SNAPSHOTS[@]}" | jq -R . | jq -s .)" \
            --arg clustername "$clustername" \
            --arg qemuInfo "$QEMU_INFO" \
            --arg cephInfo "$Ceph_Info" \
            --arg oldestSnapshot "$max_days" \
            '. + {cpu: $cpu, cpu_percent: $cpu_percent, cephDisks: $cephDisks, cephBiggerDiskPercentUsage: $cephBiggerDiskPercentUsage, cephSnapshots: $cephSnapshots, CephTotalSnapshots: $totalSnapshots, clustername: $clustername, qemuInfo: $qemuInfo, cephInfo: $cephInfo, oldestSnapshot: $oldestSnapshot}')

        UPDATED_VMS+=("$UPDATED_ELMNT")
    done
    # Output the updated VM data
    echo "<<<proxmox-qemu>>>" > "/usr/lib/PVE_INFO.txt"
    echo "${UPDATED_VMS[@]}" | jq -s '.' >> "/usr/lib/PVE_INFO.txt"
fi
