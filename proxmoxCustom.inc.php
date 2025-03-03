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
                    last_update = '$last_update'
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
                    node_name, last_update
                )
                VALUES (
                    '$hostname', $device_id, $vmid, '$name', '$status', 
                    $cpu, $cpus, $cpu_percent, $mem, $maxmem, '$disk', 
                    $netin, $netout, $uptime, '$description', '$clustername', 
                    $biggerDiskPercentUsage, '$cephSnapshots', $CephTotalSnapshots, 
                    '$qemu_info', '$node_name', '$last_update'
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
    $oid = ".1.3.6.1.4.1.8072.1.3.2.3.1.2.9.99.117.115.116.111.109.80.86.69";
    $community = 'LibrenMSPublic';
    $snmpCommand = "snmpget -v2c -c $community $host $oid";
    
    $response = shell_exec($snmpCommand);

    if (!$response) {
        echo "Unable to get SNMP result for hostname: $host\n";
    } else {
        echo "$host connected successfully via SNMP\n";
        //echo "$response";

        // Process SNMP response
        $compressed_data = '';
        if (preg_match('/STRING:\s*"([^"]*)"/', $response, $matches)) {
        //    echo "matches[1]";
            $compressed_data = trim($matches[1]);
        //    echo "$compressed_data";
            
            // Decompress data
            $decoded = base64_decode($compressed_data);
            file_put_contents('/tmp/temp.xz', $decoded);
            $decompressed = shell_exec('xz -dc /tmp/temp.xz');
            unlink('/tmp/temp.xz');

            if (empty($decompressed)) {
                echo "Error during decompression\n";
                exit;
            }

            // Extract JSON data
            preg_match('/\[\s*{.*}\s*]/s', $decompressed, $matches);
            if (isset($matches[0])) {
                $jsonResponse = $matches[0];
                echo $jsonResponse;
                $vms = json_decode($jsonResponse, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    echo 'Error decoding JSON: ' . json_last_error_msg();
                    exit;
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
                    $int_vmid = (int) $vm['vmid'];
                    $vmid_list[] = $int_vmid;
                    
                    $vm['device_id'] = $row['device_id'];
                    $vm['hostname'] = $host;
                    upsertVm($conn, $vm);
                }

                // Remove non-existing VMs
                foreach ($existing_vm_ids as $db_vmid) {
                    if (!in_array($db_vmid, $vmid_list, true)) {
                        deleteVmFromVmid($conn, $db_vmid, $host);
                    }
                }
            }
        }
    }
} else {
    echo "Device with hostname '$currentHost' not found\n";
}

// Close the database connection
$conn->close();
?>
