#!/bin/bash

source /usr/lib/.env
CephPoolName="Pool-Replica-3"
MAX_THREADS=8
TEMP_DIR="/tmp/vm_data"

LOG_FILE="/usr/lib/returnpveinfo.log"

# Initialize counters for reporting
TOTAL_VMS=0
PROCESSED_VMS=0
FAILED_VMS=0
RETRIED_VMS=0

# Don't send to syslog
exec >"$LOG_FILE" 2>&1

timestamp() {
	date +"%Y-%m-%d %H:%M:%S"
}

log_message() {
	echo "[$(timestamp)] $1" >> "$LOG_FILE"
}

# Verify if returnPveInfo script is already running
another_instance() {
	log_message "Fill Cache is running already."
	echo "Fill Cache is running already." >>/var/log/fill-cache.log
	exit 1
}

INSTANCES=$(lsof -t "$0" | wc -l)
if [ "$INSTANCES" -gt 1 ]; then
	another_instance
fi

mkdir -p "$TEMP_DIR"
mkdir -p "$TEMP_DIR"/bak

# Backup old temp files with timestamp
TODAY=$(date +%Y%m%d)
for i in "$TEMP_DIR"/*.json
do
	if [ -f "$i" ]; then
		FILENAME=$(basename "$i")
		TIMESTAMP=$(date +"%H:%M:%S")
		echo "--- Backup at $TIMESTAMP ---" >> "$TEMP_DIR/bak/${TODAY}_${FILENAME}"
		cat "$i" >> "$TEMP_DIR/bak/${TODAY}_${FILENAME}"
	fi
done

# Delete files older than 2 weeks
find "$TEMP_DIR/bak" -type f -name "*.json" -mtime +14 -delete

# Backup and delete previous Json files before running the script
cp -r "$TEMP_DIR"/*.json "$TEMP_DIR/bak/" # Backup current files before deleting
rm -f "$TEMP_DIR"/*.json

contains() {
	local haystack="$1"
	local needle="$2"
	[[ "$haystack" =~ (^|[[:space:]])${needle}($|[[:space:]]) ]] && return 0 || return 1
}

# Function to process a VM with retry mechanism
process_vm() {
	local NODE=$1
	local VMID=$2
	local TEMP_FILE="$TEMP_DIR/${NODE}_${VMID}.json"
	local TOTAL_DATA=$3
	local clustername=$4
	local Ceph_Info=$5
	local CEPH_TOTAL_INFO=$6
	local CEPH_POOL_USAGE=$7
	local RETRY=${8:-0}  # Default retry count is 0
	
	# Add a small random delay to avoid API request bursts
	sleep $(awk "BEGIN { print 0.1 + (0.3 * rand()) }")
	
	# Set a longer timeout for larger VMs or if this is a retry
	local TIMEOUT=2
	if [ "$RETRY" -gt 0 ]; then
		TIMEOUT=5  # Longer timeout for retries
	fi

	log_message "Starting process for VM $VMID on node $NODE (try: $((RETRY+1)))"

	# Get VM basic data
	#VM_DATA=$(pvesh get /nodes/$NODE/qemu/$VMID/status/current --output-format=json-pretty | jq '{cpu, cpus, diskread, diskwrite, maxdisk, maxmem, mem, name, netin, netout, pid, status, tags, uptime, vmid}')
	VM_DATA=$(curl -s -k --connect-timeout $TIMEOUT -H "$Authorization" "https://$PVE_IP:8006/api2/json/nodes/$NODE/qemu/$VMID/status/current" 2>/dev/null)
	
	# Check if curl failed or returned empty data
	if [ $? -ne 0 ] || [ -z "$VM_DATA" ] || ! echo "$VM_DATA" | jq -e '.data' >/dev/null 2>&1; then
		/usr/bin/logger -p daemon.warning "RETURNPVEINFO agent timeout de la vm $VMID provoqué par la requête VM_DATA (status/current)"
		log_message "ERROR: Failed to get basic data for VM $VMID on node $NODE"
		if [ "$RETRY" -lt 2 ]; then
			log_message "Retrying VM $VMID on node $NODE (attempt $((RETRY+2)))"
			((RETRIED_VMS++))
			sleep 3  # Wait before retry
			process_vm "$NODE" "$VMID" "$TOTAL_DATA" "$clustername" "$Ceph_Info" "$CEPH_TOTAL_INFO" "$CEPH_POOL_USAGE" $((RETRY+1))
			return $?
		else
			log_message "CRITICAL: Failed to process VM $VMID on node $NODE after 3 attempts"
			((FAILED_VMS++))
			return 1
		fi
	fi

	# Extract the VM information from the response
	VM_DATA=$(echo "$VM_DATA" | jq '.data | {cpu, cpus, diskread, diskwrite, maxdisk, maxmem, mem, name, netin, netout, pid, status, tags, uptime, vmid, qmpstatus, tags}')
	VM_STATUS=$(echo "$VM_DATA" | jq -r .qmpstatus 2>/dev/null)
	VM_NAME=$(echo "$VM_DATA" | jq -r .name 2>/dev/null)
	VM_TAGS=$(echo "$VM_DATA" | jq -r '.tags' 2>/dev/null) # Exemple de tags: "tag1;tag2;tag3"

	# When the VM is closed but in backup state (not snapshot), the status is "running" and the qmpstatus is "prelaunch" so we should say that state is stopped
	if [[ "$VM_STATUS" == "prelaunch" ]]; then
		# Check .status field to determine if the VM is actually status is "running" and the qmpstatus is "prelaunch"
		TMP_VM_STATUS=$(echo "$VM_DATA" | jq -r .status 2>/dev/null)
		if [[ "$TMP_VM_STATUS" == "running" ]]; then
			VM_STATUS="stopped"
		fi
	fi
	
	if [ -z "$VM_STATUS" ]; then
		log_message "ERROR: Could not determine status for VM $VMID"
		if [ "$RETRY" -lt 2 ]; then
			((RETRIED_VMS++))
			sleep 2
			process_vm "$NODE" "$VMID" "$TOTAL_DATA" "$clustername" "$Ceph_Info" "$CEPH_TOTAL_INFO" "$CEPH_POOL_USAGE" $((RETRY+1)) &
			return 0
		else
			((FAILED_VMS++))
			return 1
		fi
	fi
	
	sleep 2
	
	# Create list of keywords to check for in VM_NAME
	NO_QEMU_KEYWORDS=("Elastix" "Fortinet" "Sophos" "Owncloud" "HPEOneView" )
	NO_DISK_KEYWORDS=("Ubuntu-TSHARK" "Ubuntu-Zabbix-Humbert" "Ubuntu-Provisioning-001-001" "NextCloud-Umedia" "Centos-Dhondt")

	# Function to check if VM name contains any of the keywords
	check_keywords() {
		local vm_name="$1"
		for keyword in "${NO_QEMU_KEYWORDS[@]}"; do
			if [[ "$vm_name" == *"$keyword"* ]]; then
				return 0  # Found a match
			fi
		done
		return 1  # No match found
	}

	# Function to check if VM name contains any of the keywords
	check_disk_keywords() {
		local vm_name="$1"
		for keyword in "${NO_DISK_KEYWORDS[@]}"; do
			if [[ "$vm_name" == *"$keyword"* ]]; then
				return 0  # Found a match
			fi
		done
		return 1  # No match found
	}

	# Fonction pour vérifier si les tags contiennent une valeur spécifique
	check_tag_keyword() {
		local tags="$1"
		local keyword="$2"
		IFS=';' read -ra TAG_ARRAY <<< "$tags"
		for tag in "${TAG_ARRAY[@]}"; do
			if [[ "$tag" == "$keyword" ]]; then
				return 0  # trouvé
			fi
		done
		return 1  # pas trouvé
	}

	if [[ "$VM_STATUS" != "running" ]] && [ ! -z "$VM_STATUS" ] && [[ "$VM_STATUS" != "stopped" ]]; then
		# To check qmp_status in real time : curl -s -k --connect-timeout $TIMEOUT -H "$Authorization" "https://$PVE_IP:8006/api2/json/nodes/$NODE/qemu/$VMID/status/current" | jq '.data | {cpu, cpus, diskread, diskwrite, maxdisk, maxmem, mem, name, netin, netout, pid, status, tags, uptime, vmid, qmpstatus, tags}'
		sleep 2 # Je reçois environ 10 à 15 logs par salve de snapshots
		# Request the current status of the VM
		VM_STATUS=$(curl -s -k --connect-timeout $TIMEOUT -H "$Authorization" "https://$PVE_IP:8006/api2/json/nodes/$NODE/qemu/$VMID/status/current" | jq -r '.data.qmpstatus')
		if [[ "$VM_STATUS" == "save-vm" ]]; then
			/usr/bin/logger -p daemon.warning "INFO : VM $VMID on node $NODE is probably in vm-save state (state after a snapshot)"
			sleep 2
			# Request the current status of the VM
			VM_STATUS=$(curl -s -k --connect-timeout $TIMEOUT -H "$Authorization" "https://$PVE_IP:8006/api2/json/nodes/$NODE/qemu/$VMID/status/current" | jq -r '.data.qmpstatus')
			# Recheck
			if [[ "$VM_STATUS" == "save-vm" ]]; then
				/usr/bin/logger -p daemon.warning "INFO : VM $VMID on node $NODE is still in vm-save state (state after a snapshot)"
				sleep 4
				# Request the current status of the VM
				VM_STATUS=$(curl -s -k --connect-timeout $TIMEOUT -H "$Authorization" "https://$PVE_IP:8006/api2/json/nodes/$NODE/qemu/$VMID/status/current" | jq -r '.data.qmpstatus')
			fi	
		fi
	fi
	

	# Get network interfaces if VM is running and name doesn't contain forbidden keywords
	if [[ "$VM_STATUS" == "running" ]] && ! check_keywords "$VM_NAME" && ! check_tag_keyword "$VM_TAGS" "no-qemu"; then
		# Verify if VM is lock to not overload vm agent
		SNAP_LOCK=$(curl -s -k --connect-timeout $TIMEOUT -H "$Authorization" "https://$PVE_IP:8006/api2/json/nodes/$NODE/qemu/$VMID/status/current" 2>/dev/null | jq -r '.data.lock == "snapshot"')
		if [[ "$SNAP_LOCK" == "true" ]]; then
			sleep 20
			# Retry after waiting
			SNAP_LOCK=$(curl -s -k --connect-timeout $TIMEOUT -H "$Authorization" "https://$PVE_IP:8006/api2/json/nodes/$NODE/qemu/$VMID/status/current" 2>/dev/null | jq -r '.data.lock == "snapshot"')
			if [[ "$SNAP_LOCK" == "true" ]]; then
				/usr/bin/logger -p daemon.warning "RETURNPVEINFO la vm $VMID ne se met pas à jour dans le monitoring car en cours de snapshot depuis plus de 20 secondes"
				# Sortir de la fonction process_vm pour ne pas update le fichier $TEMPFILE
				cp "$TEMP_DIR/bak/$NODE_$VMID.json" "$TEMP_DIR/" # Restore the old file
				return 0
 				#QEMU_INFO="null"
			fi
		fi
		if [[ "$SNAP_LOCK" != "true" ]]; then # second if instead of else if the snapshot is finished during the previous check
			#QEMU_INFO=$(pvesh get /nodes/$NODE/qemu/$VMID/agent/network-get-interfaces --output-format json | jq '.result' 2>/dev/null | jq -r '.[] | ."ip-addresses" | .[] | ."ip-address"' 2>/dev/null || echo "null")
			QEMU_INFO=$(curl -s -k --connect-timeout $TIMEOUT -X GET -H "$Authorization" "https://$PVE_IP:8006/api2/json/nodes/$NODE/qemu/$VMID/agent/network-get-interfaces" 2>/dev/null | jq -r '.data.result? | if . then .[]?["ip-addresses"]? // [] | .[]?["ip-address"]? // empty else "null" end')
			sleep 0.2
			if [[ $QEMU_INFO == "null" ]]; then
				sleep 4.3  # Wait a bit longer for the agent to respond
				/usr/bin/logger -p daemon.warning "RETURNPVEINFO agent timeout de la vm $VMID provoqué par la requête 1 de QEMU_INFO (network interfaces)"
				QEMU_INFO=$(curl -s -k --connect-timeout $TIMEOUT -X GET -H "$Authorization" "https://$PVE_IP:8006/api2/json/nodes/$NODE/qemu/$VMID/agent/network-get-interfaces" 2>/dev/null | jq -r '.data.result? | if . then .[]?["ip-addresses"]? // [] | .[]?["ip-address"]? // empty else "null" end')
				if [[ "$QEMU_INFO" == "null" ]]; then
					/usr/bin/logger -p daemon.warning "RETURNPVEINFO agent timeout de la vm $VMID provoqué par la requête 2 de QEMU_INFO (network interfaces)"
				fi
			fi
		fi
	else
		# For test puprose
		# if check_tag_keyword "$VM_TAGS" "no-qemu"; then
		# 	/usr/bin/logger -p daemon.warning "VM $VMID on node $NODE qemu_info = null cause of no-qemu tag"
		# fi
		QEMU_INFO="null"
	fi


	# Get CPU data
	CPU_DATA=$(echo "$TOTAL_DATA" | jq --arg id "qemu/$VMID" '.[] | select(.id == $id) | .cpu' 2>/dev/null)
	CPU_PERCENT=$(echo "$TOTAL_DATA" | jq --arg id "qemu/$VMID" '.[] | select(.id == $id) | ((.cpu * 100 / .maxcpu) * 1000 | round / 1000)' 2>/dev/null)

	ARRAY_DISKS=()
	BIGGER=0
	
	# Get snapshots if available
	if [[ "$QEMU_INFO" != "null" ]]; then
		#snapshots=$(pvesh get /nodes/$NODE/qemu/$VMID/snapshot --output-format json | jq -r '.[] | select(.name != "current") | .name + " " + (.snaptime | todate | split("T")[0]) + " " + (.snaptime | todate | split("T")[1] | split("+")[0] | split("Z")[0])')
		snapshots=$(curl -s -k --connect-timeout $TIMEOUT -X GET -H "$Authorization" "https://$PVE_IP:8006/api2/json/nodes/$NODE/qemu/$VMID/snapshot" 2>/dev/null | jq -r '.data[] | select(.name != "current") | .name + " " + (.snaptime | todate | split("T")[0]) + " " + (.snaptime | todate | split("T")[1] | split("+")[0] | split("Z")[0])')
		if [ $? -ne 0 ] || [ -z "$snapshots" ]; then
			/usr/bin/logger -p daemon.warning "RETURNPVEINFO agent timeout de la vm $VMID provoqué par la requête SNAPSHOTS (snapshot list)"
		fi
		sleep 0.25
		current_date=$(date +%s)
		max_days=0

		while read -r name date time; do
			if [[ $date =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]]; then
				snapshot_datetime="$date $time"
				snapshot_timestamp=$(date -d "$snapshot_datetime" +%s 2>/dev/null)
				if [ $? -eq 0 ]; then  # Make sure date conversion succeeded
					days=$(( (current_date - snapshot_timestamp) / 86400 ))
					if [ $days -gt $max_days ]; then
						max_days=$days
					fi
				fi
			fi
		done <<< "$snapshots"

		if [[ "$VM_STATUS" == "running" ]]  && ! check_disk_keywords "$VM_NAME"; then
			# Get disk information with better error handling
			# DISK_INFO=$(pvesh get /nodes/${NODE}/qemu/$VMID/agent/get-fsinfo --output-format json 2>/dev/null | jq -r '
            #     .result[] | 
            #     select(.["used-bytes"] != null and .["total-bytes"] != null) | 
            #     "\(.mountpoint) \(.["used-bytes"] / 1024 / 1024 / 1024) GiB \(.["total-bytes"] / 1024 / 1024 / 1024) GiB"
            # ')
			DISK_INFO=$(curl -s -k --connect-timeout $TIMEOUT -X GET -H "$Authorization" "https://$PVE_IP:8006/api2/json/nodes/$NODE/qemu/$VMID/agent/get-fsinfo" 2>/dev/null | 
			jq -r '
				if .data != null and .data.result != null then
					.data.result[] | select(.["used-bytes"] != null and .["total-bytes"] != null) |
					"\(.mountpoint) \((.["used-bytes"] / 1024 / 1024 / 1024) | tonumber) GiB \((.["total-bytes"] / 1024 / 1024 / 1024) | tonumber) GiB"
				else
					empty
				end
			')
			sleep 0.25
			
			# Retry if agent is busy
			if [[ -z "$DISK_INFO" ]]; then
				/usr/bin/logger -p daemon.warning "RETURNPVEINFO agent timeout de la vm $VMID provoqué par la requête 1 de DISK_INFO"
				sleep 2
				DISK_INFO=$(curl -s -k --connect-timeout $((TIMEOUT*2)) -X GET -H "$Authorization" "https://$PVE_IP:8006/api2/json/nodes/$NODE/qemu/$VMID/agent/get-fsinfo" 2>/dev/null | 
				jq -r '
					if .data != null and .data.result != null then
						.data.result[] | select(.["used-bytes"] != null and .["total-bytes"] != null) |
						"\(.mountpoint) \((.["used-bytes"] / 1024 / 1024 / 1024) | tonumber) GiB \((.["total-bytes"] / 1024 / 1024 / 1024) | tonumber) GiB"
					else
						"Busy"
					end
				')
			fi
			
			if [[ "$DISK_INFO" != *"QEMU guest agent is not running"* && "$DISK_INFO" != *"is not running"* && "$DISK_INFO" != *"No QEMU guest agent configured"* && ! -z "$DISK_INFO" ]]; then
				if [[ "$DISK_INFO" == "Busy" ]]; then
					ARRAY_DISKS+=("Busy")
					log_message "VMID $VMID is busy (agent not responding properly)"
				else
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

							used_disk_rounded=$(printf "%.2f" "$used_disk" 2>/dev/null)
							max_disk_rounded=$(printf "%.2f" "$max_disk" 2>/dev/null)
							ARRAY_DISKS+=("$disk_name : $used_disk_rounded / $max_disk_rounded $size_unit")
						fi
					done <<< "$DISK_INFO"
				fi
			else
				/usr/bin/logger -p daemon.warning "RETURNPVEINFO agent timeout de la vm $VMID provoqué par la requête 2 de DISK_INFO"	
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
					*) used_gib=0 ;;  # Handle unexpected units
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

	if [[ $QEMU_INFO == "null" ]]; then
		HA_State="null"
	else
		# Get HA state with better error handling
		HA_STATE_RESPONSE=$(curl -s -k --connect-timeout $TIMEOUT -H "$Authorization" "https://$PVE_IP:8006/api2/json/nodes/$NODE/qemu/$VMID/status/current" 2>/dev/null)
		if [ $? -ne 0 ] || [ -z "$HA_STATE_RESPONSE" ]; then
			/usr/bin/logger -p daemon.warning "RETURNPVEINFO agent timeout de la vm $VMID provoqué par la requête HA_STATE (status/current)"
			HA_State="null"
			log_message "Warning: Could not get HA state for VM $VMID on node $NODE"
		else
			HA_State=$(echo "$HA_STATE_RESPONSE" | jq '.data.ha.state' 2>/dev/null)
		fi
		sleep 0.25
		
		# Clean whitespace and adjust HA_State value
		HA_State=$(echo "$HA_State" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
		
		if [[ -z "$HA_State" || $HA_State == "null" ]]; then
			HA_State="Out_of_HA"
		elif [[ "$HA_State" == \"error\" ]]; then
			HA_State="Error"
		elif [[ "$HA_State" == \"started\" ]]; then
			HA_State="UP"
		fi
	fi

	# Build JSON with error handling for all JQ operations
	VM_JSON=$(echo "$VM_DATA" | jq \
		--arg cpu "$CPU_DATA" \
		--arg cpu_percent "$CPU_PERCENT" \
		--argjson disk "$(printf '%s\n' "${ARRAY_DISKS[@]}" | jq -R . | jq -s . 2>/dev/null)" \
		--arg biggerDiskPercentUsage "$(printf '%.2f' $BIGGER 2>/dev/null)" \
		--arg totalSnapshots "$(printf '%.1f' $TOTAL_SNAPSHOTS 2>/dev/null)" \
		--argjson cephSnapshots "$(printf '%s\n' "${ARRAY_SNAPSHOTS[@]}" | jq -R . | jq -s . 2>/dev/null)" \
		--arg clustername "$clustername" \
		--arg qemuInfo "$QEMU_INFO" \
		--arg cephInfo "$Ceph_Info" \
		--arg HA_State "$HA_State" \
		--arg cephPoolUsage "$CEPH_POOL_USAGE" \
		--arg oldestSnapshot "$max_days" \
		--arg node "$NODE" \
		--arg vmid "$VMID" \
		--arg vm_status "$VM_STATUS" \
		--arg last_update "$last_update" \
		'. + {vmid: $vmid, cpu: $cpu, cpu_percent: $cpu_percent, disk: $disk, biggerDiskPercentUsage: $biggerDiskPercentUsage, cephSnapshots: $cephSnapshots, CephTotalSnapshots: $totalSnapshots, clustername: $clustername, qemuInfo: $qemuInfo, cephInfo: $cephInfo, HA_State: $HA_State, cephPoolUsage: $cephPoolUsage, oldestSnapshot: $oldestSnapshot, node: $node, qmpstatus: $vm_status, last_update: $last_update}' 2>/dev/null)

	# Check if JSON creation failed
	if [ $? -ne 0 ] || [ -z "$VM_JSON" ]; then
		log_message "ERROR: Failed to create JSON for VM $VMID on node $NODE"
		if [ "$RETRY" -lt 2 ]; then
			((RETRIED_VMS++))
			sleep 2
			process_vm "$NODE" "$VMID" "$TOTAL_DATA" "$clustername" "$Ceph_Info" "$CEPH_TOTAL_INFO" "$CEPH_POOL_USAGE" $((RETRY+1)) &
			return 0
		else
			((FAILED_VMS++))
			# Create minimal fallback JSON with basic information
			VM_JSON="{\"vmid\":\"$VMID\",\"node\":\"$NODE\",\"qmpstatus\":\"$VM_STATUS\",\"last_update\":\"$last_update\",\"error\":\"JSON creation failed\"}"
		fi
	fi

	# Write VM data to file with error handling
	if ! echo "$VM_JSON" > "$TEMP_FILE"; then
		log_message "ERROR: Failed to write data to file for VM $VMID on node $NODE"
		((FAILED_VMS++))
		return 1
	fi
	
	((PROCESSED_VMS++))
	log_message "Successfully processed VM $VMID on node $NODE"
	return 0
}

PVESH=$(which pvesh)
if [ $? -eq 0 ]; then
	log_message "Starting data collection script"
	
	# Get cluster name
	clustername=$(grep 'cluster_name' /etc/pve/corosync.conf | awk '{print $2}')
	
	# Get resources data
	log_message "Requesting cluster resources data"
	#TOTAL_DATA=$(pvesh get cluster/resources --output-format json)
	TOTAL_DATA=$(curl -s -k --connect-timeout 5 -H "$Authorization" "https://$PVE_IP:8006/api2/json/cluster/resources" | jq -c '.data')
	
	if [ $? -ne 0 ] || [ -z "$TOTAL_DATA" ]; then
		/usr/bin/logger -p daemon.warning "RETURNPVEINFO timeout provoqué par la requête TOTAL_DATA (cluster/resources)"
		log_message "CRITICAL: Failed to get cluster resources data"
		exit 1
	fi
	
	VERSION=$(pveversion | awk -F/ '{print $2}' | sed 's/\..*//')

	# Get node list
	NODES=$(echo "$TOTAL_DATA" | jq -r '.[] | select(.type == "node") | .node' || hostname)
	if [ -z "$NODES" ]; then
		NODES=$(hostname)
		log_message "Using local hostname: $NODES"
	else
		log_message "Found cluster nodes: $NODES"
	fi

	# Check Ceph availability
	if command -v ceph >/dev/null 2>&1; then
		log_message "Ceph command found, gathering Ceph data"
		ceph_data=$(/usr/bin/ceph osd df -f json 2>/dev/null)
		if [ $? -ne 0 ] || [ -z "$ceph_data" ]; then
			/usr/bin/logger -p daemon.warning "RETURNPVEINFO timeout provoqué par la requête CEPH_DATA (osd df)"
			Ceph_Info="null"
			CEPH_TOTAL_INFO="null"
			CEPH_POOL_USAGE="null"
			log_message "Warning: Failed to get Ceph data"
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
				log_message "$Ceph_Info"
			else
				health=$(ceph health 2>/dev/null)
				if "$health" | grep -q "HEALTH_OK"; then
					Ceph_Info="Disks 'up'"
				else
					Ceph_Info="$health"
				fi
			fi
			CEPH_TOTAL_INFO=$(rbd du -p "$CephPoolName" 2>/dev/null || echo "null")
		fi
	else
		Ceph_Info="null"
		CEPH_TOTAL_INFO="null"
		CEPH_POOL_USAGE="null"
	fi



	# Process each node
	for NODE in $NODES; do
		log_message "Processing node: $NODE"
		NODE_VMS=$(echo "$TOTAL_DATA" | jq -r --arg node "$NODE" '.[] | select(.type == "qemu" and .node == $node) | .vmid')
		running=0
		
		# Count total VMs to process
		NODE_VM_COUNT=$(echo "$NODE_VMS" | wc -w)
		((TOTAL_VMS += NODE_VM_COUNT))
		log_message "Found $NODE_VM_COUNT VMs on node $NODE"
		
		# Convert VM list to array so we can modify it
		NODE_VMS_ARRAY=($NODE_VMS)
		i=0
		
		while [ $i -lt ${#NODE_VMS_ARRAY[@]} ]; do
			VMID=${NODE_VMS_ARRAY[$i]}
			
			# Check if a snapshot is in progress with better timeout and error handling
			SNAPSHOT_CHECK=$(curl -s -k --connect-timeout 3 -H "$Authorization" "https://$PVE_IP:8006/api2/json/nodes/$NODE/qemu/$VMID/config" 2>/dev/null)
			if [ $? -ne 0 ] || [ -z "$SNAPSHOT_CHECK" ]; then
				/usr/bin/logger -p daemon.warning "RETURNPVEINFO agent timeout de la vm $VMID provoqué par la requête SNAPSHOT_CHECK (config)"
			fi
			
			if [ "$SNAPSHOT_CHECK" -eq 0 ]; then
				log_message "Snapshot in progress for VM $VMID on node $NODE. Rescheduling."
				
				# If this is not the last VM in the array, move it to the end and continue
				if [ $i -lt $((${#NODE_VMS_ARRAY[@]} - 1)) ]; then
					NODE_VMS_ARRAY+=(${NODE_VMS_ARRAY[$i]})  # Add to the end
					unset NODE_VMS_ARRAY[$i]                 # Remove from current position
					NODE_VMS_ARRAY=("${NODE_VMS_ARRAY[@]}")  # Reindex the array
					continue                                 # Move to next element without incrementing i
				else
					# This is the last VM, wait and retry with increasing backoff
					for retry in 1 2 3; do
						log_message "Waiting $((retry * 5)) seconds before retrying the last VM with snapshot..."
						sleep $((retry * 5))
						
						# Check again if snapshot still in progress
						if ! curl -s -k --connect-timeout 3 -H "$Authorization" "https://$PVE_IP:8006/api2/json/nodes/$NODE/qemu/$VMID/config" 2>/dev/null | grep -q snap; then
							log_message "Snapshot finished for VM $VMID on node $NODE, processing now"
							break
						fi
						
						# If this was the last retry and snapshot still in progress
						if [ "$retry" -eq 3 ]; then
							log_message "WARNING: Snapshot still in progress after retries for VM $VMID on node $NODE. Processing anyway."
						fi
					done
				fi
			fi

			# Process VM normally
			process_vm "$NODE" "$VMID" "$TOTAL_DATA" "$clustername" "$Ceph_Info" "$CEPH_TOTAL_INFO" "$CEPH_POOL_USAGE" 0 &
			((running++))
			
			if ((running >= MAX_THREADS)); then
				wait -n
				((running--))
			fi
			
			# Move to next VM
			((i++))
		done
		wait  # Wait for all VMs on this node to complete
		log_message "Completed processing for node $NODE"
	done

	# Count how many VM JSON files were created
	VM_FILE_COUNT=$(ls /tmp/vm_data/*.json | wc -l)
	log_message "Found $VM_FILE_COUNT VM files in $TEMP_DIR (Expected: $TOTAL_VMS)"
	
	# If some VMs are missing, log a warning
	if [ "$VM_FILE_COUNT" -lt "$TOTAL_VMS" ]; then
		log_message "WARNING: Missing ${TOTAL_VMS - VM_FILE_COUNT} VM files!"
		
		# Get list of processed VMs
		PROCESSED_VM_LIST=$(ls "$TEMP_DIR" | grep -o '[0-9]\+' | sort -n)
		
		# Get list of all VMs that should be processed
		ALL_VM_LIST=$(echo "$TOTAL_DATA" | jq -r '.[] | select(.type == "qemu") | .vmid' | sort -n)
		
		# Find missing VMs using comm command
		MISSING_VMS=$(comm -23 <(echo "$ALL_VM_LIST") <(echo "$PROCESSED_VM_LIST"))
		
		if [ -n "$MISSING_VMS" ]; then
			log_message "Missing VMs: $MISSING_VMS"
			
			# Try to process missing VMs one more time with higher timeout
			for VMID in $MISSING_VMS; do
				NODE=$(echo "$TOTAL_DATA" | jq -r --arg vmid "$VMID" '.[] | select(.type == "qemu" and .vmid == $vmid) | .node')
				log_message "Final attempt to process missing VM $VMID on node $NODE"
				process_vm "$NODE" "$VMID" "$TOTAL_DATA" "$clustername" "$Ceph_Info" "$CEPH_TOTAL_INFO" "$CEPH_POOL_USAGE" 0
			done
		fi
	fi

	# Read all VM files into an array
	ALL_VMS=()
	for f in "$TEMP_DIR"/*.json; do
		if [ -f "$f" ]; then
			ALL_VMS+=("$(cat "$f")")
		fi
	done

	# Count final VM count
	FINAL_VM_COUNT=${#ALL_VMS[@]}
	log_message "Final VM count: $FINAL_VM_COUNT out of $TOTAL_VMS expected"
#	log_message "Processed: $PROCESSED_VMS, Failed: $FAILED_VMS, Retried: $RETRIED_VMS" # doesn't return the right values

	# Output all VMs data from all nodes with safer file writing
	echo "<<<proxmox-qemu>>>" > "/usr/lib/TMP_PVE_INFO.txt.new"
	echo "${ALL_VMS[@]}" | jq -s '.' >> "/usr/lib/TMP_PVE_INFO.txt.new"
	
	# Only move the file if the write was successful
	if [ $? -eq 0 ] && [ -s "/usr/lib/TMP_PVE_INFO.txt.new" ]; then
		mv "/usr/lib/TMP_PVE_INFO.txt.new" "/usr/lib/TMP_PVE_INFO.txt"
		cp /usr/lib/TMP_PVE_INFO.txt /usr/lib/PVE_INFO.txt
		cp /usr/lib/TMP_PVE_INFO.txt /home/odoo_fetcher/PVE_INFO.txt # pour pouvoir fetch sur odoo
	else
		log_message "ERROR: Failed to create output file"
		exit 1
	fi

	# Compress output with error handling
	if ! xz -9 -c /usr/lib/TMP_PVE_INFO.txt > "/usr/lib/ENC_PVE_INFO.txt.new"; then
		log_message "ERROR: Failed to compress output file"
		exit 1
	fi
	mv "/usr/lib/ENC_PVE_INFO.txt.new" "/usr/lib/ENC_PVE_INFO.txt"

	# Source file for chunking
	SOURCE_FILE="/usr/lib/ENC_PVE_INFO.txt"

	# Verify source file exists and has content
	if [ ! -f "$SOURCE_FILE" ] || [ ! -s "$SOURCE_FILE" ]; then
		log_message "ERROR: Source file $SOURCE_FILE is missing or empty"
		exit 1
	fi
	
	FILE_SIZE=$(stat -c%s "/usr/lib/TMP_PVE_INFO.txt")
	CHUNK_SIZE=$(( (FILE_SIZE + 4) / 5 ))

	# Divide, compress, encode each chunk in base64, and write with error handling
	for i in $(seq 1 5); do
		START_BYTE=$((CHUNK_SIZE * (i - 1)))
		# Fichier temporaire pour le chunk du JSON
		CHUNK_TXT="/tmp/chunk_$i.txt"
		
		# Create chunk with error handling
		if ! dd if="/usr/lib/TMP_PVE_INFO.txt" of="$CHUNK_TXT" bs=1 skip="$START_BYTE" count="$CHUNK_SIZE" status=none; then
			log_message "ERROR: Failed to create chunk $i"
			continue
		fi
		
		# Compress and encode chunk with error handling
		if ! xz -9 -c "$CHUNK_TXT" | base64 -w0 > "/usr/lib/PVE_INFO${i}.txt.new"; then
			log_message "ERROR: Failed to compress and encode chunk $i"
			continue
		fi
		
		# Move temporary file to final location only if successful
		mv "/usr/lib/PVE_INFO${i}.txt.new" "/usr/lib/PVE_INFO${i}.txt"
		log_message "Chunk $i successfully encoded in /usr/lib/PVE_INFO${i}.txt"
		
		# Clean up temporary file
		rm -f "$CHUNK_TXT"
	done

	# log_message "Script completed successfully. Processed $PROCESSED_VMS/$TOTAL_VMS VMs with $FAILED_VMS failures and $RETRIED_VMS retries."
fi
