<?php
require_once 'includes/html/application/proxmox.inc.php';
$graphs['proxmox'] = [
    'netif',
];

$pmxcl = dbFetchRows('SELECT DISTINCT(`app_instance`) FROM `applications` WHERE `app_type` = ?', ['proxmox']);
$instance = Request::get('instance', $pmxcl[0]['app_instance'] ?? null);

//print_optionbar_start();

echo "<span style='font-weight: bold;'>Proxmox Clusters</span> &#187; ";

$sep = '';

foreach ($pmxcl as $pmxc) {
    echo $sep;

    $selected = $pmxc['app_instance'] == $instance || (empty($instance) && empty($sep));
    if ($selected) {
        echo "<span class='pagemenu-selected'>";
    }

    echo generate_link(\LibreNMS\Util\StringHelpers::niceCase($pmxc->app_instance), ['page' => 'apps', 'app' => 'proxmox', 'instance' => $pmxc['app_instance']]);

    if ($selected) {
        echo '</span>';
    }

    $sep = ' | ';
}

print_optionbar_end();

$pagetitle[] = 'Proxmox';
//$pagetitle[] = $instance;

/*
if (isset($vars['vmid'])) {
    include 'includes/html/pages/apps/proxmox/vm.inc.php';
    $pagetitle[] = $vars['vmid'];
} else {
    echo '
<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="row">';
    foreach (proxmox_cluster_vms($instance) as $pmxvm) {
	if($pmxvm['status']=='running'){
	        echo '<div class="col-sm-4 col-md-3 col-lg-2">' . generate_link($pmxvm['vmid'] . ' (' . $pmxvm['description'] . ')', ['page' => 'apps', 'app' => 'proxmox', 'instance' => $instance, 'vmid' => $pmxvm['vmid']]) . '</div>';
	}
    }
    echo '
            </div>
        </div>
    </div>
</div>
';
}*/
include 'includes/html/pages/apps/proxmox/vm.inc.php';
// START OF FIRST CUSTOM PART
$env = file_get_contents(__DIR__."/opt/librenms/.env");
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

	$env = file_get_contents(__DIR__."/opt/librenms/.env");
	$lines = explode("\n",$env);

	foreach($lines as $line){
	  preg_match("/([^#]+)\=(.*)/",$line,$matches);
	  if(isset($matches[2])){
	    putenv(trim($line));
	  }
	}

	$conn = new mysqli($servername, $username, $password, $dbname);

	if ($conn->connect_error) {
		die("Connection failed: " . $conn->connect_error);
	}

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
	// prevent XSS attacks
	function escape_html($string) {
	    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
	}

	$sqlGetVms = 'SELECT * FROM proxmox;';
	$result = $conn->query($sqlGetVms);

	function generate_box_close() {
		return '</div></div>';  
	}

//        <label><input type="checkbox" name="columns[]" value="ceph_disks" checked' . (in_array('ceph_disks', $_POST['columns'] ?? []) ? 'checked' : '') . '> Ceph Disks Usage Rate</label>
//        <label><input type="checkbox" name="columns[]" value="ceph_bigger_disk" checked' . (in_array('ceph_bigger_disk', $_POST['columns'] ?? []) ? 'checked' : '') . '> Ceph Bigger Disk Usage</label>


    echo '
    <form method="POST" id="column-selector">
        <input type="hidden" name="_token" value="' . csrf_token() . '">
        <label><input type="checkbox" name="columns[]" value="state" checked' . (in_array('state', $_POST['columns'] ?? []) ? 'checked' : '') . '> State</label>
        <label><input type="checkbox" name="columns[]" value="vmid" checked' . (in_array('vmid', $_POST['columns'] ?? []) ? 'checked' : '') . '> VM ID</label>
        <label><input type="checkbox" name="columns[]" value="name" checked' . (in_array('name', $_POST['columns'] ?? []) ? 'checked' : '') . '> Name</label>
        <label><input type="checkbox" name="columns[]" value="cpu_usage" checked' . (in_array('cpu_usage', $_POST['columns'] ?? []) ? 'checked' : '') . '> CPU Usage</label>
        <label><input type="checkbox" name="columns[]" value="cpu_percent" checked' . (in_array('cpu_percent', $_POST['columns'] ?? []) ? 'checked' : '') . '> CPU Percent Usage</label>
        <label><input type="checkbox" name="columns[]" value="mem_usage" checked' . (in_array('mem_usage', $_POST['columns'] ?? []) ? 'checked' : '') . '> Memory Used</label>
        <label><input type="checkbox" name="columns[]" value="disk_usage" checked' . (in_array('disk_usage', $_POST['columns'] ?? []) ? 'checked' : '') . '> Disks Usage</label>
        <label><input type="checkbox" name="columns[]" value="bigger_disk" checked' . (in_array('bigger_disk', $_POST['columns'] ?? []) ? 'checked' : '') . '> Bigger Disk Usage</label>
        <label><input type="checkbox" name="columns[]" value="ceph_snapshots" checked' . (in_array('ceph_snapshots', $_POST['columns'] ?? []) ? 'checked' : '') . '> Ceph Snapshots</label>
        <label><input type="checkbox" name="columns[]" value="total_snapshots" checked' . (in_array('total_snapshots', $_POST['columns'] ?? []) ? 'checked' : '') . '> Ceph Total Snapshots</label>
        <label><input type="checkbox" name="columns[]" value="oldest_snapshot" checked' . (in_array('oldest_snapshot', $_POST['columns'] ?? []) ? 'checked' : '') . '> Oldest Snapshot</label>
        <label><input type="checkbox" name="columns[]" value="qemu_info" checked' . (in_array('qemu_info', $_POST['columns'] ?? []) ? 'checked' : '') . '> Qemu Info</label>
        <label><input type="checkbox" name="columns[]" value="network_in" checked' . (in_array('network_in', $_POST['columns'] ?? []) ? 'checked' : '') . '> Network IN</label>
        <label><input type="checkbox" name="columns[]" value="network_out" checked' . (in_array('network_out', $_POST['columns'] ?? []) ? 'checked' : '') . '> Network OUT</label>
        <label><input type="checkbox" name="columns[]" value="uptime" checked' . (in_array('uptime', $_POST['columns'] ?? []) ? 'checked' : '') . '> Uptime</label>
    <button type="submit" style="background-color: #007bff; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer;">
        Apply
    </button>
    </form>
    ';


    $available_columns = [
        'state' => 'State',
        'vmid' => 'VM ID',
        'name' => 'Name',
        'cpu_usage' => 'CPU Usage',
        'cpu_percent' => 'CPU Percent Usage',
        'mem_usage' => 'Memory Used',
        'disk_usage' => 'Disk Usage',
//        'ceph_disks' => 'Ceph Disks<br>Usage Rate',
//        'ceph_bigger_disk' => 'Ceph Bigger<br>Disk Usage',
        'bigger_disk' => 'Bigger<br>Disk Usage',
        'ceph_snapshots' => 'Ceph Snapshots',
        'total_snapshots' => 'Ceph Total<br>Snapshots(GiB)',
        'oldest_snapshot' => 'Oldest<br>snapshot(days)',
        'qemu_info' => 'Qemu Info',
        'network_in' => 'Network IN',
        'network_out' => 'Network OUT',
        'uptime' => 'Uptime'
    ];

    $selected_columns = $_POST['columns'] ?? array_keys($available_columns);

    if ($result->num_rows > 0) {
        echo '<table class="table table-condensed table-striped table-hover">';

        echo '<thead style="text-align:center; vertical-align:middle;"><tr style="text-align:center; vertical-align:middle;">';
        foreach ($selected_columns as $col) {
            if (isset($available_columns[$col])) {
                echo '<th style="text-align:center; vertical-align:middle;"><pre>' . $available_columns[$col] . '</pre></th>';
            }
        }
        echo '</tr></thead><tbody>';

        while ($vm = $result->fetch_assoc()) {
            echo '<tr>';
            foreach ($selected_columns as $col) {
                echo '<td style="text-align:center; vertical-align:middle;">';
                switch ($col) {
                    case 'state':
                        $statusClass = $vm['status'] === 'running' ? 'text-success' : 'text-danger';
                        echo '<strong class="' . $statusClass . '">' . escape_html($vm['status']) . '</strong>';
                        break;
                    case 'vmid':
                        echo escape_html($vm['vmid']);
                        break;
                    case 'name':
                        echo escape_html($vm['name']);
                        break;
                    case 'cpu_usage':
                        echo escape_html($vm['cpu']) . ' / ' . escape_html($vm['cpus']);
                        break;
                    case 'cpu_percent':
                        echo escape_html($vm['cpu_percent']) . ' %';
                        break;
                    case 'mem_usage':
                        echo escape_html(formatBytes($vm['mem'])) . ' / ' . escape_html(formatBytes($vm['maxmem']));
                        break;
                    case 'disk_usage':
                        $disks = unserialize($vm['disk']);
                        if (is_array($disks)) {
                            foreach ($disks as $disk) {
                                echo escape_html(preg_replace('/vm-\d+-/', '', $disk)) . '<br>';
                            }
                        }
                        break;
                    case 'bigger_disk':
                        echo escape_html($vm['bigger_disk_percent_usage']) . '%';
                        break;
                    case 'ceph_snapshots':
                        $ceph_snapshots = unserialize($vm['ceph_snapshots']);
                        if (is_array($ceph_snapshots)) {
                            foreach ($ceph_snapshots as $snapshot) {
                                echo escape_html(preg_replace('/vm-\d+-state-/', '', $snapshot)) . '<br>';
                            }
                        }
                        break;
                    case 'total_snapshots':
                        echo escape_html($vm['ceph_total_snapshots']);
                        break;
                    case 'oldest_snapshot':
                        echo escape_html($vm['oldest_snapshot']);
                        break;
                    case 'qemu_info':
                        echo escape_html($vm['qemu_info']);
                        break;
                    case 'network_in':
                        echo escape_html(formatBytes($vm['netin']));
                        break;
                    case 'network_out':
                        echo escape_html(formatBytes($vm['netout']));
                        break;
                    case 'uptime':
                        echo escape_html(formatUptime($vm['uptime']));
                        break;
                }
                echo '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    } else {
        echo 'No virtual machines found.';
    }

	$conn->close();
// END OF FIRST CUSTOM PART
