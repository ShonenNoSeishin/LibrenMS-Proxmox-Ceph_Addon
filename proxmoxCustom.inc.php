<?php

$env = file_get_contents("/opt/librenms/.env");
$lines = explode("\n",$env);

foreach($lines as $line){
  preg_match("/([^#]+)\=(.*)/",$line,$matches);
  if(isset($matches[2])){
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

// (hostnames = devices ip)
$sqlGetDevices = "SELECT hostname FROM devices";

// exec request
$result = $conn->query($sqlGetDevices);

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

function deleteVm($conn, $vmid) {
    $sqlDelete = "DELETE FROM proxmox WHERE vmid = $vmid";
    if ($conn->query($sqlDelete) === TRUE) {
        echo "VM with ID: $vmid deleted successfully.\n";
    } else {
        echo "Error when delete VM : $vmid : " . $conn->error . "\n";
    }
}

function upsertVm($conn, $vm) {
    // escape values to prevent sql injections
    $hostname = $conn->real_escape_string($vm['hostname']);
    $vmid = $vm['vmid'];
    $name = $conn->real_escape_string($vm['name']);
    $status = $conn->real_escape_string($vm['status']);
    $cpu = $vm['cpu'];
    $cpus = $vm['cpus'];
    $mem = $vm['mem'];
    $maxmem = $vm['maxmem'];
    $disk = $vm['disk'];
    $maxdisk = $vm['maxdisk'];
    $netin = $vm['netin'];
    $netout = $vm['netout'];
    $uptime = $vm['uptime'];
    $cephDisks = $conn->real_escape_string(serialize($vm['cephDisks']));
    $cephBiggerDiskPercentUsage = floatval($vm['cephBiggerDiskPercentUsage']);
    $cephSnapshots = $conn->real_escape_string(serialize($vm['cephSnapshots']));
    $CephTotalSnapshots = floatval($vm['CephTotalSnapshots']);
    $clustername = $vm['clustername'];
    $description = $name;
    $qemu_info =$vm['qemuInfo'];
    $oldest_snapshot = $vm['oldestSnapshot'];

    // Verify if exist
    $sqlCheckExists = "SELECT COUNT(*) FROM proxmox WHERE vmid = $vmid";
    $result = $conn->query($sqlCheckExists);
    $exists = $result->fetch_row()[0];

    if ($exists > 0) {
        $sqlUpdate = "
            UPDATE proxmox
            SET
                hostname = '$hostname',
                name = '$name',
                status = '$status',
                cpu = $cpu,
                cpus = $cpus,
                mem = $mem,
                maxmem = $maxmem,
                disk = $disk,
                maxdisk = $maxdisk,
                netin = $netin,
                netout = $netout,
                uptime = $uptime,
                ceph_disks = \"$cephDisks\",
                ceph_bigger_disk_percent_usage = $cephBiggerDiskPercentUsage,
                ceph_snapshots = \"$cephSnapshots\",
                ceph_total_snapshots = $CephTotalSnapshots,
                cluster = '$clustername',
		        description = '$description',
		        qemu_info = '$qemu_info',
                oldest_snapshot = $oldest_snapshot

            WHERE vmid = $vmid
        ";

        if ($conn->query($sqlUpdate) === TRUE) {
            echo "VM $name (ID: $vmid) updated successfully.\n";
        } else {
            echo "Error when update VM $name (ID: $vmid): " . $conn->error . "\n";
        }
    } else {
        $sqlInsert = "
            INSERT INTO proxmox (hostname, vmid, name, status, cpu, cpus, mem, maxmem, disk, maxdisk, netin, netout, uptime, description, cluster, ceph_disks, ceph_bigger_disk_percent_usage, ceph_snapshots, ceph_total_snapshots, qemu_info)
            VALUES ('$hostname', $vmid, '$name', '$status', $cpu, $cpus, $mem, $maxmem, $disk, $maxdisk, $netin, $netout, $uptime, '$description', '$clustername', '$cephDisks', $cephBiggerDiskPercentUsage, '$cephSnapshots', $CephTotalSnapshots, '$qemu_info')
        ";
        if ($conn->query($sqlInsert) === TRUE) {
            echo "VM $name (ID: $vmid) added successfully.\n";
        } else {
            echo "Error when add VM $name (ID: $vmid): " . $conn->error . "\n";
        }
    }
}

if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $host = $row["hostname"];
        $port = 36603;  // SNMP port
        $timeout = 30; // Timeout for connection

        // Custom OID
        $oid = ".1.3.6.1.4.1.8072.1.3.2.3.1.2.9.99.117.115.116.111.109.80.86.69";

        $snmpCommand = "snmpget -v2c -c LibrenMSPublic $host $oid";
        $response = shell_exec($snmpCommand);

        if (!$response) {
            echo "Impossible to get snmp result for ip : $host\n";
        } else {
            echo "$host connected successfully via SNMP\n";

            preg_match('/\[\s*{.*}\s*]/s', $response, $matches);
            if (isset($matches[0])) {
                $jsonResponse = $matches[0];

                echo $jsonResponse;
                // Delete backslashes
                $jsonResponse = stripslashes($jsonResponse);
                $vms = json_decode($jsonResponse, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    echo 'Erreur lors du décodage du JSON : ' . json_last_error_msg();
                    exit;
                }

$deviceId = (int) $device['device_id'];  // Cast to integer for safety
$sqlCheckExists = "SELECT COUNT(*) FROM devices WHERE device_id = $deviceId";
$result = $conn->query($sqlCheckExists);

if ($result) {
    $exists = $result->fetch_row()[0];
    
    if ($exists > 0) {
        $cephInfo = $conn->real_escape_string($vms[0]['cephInfo']);
        if ($conn->query("UPDATE devices SET ceph_state = '$cephInfo' WHERE device_id = $deviceId") === TRUE) {
            echo "VM $name (ID: $deviceId) updated successfully.\n";
        } else {
            echo "Error when update CephInfo on device : $deviceId): " . $conn->error . "\n";
        }
    } else {
        echo "$deviceId doesn't exist.\n";
    }
} else {
    echo "Error when verify existance : " . $conn->error . "\n";
}

                foreach ($vms as $vm) {
                    $vmid = $vm['vmid'];
//                    deleteVm($conn, $vmid);
                    $vm['hostname'] = $host;
                    upsertVm($conn, $vm);
                }

            } else {
                echo 'No data in SNMP response.';
            }
        }
    }
} else {
    echo "No devices found\n";
}

$conn->close();
?>
