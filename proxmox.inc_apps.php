<?php
require_once 'includes/html/application/proxmox.inc.php';
$graphs['proxmox'] = [
    'netif',
];

$pmxcl = dbFetchRows('SELECT DISTINCT(`app_instance`) FROM `applications` WHERE `app_type` = ?', ['proxmox']);
$instance = Request::get('instance', $pmxcl[0]['app_instance'] ?? null);

print_optionbar_start();

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
$pagetitle[] = $instance;

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
}

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
  if ($result->num_rows > 0) {
    echo('<table class="table table-condensed table-striped table-hover">');

    echo('
      <thead style="text-align:center; vertical-align:middle;">
      <tr style="text-align:center; vertical-align:middle;">
        <th style="text-align:center; vertical-align:middle;"><pre>State</th>
        <th style="text-align:center; vertical-align:middle;"><pre>VM ID</th>
        <th style="text-align:center; vertical-align:middle;"><pre>Name</th>
        <th style="text-align:center; vertical-align:middle;"><pre>CPU Usage</th>
              <th style="text-align:center; vertical-align:middle;"><pre>Memory Used</th>
              <th style="text-align:center; vertical-align:middle;"><pre>Disk Usage</th>
              <th style="text-align:center; vertical-align:middle;"><pre>Ceph Disks<br>Usage Rate</th>
              <th style="text-align:center; vertical-align:middle;"><pre>Ceph Bigger<br>Disk Usage</th>
              <th style="text-align:center; vertical-align:middle;"><pre>Ceph Snapshots</th>
              <th style="text-align:center; vertical-align:middle;"><pre>Ceph Total<br>Snapshots(GiB)</th>
              <th style="text-align:center; vertical-align:middle;"><pre>Oldest<br>snapshot(days)</th>
              <th style="text-align:center; vertical-align:middle;"><pre>Qemu Info</th>
              <th style="text-align:center; vertical-align:middle;"><pre>Network IN</th>
              <th style="text-align:center; vertical-align:middle;"><pre>Network OUT</th>
              <th style="text-align:center; vertical-align:middle;"><pre>Uptime</th>
      </tr>
      </thead>
      <tbody>
    ');
    
    // Boucle à travers les résultats et affichage des données
    while($vm = $result->fetch_assoc()) {
      // Déterminer la couleur du statut
      $statusClass = $vm['status'] === 'running' ? 'text-success' : 'text-danger';
      
      // Affichage des informations pour chaque VM
      echo('<tr>');
      echo('<td class="' . $statusClass . '" style="text-align:center; vertical-align:middle;"><strong>' . escape_html($vm['status']) . '<strong></td>');
      echo('<td style="text-align:center; vertical-align:middle;">' . escape_html($vm['vmid']) . '</td>');
      echo('<td style="text-align:center; vertical-align:middle;">' . escape_html($vm['name']) . '</td>');
      echo('<td style="text-align:center; vertical-align:middle;">' . escape_html($vm['cpu'] . ' / ' . $vm['cpus']) . '</td>');
      echo('<td style="text-align:center; vertical-align:middle;">' . escape_html(formatBytes($vm['mem']) . ' / ' . formatBytes($vm['maxmem'])) . '</td>');
      echo('<td style="text-align:center; vertical-align:middle;">' . escape_html(formatBytes($vm['disk']) . ' / ' . formatBytes($vm['maxdisk'])) . '</td>');
      echo('<td style="text-align:center; vertical-align:middle;">');
      $ceph_disks = unserialize($vm['ceph_disks']);
            if (is_array($ceph_disks)) {
                    foreach ($ceph_disks as $disk) {
          $clean_disk = preg_replace('/vm-\d+-/', '', $disk);
                      echo escape_html($clean_disk) . '<br>';
                }
            }
            echo('</td>');
      
            echo('<td style="text-align:center; vertical-align:middle;">' . escape_html($vm['ceph_bigger_disk_percent_usage']) . '%</td>');
      
            echo('<td style="text-align:center; vertical-align:middle;">');
            $ceph_snapshots = unserialize($vm['ceph_snapshots']);
            if (is_array($ceph_snapshots)) {
                foreach ($ceph_snapshots as $snapshot) {
            $clean_snap = preg_replace('/vm-\d+-state-/', '', $snapshot);
              echo escape_html($clean_snap) . '<br>';                     
                    }
                  }
            echo('</td>');
            echo('<td style="text-align:center; vertical-align:middle;">' . escape_html($vm['ceph_total_snapshots']) . '</td>');
            echo('<td style="text-align:center; vertical-align:middle;">' . escape_html($vm['oldest_snapshot']) . '</td>');
	    echo('<td style="text-align:center; vertical-align:middle;">' . escape_html($vm['qemu_info']) . '</td>');
            echo('<td style="text-align:center; vertical-align:middle;">' . escape_html(formatBytes($vm['netin'])) . '</td>');
            echo('<td style="text-align:center; vertical-align:middle;">' . escape_html(formatBytes($vm['netout'])) . '</td>');
            echo('<td style="text-align:center; vertical-align:middle;">' . escape_html(formatUptime($vm['uptime'])) . '</td>');
            echo('</tr>');
      echo('</tr>');
    }

		echo('</tbody></table>');
		echo generate_box_close();

	} else {
		echo "No virtual machines found.";
	}

	$conn->close();
// END OF FIRST CUSTOM PART
