<?php
$env = file_get_contents("/opt/librenms/.env");
$lines = explode("\n", $env);
$hostname = $device['hostname'];
foreach ($lines as $line) {
    preg_match("/([^#]+)\=(.*)/", $line, $matches);
    if (isset($matches[2])) {
        putenv(trim($line));
    }
}

$servername = getenv('DB_HOST');
$username = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');
$dbname = getenv('DB_USERNAME');

$conn = new mysqli($servername, $username, $password, $dbname);

// Verify db connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if (!function_exists('formatBytes')) {
    function formatBytes($bytes) {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' B';
        }
    }
}

if (!function_exists('formatUptime')) {
    function formatUptime($seconds) {
        $days = floor($seconds / 86400); // 86400 seconds in a day
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

if (!function_exists('deleteVm')) {
    function deleteVm($conn, $vm) {
	$hostname = $vm['hostname'];
	$vmid = $vm['vmid'];
        $sqlDelete = "DELETE FROM proxmox WHERE vmid = $vmid AND hostname = '$hostname'";
        if ($conn->query($sqlDelete) === TRUE) {
            echo "VM with ID: $vmid deleted successfully.\n";
        } else {
            echo "Error when delete VM : $vmid : " . $conn->error . "\n";
        }
    }
}

if (!function_exists('upsertVm')) {
    function upsertVm($conn, $vm) {
        // escape values to prevent SQL injections
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
//        $maxdisk = $vm['maxdisk'];
        $netin = $vm['netin'];
        $netout = $vm['netout'];
        $uptime = $vm['uptime'];
//        $cephDisks = $conn->real_escape_string(serialize($vm['cephDisks']));
//        $cephBiggerDiskPercentUsage = floatval($vm['cephBiggerDiskPercentUsage']);
        $biggerDiskPercentUsage = floatval($vm['biggerDiskPercentUsage']);
        $cephSnapshots = $conn->real_escape_string(serialize($vm['cephSnapshots']));
        $CephTotalSnapshots = floatval($vm['CephTotalSnapshots']);
        $clustername = $vm['clustername'];
        $description = $name;
        $qemu_info = $vm['qemuInfo'];
        $oldest_snapshot = $vm['oldestSnapshot'];

        // Vérifie si la VM existe
        $sqlCheckExists = "SELECT COUNT(*) FROM proxmox WHERE vmid = $vmid AND device_id = $device_id";
        $result = $conn->query($sqlCheckExists);
        $exists = $result->fetch_row()[0];

//                    ceph_disks = \"$cephDisks\",
//                    maxdisk = $maxdisk,



        if ($exists > 0) {
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
                    description = '$description',
                    qemu_info = '$qemu_info',
                    oldest_snapshot = $oldest_snapshot

                WHERE vmid = $vmid AND device_id = $device_id
            ";

            if ($conn->query($sqlUpdate) === TRUE) {
                echo "VM $name (ID: $cpu_percent) updated successfully.\n";
            } else {
                echo "Error when update VM $name (ID: $vmid): " . $conn->error . "\n";
            }
        } else {
            $sqlInsert = "
                INSERT INTO proxmox (hostname, device_id, vmid, name, status, cpu, cpus, cpu_percent, mem, maxmem, disk, netin, netout, uptime, description, cluster, bigger_disk_percent_usage, ceph_snapshots, ceph_total_snapshots, qemu_info)
                VALUES ('$hostname', $device_id, $vmid, '$name', '$status', $cpu, $cpus, $cpu_percent, $mem, $maxmem, $disk, $netin, $netout, $uptime, '$description', '$clustername', $biggerDiskPercentUsage, '$cephSnapshots', $CephTotalSnapshots, '$qemu_info')
            ";
            if ($conn->query($sqlInsert) === TRUE) {
                echo "VM $name (ID: $vmid) added successfully.\n";
            } else {
                echo "Error when add VM $name (ID: $vmid): " . $conn->error . "\n";
            }
        }
    }
}


$currentHost = $device['hostname'];

$sqlGetDevice = "SELECT hostname FROM devices WHERE hostname = '$currentHost'";
$result = $conn->query($sqlGetDevice);

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $host = $row["hostname"];
    $port = 36603;  // SNMP port
    $timeout = 30; // Timeout for connection

    // Custom OID
    $oid = ".1.3.6.1.4.1.8072.1.3.2.3.1.2.9.99.117.115.116.111.109.80.86.69";

    $snmpCommand = "snmpget -v2c -c LibrenMSPublic $host $oid";
    $response = shell_exec($snmpCommand);

    if (!$response) {
        echo "Impossible to get SNMP result for hostname : $host\n";
    } else {
        echo "$host connected successfully via SNMP\n";

        preg_match('/\[\s*{.*}\s*]/s', $response, $matches);
        if (isset($matches[0])) {
            $jsonResponse = $matches[0];
            echo $jsonResponse;

            // Remove backslashes
            $jsonResponse = stripslashes($jsonResponse);
            $vms = json_decode($jsonResponse, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                echo 'Erreur lors du décodage du JSON : ' . json_last_error_msg();
                exit;
            }

            $deviceId = (int) $device['device_id'];
            $sqlCheckExists = "SELECT COUNT(*) FROM devices WHERE device_id = $deviceId";
            $result = $conn->query($sqlCheckExists);

            if ($result) {
                $exists = $result->fetch_row()[0];
                if ($exists > 0) {
                    $cephInfo = $conn->real_escape_string($vms[0]['cephInfo']);
                    if ($conn->query("UPDATE devices SET ceph_state = '$cephInfo' WHERE device_id = $deviceId") === TRUE) {
                        echo "VM $name (ID: $vmid) updated successfully.\n";
                    } else {
                        echo "Error updating CephInfo on device: $deviceId : " . $conn->error . "\n";
                    }
                } else {
                    echo "$deviceId doesn't exist.\n";
                }
            } else {
                echo "Error when verifying existence: " . $conn->error . "\n";
            }

            foreach ($vms as $vm) {
		$sqlGetDeviceID = "SELECT device_id FROM devices WHERE hostname = '$host'";
		$result = $conn->query($sqlGetDeviceID);
		$row = $result->fetch_assoc();
		$vm['device_id'] = $row['device_id'];
                $vmid = $vm['vmid'];
                $vm['hostname'] = $hostname;
                upsertVm($conn, $vm);
            }

        } else {
            echo 'No data in SNMP response.';
        }
    }
} else {
    echo "Device with hostname '$currentHost' not found\n";
}

$conn->close();
?>
