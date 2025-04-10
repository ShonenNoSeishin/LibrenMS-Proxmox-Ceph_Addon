#!/bin/bash

source /usr/lib/.env
CephPoolName="Pool-Replica-3"
MAX_THREADS=8
TEMP_DIR="/tmp/vm_data"

LOG_FILE="/usr/lib/returnpveinfo.log"

# Don t send to syslog
exec >"$LOG_FILE" 2>&1

# Verify if returnPveInfo script is already running
another_instance() {
   echo "Fill Cache is running already." >>/var/log/fill-cache.log
   exit 1
}

INSTANCES=$(lsof -t "$0" | wc -l)
if [ "$INSTANCES" -gt 1 ]; then
   another_instance
fi

mkdir -p "$TEMP_DIR"

contains() {
    local haystack="$1"
    local needle="$2"
    [[ "$haystack" =~ (^|[[:space:]])${needle}($|[[:space:]]) ]] && return 0 || return 1
}


# Fonction pour traiter un VM
process_vm() {
   local NODE=$1
   local VMID=$2
   local TEMP_FILE="$TEMP_DIR/${NODE}_${VMID}.json"
   local TOTAL_DATA=$3
   local clustername=$4
   local Ceph_Info=$5
   local CEPH_TOTAL_INFO=$6
   local CEPH_POOL_USAGE=$7

   if curl -s -k --connect-timeout 2 -H "$Authorization" "https://$PVE_IP:8006/api2/json/nodes/$NODE/qemu/$VMID/config" | grep -q snap; then
        echo "Un snapshot est en cours pour la VM $VMID sur le node $NODE. Sortie de la fonction."
        return  # Sort de la fonction sans arrêter le script
   fi

   #sleep 0.25
   #VM_DATA=$(pvesh get /nodes/$NODE/qemu/$VMID/status/current --output-format=json-pretty | jq '{cpu, cpus, diskread, diskwrite, maxdisk, maxmem, mem, name, netin, netout, pid, status, tags, uptime, vmid}')
   VM_DATA=$(curl -s -k --connect-timeout 2 -H "$Authorization" "https://$PVE_IP:8006/api2/json/nodes/$NODE/qemu/$VMID/status/current" | jq '.data | {cpu, cpus, diskread, diskwrite, maxdisk, maxmem, mem, name, netin, netout, pid, status, tags, uptime, vmid}')
   VM_STATUS=$(echo "$VM_DATA" | jq -r .status)
   sleep 0.25
    if [[ "$VM_STATUS" == "running" ]]; then
        #QEMU_INFO=$(pvesh get /nodes/$NODE/qemu/$VMID/agent/network-get-interfaces --output-format json | jq '.result' 2>/dev/null | jq -r '.[] | ."ip-addresses" | .[] | ."ip-address"' 2>/dev/null || echo "null")
        QEMU_INFO=$(curl -s -k --connect-timeout 2 -X GET -H "$Authorization" "https://$PVE_IP:8006/api2/json/nodes/$NODE/qemu/$VMID/agent/network-get-interfaces" | jq -r '.data.result? | if . then .[]?["ip-addresses"]? // [] | .[]?["ip-address"]? // empty else "null" end')
        sleep 0.25
    else
        QEMU_INFO="null"
    fi

   CPU_DATA=$(echo "$TOTAL_DATA" | jq --arg id "qemu/$VMID" '.[] | select(.id == $id) | .cpu')
   CPU_PERCENT=$(echo "$TOTAL_DATA" | jq --arg id "qemu/$VMID" '.[] | select(.id == $id) | ((.cpu * 100 / .maxcpu) * 1000 | round / 1000)')

   ARRAY_DISKS=()
   BIGGER=0

   

   #snapshots=$(pvesh get /nodes/$NODE/qemu/$VMID/snapshot --output-format json | jq -r '.[] | select(.name != "current") | .name + " " + (.snaptime | todate | split("T")[0]) + " " + (.snaptime | todate | split("T")[1] | split("+")[0] | split("Z")[0])')
   if [[ "$QEMU_INFO" != "null" ]]; then
        snapshots=$(curl -s -k --connect-timeout 2 -X GET -H "$Authorization" "https://$PVE_IP:8006/api2/json/nodes/$NODE/qemu/$VMID/snapshot" | jq -r '.data[] | select(.name != "current") | .name + " " + (.snaptime | todate | split("T")[0]) + " " + (.snaptime | todate | split("T")[1] | split("+")[0] | split("Z")[0])')
        sleep 0.25
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

        if [[ "$VM_STATUS" == "running" ]]; then
            #sleep 0.25
            # DISK_INFO=$(pvesh get /nodes/${NODE}/qemu/$VMID/agent/get-fsinfo --output-format json 2>/dev/null | jq -r '
            #     .result[] | 
            #     select(.["used-bytes"] != null and .["total-bytes"] != null) | 
            #     "\(.mountpoint) \(.["used-bytes"] / 1024 / 1024 / 1024) GiB \(.["total-bytes"] / 1024 / 1024 / 1024) GiB"
            # ')

            DISK_INFO=$(curl -s -k --connect-timeout 2 -X GET -H "$Authorization" "https://$PVE_IP:8006/api2/json/nodes/$NODE/qemu/$VMID/agent/get-fsinfo" \
            | jq -r '
                if .data != null and .data.result != null then
                    .data.result[] | select(.["used-bytes"] != null and .["total-bytes"] != null) |
                    "\(.mountpoint) \((.["used-bytes"] / 1024 / 1024 / 1024) | tonumber) GiB \((.["total-bytes"] / 1024 / 1024 / 1024) | tonumber) GiB"
                else
                    empty
                end
            ')
            sleep 0.25
            if [[ "$DISK_INFO" != *"QEMU guest agent is not running"* && "$DISK_INFO" != *"is not running"* && "$DISK_INFO" != *"No QEMU guest agent configured"* ]]; then
                while read -r disk_name GiB_used used_unit GiB_size size_unit; do
                    if [[ -n "$disk_name" && ! "$disk_name" =~ ^/snap ]]; then # to not take in count /snap/lxd... disks
                        max_disk=$(echo "$GiB_size" | sed 's/[^0-9.]//g')
                        used_disk=$(echo "$GiB_used" | sed 's/[^0-9.]//g')
                        
                        if [[ -n "$used_disk" ]] && (( $(awk "BEGIN {print ($max_disk != 0)}") )); then
                            POURCENT=$(awk "BEGIN {print ($used_disk / $max_disk) * 100}")
                            if (( $(echo "$BIGGER < $POURCENT" | bc -l) )); then
                                BIGGER=$POURCENT
                            fi
                        fi

                        used_disk_rounded=$(printf "%.2f" "$used_disk")
                        max_disk_rounded=$(printf "%.2f" "$max_disk")
                        ARRAY_DISKS+=("$disk_name : $used_disk_rounded / $max_disk_rounded $size_unit")
                    fi
                done <<< "$DISK_INFO"
            fi
        fi

   else
      snapshots="null"
      max_days=0
   fi

   TOTAL_SNAPSHOTS=0
   ARRAY_SNAPSHOTS=()
   if [ "$CEPH_TOTAL_INFO" != "null" ]; then
       CEPH_SNAPSHOTS=$(echo "$CEPH_TOTAL_INFO" | grep "vm-$VMID-disk-.*@")
       i=0
       while read -r name size sizeUnit used usedUnit; do
           if [[ -n "$name" ]]; then
               size_num=$(echo "$size" | sed 's/[^0-9.]//g')
               used_num=$(echo "$used" | sed 's/[^0-9.]//g')

               case $usedUnit in
                   "GiB") used_gib=$used_num ;;
                   "MiB") used_gib=$(awk "BEGIN {print $used_num / 1024}") ;;
                   "KiB") used_gib=$(awk "BEGIN {print $used_num / 1024 / 1024}") ;;
               esac
	           # Remove the first entry, which is just a reference to memory state at a given time, not actual used space
               if [ $i -ne 0 ]; then
		           TOTAL_SNAPSHOTS=$(awk "BEGIN {print $TOTAL_SNAPSHOTS + $used_gib}")
        	       ARRAY_SNAPSHOTS+=("$name : $used_num $usedUnit / $size_num $sizeUnit")
	           fi
           fi
	   i=$((i+1))
       done <<< "$CEPH_SNAPSHOTS"

   fi

   last_update=$(date +"%H:%M:%S")

   HA_State=$(curl -s -k --connect-timeout 2 -H "$Authorization" "https://10.61.0.51:8006/api2/json/nodes/$NODE/qemu/$VMID/status/current" | jq '.data.ha.state')
   sleep 0.25
   
   # Nettoyer les espaces et retours à la ligne éventuels
    HA_State=$(echo "$HA_State" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
    # echo "$HA_State"

    sleep 0.25

    # Comparaison des chaînes et ajustement de la valeur de HA_State
    if [[ -z "$HA_State" || $HA_State == "null" ]]; then
        HA_State="Out_of_HA"
    elif [[ "$HA_State" == \"error\" ]]; then
        HA_State="Error"
    elif [[ "$HA_State" == \"started\" ]]; then
        HA_State="UP"
    fi

   VM_JSON=$(echo "$VM_DATA" | jq \
       --arg cpu "$CPU_DATA" \
       --arg cpu_percent "$CPU_PERCENT" \
       --argjson disk "$(printf '%s\n' "${ARRAY_DISKS[@]}" | jq -R . | jq -s .)" \
       --arg biggerDiskPercentUsage "$(printf '%.2f' $BIGGER)" \
       --arg totalSnapshots "$(printf '%.1f' $TOTAL_SNAPSHOTS)" \
       --argjson cephSnapshots "$(printf '%s\n' "${ARRAY_SNAPSHOTS[@]}" | jq -R . | jq -s .)" \
       --arg clustername "$clustername" \
       --arg qemuInfo "$QEMU_INFO" \
       --arg cephInfo "$Ceph_Info" \
       --arg HA_State $HA_State \
       --arg cephPoolUsage "$CEPH_POOL_USAGE" \
       --arg oldestSnapshot "$max_days" \
       --arg node "$NODE" \
       --arg vmid "$VMID" \
       --arg last_update "$last_update" \
       '. + {vmid: $vmid, cpu: $cpu, cpu_percent: $cpu_percent, disk: $disk, biggerDiskPercentUsage: $biggerDiskPercentUsage, cephSnapshots: $cephSnapshots, CephTotalSnapshots: $totalSnapshots, clustername: $clustername, qemuInfo: $qemuInfo, cephInfo: $cephInfo, HA_State: $HA_State, cephPoolUsage: $cephPoolUsage, oldestSnapshot: $oldestSnapshot, node: $node, last_update: $last_update}')

   echo "$VM_JSON" > "$TEMP_FILE"
   return 0
}

PVESH=$(which pvesh)
if [ $? -eq 0 ]; then
   clustername=$(grep 'cluster_name' /etc/pve/corosync.conf | awk '{print $2}')
   #TOTAL_DATA=$(pvesh get cluster/resources --output-format json)
   TOTAL_DATA=$(curl -s -k --connect-timeout 2 -H "$Authorization" "https://$PVE_IP:8006/api2/json/cluster/resources" | jq -c '.data')
   VERSION=$(pveversion | awk -F/ '{print $2}' | sed 's/\..*//')

   NODES=$(echo "$TOTAL_DATA" | jq -r '.[] | select(.type == "node") | .node' || hostname)
   if [ -z "$NODES" ]; then
       NODES=$(hostname)
   fi

   if command -v ceph >/dev/null 2>&1; then
       ceph_data=$(/usr/bin/ceph osd df -f json 2>/dev/null)
       if [ $? -ne 0 ] || [ -z "$ceph_data" ]; then
           Ceph_Info="null"
           CEPH_TOTAL_INFO="null"
           CEPH_POOL_USAGE="null"
       else
           CEPH_POOL_USAGE=$(printf "%.2f%%\n" "$(ceph df --format=json | jq '.pools[] | select(.name=="'"$CephPoolName"'") | (.stats.percent_used * 100)')")
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
           CEPH_TOTAL_INFO=$(rbd du -p "$CephPoolName" 2>/dev/null || echo "null")
       fi
   else
       Ceph_Info="null"
       CEPH_TOTAL_INFO="null"
       CEPH_POOL_USAGE="null"
   fi

   for NODE in $NODES; do
       NODE_VMS=$(echo "$TOTAL_DATA" | jq -r --arg node "$NODE" '.[] | select(.type == "qemu" and .node == $node) | .vmid')
       
       running=0
       for VMID in $NODE_VMS; do
           process_vm "$NODE" "$VMID" "$TOTAL_DATA" "$clustername" "$Ceph_Info" "$CEPH_TOTAL_INFO" "$CEPH_POOL_USAGE" &
           ((running++))
           if ((running >= MAX_THREADS)); then
               wait -n
               ((running--))
           fi
       done
       wait
   done

   ALL_VMS=()
   for f in "$TEMP_DIR"/*.json; do
       [ -f "$f" ] && ALL_VMS+=("$(cat "$f")")
   done

    # Output all VMs data from all nodes
    echo "<<<proxmox-qemu>>>" > "/usr/lib/TMP_PVE_INFO.txt"
    echo "${ALL_VMS[@]}" | jq -s '.' >> "/usr/lib/TMP_PVE_INFO.txt"
    cp /usr/lib/TMP_PVE_INFO.txt /usr/lib/PVE_INFO.txt

    #cat "/usr/lib/PVE_INFO.txt" | gzip -c > "/usr/lib/ENC_PVE_INFO.txt"
    xz -9 -c /usr/lib/TMP_PVE_INFO.txt > "/usr/lib/ENC_PVE_INFO.txt"

    # Chemin du fichier source
    SOURCE_FILE="/usr/lib/ENC_PVE_INFO.txt"

    # Vérifier que le fichier source existe
    if [ ! -f "$SOURCE_FILE" ]; then
        echo "Erreur : Fichier $SOURCE_FILE introuvable."
        exit 1
    fi
    FILE_SIZE=$(stat -c%s "/usr/lib/TMP_PVE_INFO.txt")
    CHUNK_SIZE=$(( (FILE_SIZE + 4) / 5 ))

    # Diviser, compresser, encoder chaque chunk en base64, et écrire
    for i in $(seq 1 5); do
        START_BYTE=$((CHUNK_SIZE * (i - 1)))
        # Fichier temporaire pour le chunk du JSON
        CHUNK_TXT="/tmp/chunk_$i.txt"
        dd if="/usr/lib/TMP_PVE_INFO.txt" of="$CHUNK_TXT" bs=1 skip="$START_BYTE" count="$CHUNK_SIZE" status=none
        
        # Compresser puis encoder ce chunk
        xz -9 -c "$CHUNK_TXT" | base64 -w0 > "/usr/lib/PVE_INFO${i}.txt"
        echo "Partie $i encodée dans /usr/lib/PVE_INFO${i}.txt."
        rm -f "$CHUNK_TXT"
    done

fi
