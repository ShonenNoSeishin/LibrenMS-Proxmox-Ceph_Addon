#!/bin/bash
# Proxmox VE virtual machine listing
# (c) 2015-2019, Tom Laermans for Observium

# verify if returnPveInfo script is already running
another_instance()
{
        echo "Fill Cache is running already." >>/var/log/fill-cache.log
        exit 1
}

INSTANCES=`lsof -t "$0" | wc -l`
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

  # Récupérer les informations des OSD Ceph au format JSON
  ceph_data=$(/usr/bin/ceph osd df -f json)

  # Vérification si la commande a réussi
  if [ $? -ne 0 ]; then
      echo "Error with Ceph execution"
      exit 1
  fi

  # Extraire le statut des OSD et initialiser les variables
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

  Ceph_Info=""
  if [ $warning = true ]; then
      Ceph_Info="WARNING : Followed disks aren't 'up' : $warned_disks"
  else
      Ceph_Info="Disks 'up'"
  fi

  # Initialize an array to hold updated VM information
  UPDATED_VMS=()
  CPU_TOTAL_DATA=$(pvesh get cluster/resources --output-format json)
  CEPH_TOTAL_INFO=$(rbd du -p CephPool)

  for ELMNT in $(echo "$VM_LIST" | jq -c '.[]'); do
      VMID=$(echo "$ELMNT" | jq -r '.vmid')
      QEMU_INFO=$(/usr/sbin/qm agent $VMID network-get-interfaces 2>/dev/null | jq -r '.[] | ."ip-addresses" | .[] | ."ip-address"')
      CPU_DATA=$(echo "$CPU_TOTAL_DATA" | jq --arg id "qemu/$VMID" '.[] | select(.id == $id) | ((.cpu * 100 / .maxcpu) * 1000 | round / 1000)')
      CEPH_DISK_INFO=$(echo "$CEPH_TOTAL_INFO" | grep -E "vm-$VMID-disk-*" | grep -v '@')
      ARRAY_DISKS=()
      BIGGER=0

      # Calculate oldest snapshot
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

      while read -r name size sizeUnit used usedUnit; do
          if [[ -n "$name" ]]; then
              size_num=$(echo "$size" | sed 's/[^0-9.]//g')
              if (( $(echo "$size_num > 2" | bc -l) )) && [[ "$sizeUnit" == "GiB" ]] || [[ "$sizeUnit" != "MiB" ]]; then
                  used_num=$(echo "$used" | sed 's/[^0-9.]//g')
                  POURCENT=$(awk "BEGIN {print ($used_num/$size_num) * 100}")
                  if (( $(echo "$BIGGER < $POURCENT" | bc -l) )); then
                      BIGGER=$POURCENT
                  fi
                  ARRAY_DISKS+=("$name : $used_num / $size_num $sizeUnit")
              fi
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

      # Modify ELMNT with new values including oldestSnapshot
      UPDATED_ELMNT=$(echo "$ELMNT" | jq \
          --arg cpu "$CPU_DATA" \
          --argjson cephDisks "$(printf '%s\n' "${ARRAY_DISKS[@]}" | jq -R . | jq -s .)" \
          --arg cephBiggerDiskPourcentUsage "$(printf '%.2f' $BIGGER)" \
          --arg totalSnapshots "$(printf '%.1f' $TOTAL_SNAPSHOTS)" \
          --argjson cephSnapshots "$(printf '%s\n' "${ARRAY_SNAPSHOTS[@]}" | jq -R . | jq -s .)" \
          --arg clustername "$clustername" \
          --arg qemuInfo "$QEMU_INFO" \
          --arg cephInfo "$Ceph_Info" \
          --arg oldestSnapshot "$max_days" \
          '. + {cpu: $cpu, cephDisks: $cephDisks, cephBiggerDiskPourcentUsage: $cephBiggerDiskPourcentUsage, cephSnapshots: $cephSnapshots, CephTotalSnapshots: $totalSnapshots, clustername: $clustername, qemuInfo: $qemuInfo, cephInfo: $cephInfo, oldestSnapshot: $oldestSnapshot}')

      UPDATED_VMS+=("$UPDATED_ELMNT")
  done
  # Output the final updated VM data
  echo "<<<proxmox-qemu>>>" > "/usr/lib/PVE_INFO.txt"
  echo "${UPDATED_VMS[@]}" | jq -s '.' >> "/usr/lib/PVE_INFO.txt"
fi
