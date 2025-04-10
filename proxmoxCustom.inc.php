<?php
// Load environment variables from LibreNMS .env file
$env = file_get_contents("/opt/librenms/.env");
$lines = explode("\n", $env);
$hostname = $device['hostname'];

// Parse and set environment variables
foreach ($lines as $line) {
    preg_match("/([^#]+)\=(.*)/", $line, $matches);
    if (isset($matches[2])) {
        putenv(trim($line));
    }
}

// Database connection parameters
$servername = getenv('DB_HOST');
$username = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');
$dbname = getenv('DB_USERNAME');

// Establish database connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Verify database connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

/**
 * Convert bytes to human-readable format
 * @param int $bytes Number of bytes
 * @return string Formatted size with unit
 */
if (!function_exists('formatBytes')) {
    function formatBytes($bytes) {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }
}

/**
 * Convert seconds to human-readable uptime format
 * @param int $seconds Total seconds
 * @return string Formatted uptime string
 */
if (!function_exists('formatUptime')) {
    function formatUptime($seconds) {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds / 60) % 60);
        $seconds = $seconds % 60;

        $uptimeString = '';
        if ($days > 0) {
            $uptimeString .= "$days days, ";
        }
        $uptimeString .= "$hours hours, $minutes minutes, $seconds seconds";

        return $uptimeString;
    }
}

/**
 * Delete a VM from the database using VM object
 * @param mysqli $conn Database connection
 * @param array $vm VM data array
 */
if (!function_exists('deleteVm')) {
    function deleteVm($conn, $vm) {
        $hostname = $vm['hostname'];
        $vmid = $vm['vmid'];
        $sqlDelete = "DELETE FROM proxmox WHERE vmid = $vmid AND hostname = '$hostname'";
        
        if ($conn->query($sqlDelete) === TRUE) {
            echo "VM with ID: $vmid deleted successfully.\n";
        } else {
            echo "Error when deleting VM: $vmid: " . $conn->error . "\n";
        }
    }
}

/**
 * Delete a VM from the database using VMID and hostname
 * @param mysqli $conn Database connection
 * @param int $vmid VM identifier
 * @param string $hostname Host name
 */
if (!function_exists('deleteVmFromVmid')) {
    function deleteVmFromVmid($conn, $vmid, $hostname) {
        $sqlDelete = "DELETE FROM proxmox WHERE vmid = $vmid AND hostname = '$hostname'";
        
        if ($conn->query($sqlDelete) === TRUE) {
            echo "VM with ID: $vmid deleted successfully.\n";
        } else {
            echo "Error when deleting VM: $vmid: " . $conn->error . "\n";
        }
    }
}

/**
 * Insert or update VM information in the database
 * @param mysqli $conn Database connection
 * @param array $vm VM data array
 */
if (!function_exists('upsertVm')) {
    function upsertVm($conn, $vm) {
        // Sanitize input values
        $hostname = $vm['hostname'];
        $device_id = (int) $vm['device_id'];
        $vmid = $vm['vmid'];
        $name = $conn->real_escape_string($vm['name']);
        $status = $conn->real_escape_string($vm['status']);
        $cpu = $vm['cpu'];
        $cpus = $vm['cpus'];
        $cpu_percent = round(((float) $vm['cpu'] / (float) $vm['cpus']) * 100, 3);
        $mem = $vm['mem'];
        $maxmem = $vm['maxmem'];
        $disk = $conn->real_escape_string(serialize($vm['disk']));
        $netin = $vm['netin'];
        $netout = $vm['netout'];
        $uptime = $vm['uptime'];
        $biggerDiskPercentUsage = floatval($vm['biggerDiskPercentUsage']);
        $cephSnapshots = $conn->real_escape_string(serialize($vm['cephSnapshots']));
        $CephTotalSnapshots = floatval($vm['CephTotalSnapshots']);
        $clustername = $vm['clustername'];
        $description = $name;
	$qemu_info = $vm['qemuInfo'];
        $oldest_snapshot = $vm['oldestSnapshot'];
        $node_name = $vm['node'];
        $last_update = $conn->real_escape_string($vm['last_update']);
	$HA_State = $conn->real_escape_string($vm['HA_State']);

        // Check if VM exists
        $sqlCheckExists = "SELECT COUNT(*) FROM proxmox WHERE vmid = $vmid AND device_id = $device_id";
        $result = $conn->query($sqlCheckExists);
        $exists = $result->fetch_row()[0];

        if ($exists > 0) {
            // Update existing VM
            $sqlUpdate = "
                UPDATE proxmox
                SET
                    hostname = '$hostname',
                    device_id = $device_id,
                    name = '$name',
                    status = '$status',
                    cpu = $cpu,
                    cpus = $cpus,
                    cpu_percent = $cpu_percent,
                    mem = $mem,
                    maxmem = $maxmem,
                    disk = CASE 
                        WHEN ('$disk' = 'a:1:{i:0;s:0:\"\";}') AND '$status' = 'stopped' THEN disk
                        ELSE \"$disk\"
                    END,
                    netin = $netin,
                    netout = $netout,
                    uptime = $uptime,
                    bigger_disk_percent_usage = $biggerDiskPercentUsage,
                    ceph_snapshots = \"$cephSnapshots\",
                    ceph_total_snapshots = $CephTotalSnapshots,
                    cluster = '$clustername',
                    node_name = '$node_name',
                    description = '$description',
                    qemu_info = '$qemu_info',
                    oldest_snapshot = $oldest_snapshot,
		    last_update = '$last_update',
		    HA_State = '$HA_State'
                WHERE vmid = $vmid AND device_id = $device_id
            ";

            if ($conn->query($sqlUpdate) === TRUE) {
                echo "VM $name (ID: $vmid) updated successfully.\n";
            } else {
                echo "Error when updating VM $name (ID: $vmid): " . $conn->error . "\n";
            }
        } else {
            // Insert new VM
            $sqlInsert = "
                INSERT INTO proxmox (
                    hostname, device_id, vmid, name, status, cpu, cpus, 
                    cpu_percent, mem, maxmem, disk, netin, netout, uptime, 
                    description, cluster, bigger_disk_percent_usage, 
                    ceph_snapshots, ceph_total_snapshots, qemu_info, 
                    node_name, last_update, HA_State
                )
                VALUES (
                    '$hostname', $device_id, $vmid, '$name', '$status', 
                    $cpu, $cpus, $cpu_percent, $mem, $maxmem, '$disk', 
                    $netin, $netout, $uptime, '$description', '$clustername', 
                    $biggerDiskPercentUsage, '$cephSnapshots', $CephTotalSnapshots, 
                    '$qemu_info', '$node_name', '$last_update', '$HA_State'
                )
            ";
            
            if ($conn->query($sqlInsert) === TRUE) {
                echo "VM $name (ID: $vmid) added successfully.\n";
            } else {
                echo "Error when adding VM $name (ID: $vmid): " . $conn->error . "\n";
            }
        }
    }
}

// Main execution flow
$currentHost = $device['hostname'];
$sqlGetDevice = "SELECT hostname FROM devices WHERE hostname = '$currentHost'";
$result = $conn->query($sqlGetDevice);

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $host = $row["hostname"];
    
    // SNMP configuration
    $timeout = 30;
    $oids = [
        ".1.3.6.1.4.1.8072.1.3.2.3.1.2.10.99.117.115.116.111.109.80.86.69.49",
        ".1.3.6.1.4.1.8072.1.3.2.3.1.2.10.99.117.115.116.111.109.80.86.69.50", 
        ".1.3.6.1.4.1.8072.1.3.2.3.1.2.10.99.117.115.116.111.109.80.86.69.51",
        ".1.3.6.1.4.1.8072.1.3.2.3.1.2.10.99.117.115.116.111.109.80.86.69.52",
        ".1.3.6.1.4.1.8072.1.3.2.3.1.2.10.99.117.115.116.111.109.80.86.69.53"
    ];
    $community = 'LibrenMSPublic';
    $compressed_chunks = [];

    // Récupérer chaque partie individuellement
    for ($i = 0; $i < count($oids); $i++) {
        $oid = $oids[$i];

        $snmpCommand = "snmpget -v2c -c $community $host $oid";
        $response = shell_exec($snmpCommand);

        if (!$response) {
            echo "Impossible de récupérer le résultat SNMP pour l'OID $oid sur l'hôte $host:$port\n";
        }
        else {
            if (preg_match('/STRING: (.+)/', $response, $matches)) {
                $compressed_chunks[] = trim($matches[1]);
                echo "$host:$port connecté avec succès via SNMP pour l'OID $oid\n";
            }
        }
        usleep(500000); // 500ms de pause entre chaque requête
    }

    // Traitement des chunks compressés
    $json_parts = [];

    foreach ($compressed_chunks as $index => $chunk) {
        $chunk_index = $index + 1;
        echo "Traitement du chunk $chunk_index\n";
        
        // Décodage base64
        $decoded = base64_decode($chunk);
        if ($decoded === false) {
            echo "Erreur de décodage base64 sur le chunk $chunk_index\n";
            continue;
        }
        
        // Décompression XZ directement en PHP si possible
        $temp_file = "/tmp/temp_chunk_{$chunk_index}.xz";
        file_put_contents($temp_file, $decoded);
        
        exec("xz -dc $temp_file 2>/tmp/xz_error_{$chunk_index}.log", $output, $return_code);
        
        if ($return_code !== 0) {
            echo "Erreur avec le chunk $chunk_index: " . file_get_contents("/tmp/xz_error_{$chunk_index}.log") . "\n";
        } else {
            // Ajouter la sortie décompressée à notre tableau
            $json_parts[] = implode("\n", $output);
        }
        
        // Nettoyage
        if (file_exists($temp_file)) {
            unlink($temp_file);
        }
        if (file_exists("/tmp/xz_error_{$chunk_index}.log")) {
            unlink("/tmp/xz_error_{$chunk_index}.log");
        }
        
        $output = [];
    }
            // Extract JSON data
    if (!empty($json_parts)) {
        $combined_json = implode("", $json_parts);
        if (preg_match('/(\[\s*{.*}\s*\])/s', $combined_json, $matches) ||
            preg_match('/({.*})/s', $combined_json, $matches)) {
            $json_content = $matches[1];
        }
        preg_match('/\[\s*{.*}\s*]/s', $combined_json, $matches);
        if (isset($matches[0])) {
            $jsonResponse = $matches[0];
            echo $jsonResponse;
            $vms = json_decode($jsonResponse, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                echo 'Error decoding JSON: ' . json_last_error_msg();
                exit;
            }
	   }

    } else {
        echo "No chunk successfully decompressed\n";
    }

    // Update Ceph information
    $deviceId = (int) $device['device_id'];
    $sqlCheckExists = "SELECT COUNT(*) FROM devices WHERE device_id = $deviceId";
    $result = $conn->query($sqlCheckExists);

    if ($result) {
        $exists = $result->fetch_row()[0];
        if ($exists > 0) {
            $cephInfo = $conn->real_escape_string($vms[0]['cephInfo']);
            if ($conn->query("UPDATE devices SET ceph_state = '$cephInfo' WHERE device_id = $deviceId") === TRUE) {
                echo "Ceph info updated successfully.\n";
            } else {
                echo "Error updating CephInfo on device: $deviceId: " . $conn->error . "\n";
            }
        } else {
            echo "$deviceId doesn't exist.\n";
        }
    } else {
        echo "Error when verifying existence: " . $conn->error . "\n";
    }

	// update Ceph storage state
	$sqlCheckExists = "SELECT COUNT(*) FROM devices WHERE device_id = $deviceId";
    $result = $conn->query($sqlCheckExists);

    if ($result) {
        $exists = $result->fetch_row()[0];
        if ($exists > 0) {
			$cephPoolUsage = $conn->real_escape_string($vms[0]['cephPoolUsage']);    
			if ($conn->query("UPDATE devices SET ceph_pool_usage = '$cephPoolUsage' WHERE device_id = $deviceId") === TRUE) {
                echo "Ceph storage updated successfully.\n";
            } else {
                echo "Error updating cephPoolUsage on device: $deviceId: " . $conn->error . "\n";
            }
        } else {
            echo "$deviceId doesn't exist.\n";
        }
    } else {
        echo "Error when verifying existence: " . $conn->error . "\n";
    }

    // Process VMs
    $DB_VM_list_query = "SELECT vmid FROM proxmox WHERE hostname = '$host'";
    $DB_VM_list_result = $conn->query($DB_VM_list_query);

    $existing_vm_ids = [];
    while ($row = $DB_VM_list_result->fetch_assoc()) {
        $existing_vm_ids[] = (int) $row['vmid'];
    }

    // Update VM information
    print_r($existing_vm_ids);
    $vmid_list = [];

    $sqlGetDeviceID = "SELECT device_id FROM devices WHERE hostname = '$host'";
    $result = $conn->query($sqlGetDeviceID);
    $row = $result->fetch_assoc();
    foreach ($vms as $vm) {
	if ($vm['name'] != null && $vm['cpus'] != null){
        	$int_vmid = (int) $vm['vmid'];
        	$vmid_list[] = $int_vmid;
        	$vm['device_id'] = $row['device_id'];
        	$vm['hostname'] = $host;
	        upsertVm($conn, $vm);
	}
    }

    // Remove non-existing VMs
    foreach ($existing_vm_ids as $db_vmid) {
        if (!in_array($db_vmid, $vmid_list, true)) {
            deleteVmFromVmid($conn, $db_vmid, $host);
        }
    }
} else {
    echo "Device with hostname '$currentHost' not found\n";
}

// Close the database connection
$conn->close();
?>
