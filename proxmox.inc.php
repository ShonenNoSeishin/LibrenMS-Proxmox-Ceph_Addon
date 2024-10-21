<?php

/*
 * Copyright (C) 2015 Mark Schouten <mark@tuxis.nl>
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; version 2 dated June,
 * 1991.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * See https://www.gnu.org/licenses/gpl.txt for the full license
 */
include "includes/html/application/proxmox.inc.php";
// START FIRST CUSTOM PART
$env = file_get_contents(__DIR__."/opt/librenms/.env");
$lines = explode("\n",$env);

//echo $device['ip'];
foreach($lines as $line){
  preg_match("/([^#]+)\=(.*)/",$line,$matches);
  if(isset($matches[2])){
    putenv(trim($line));
  }
}

// END OF FIRST CUSTOM PART

if (! \LibreNMS\Config::get('enable_proxmox')) {
    print_error('Proxmox agent was discovered on this host. Please enable Proxmox in your config.');
} else {
	// START OF SECOND CUSTOM PART
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
		$days = floor($seconds / 86400); // 86400 seconds per day
		$hours = floor(($seconds % 86400) / 3600);
		$minutes = floor(($seconds / 60) % 60);
		$seconds = $seconds % 60;

		$uptimeString = '';
		if ($days > 0) {
			$uptimeString .= "$days days, ";
		}
		$uptimeString .= "$hours heures, $minutes minutes, $seconds secondes";

		return $uptimeString;
	}
	function escape_html($string) {
	    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
	}

	$sqlGetVms = 'SELECT * FROM proxmox WHERE hostname = "'.$device['ip'].'";';
	$result = $conn->query($sqlGetVms);

	function generate_box_close() {
		return '</div></div>';
	}

	if ($result->num_rows > 0) {
		echo('<table class="table table-condensed table-striped table-hover">');

		// En-tête du tableau
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
			  <th style="text-align:center; vertical-align:middle;"><pre>Ceph oldest<br>snapshot(days)</th>
			  <th style="text-align:center; vertical-align:middle;"><pre>Qemu Info</th>
		          <th style="text-align:center; vertical-align:middle;"><pre>Network IN</th>
		          <th style="text-align:center; vertical-align:middle;"><pre>Network OUT</th>
		          <th style="text-align:center; vertical-align:middle;"><pre>Uptime</th>
			</tr>
		  </thead>
		  <tbody>
		');

		while($vm = $result->fetch_assoc()) {
			$statusClass = $vm['status'] === 'running' ? 'text-success' : 'text-danger';
			
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
	// END OF SECOND CUSTOM PART

    $graphs = [
        'proxmox_traffic' => 'Traffic',
    ];

    foreach (proxmox_node_vms($device["device_id"]) as $nvm) {

	$vm = proxmox_vm_info($nvm['vmid'], $nvm['cluster']);

        foreach ($vm['ports'] as $port) {
            foreach ($graphs as $key => $text) {
                $graph_type = 'proxmox_traffic';
                $graph_array['height'] = '100';
                $graph_array['width'] = '215';
                $graph_array['to'] = \LibreNMS\Config::get('time.now');
                $graph_array['id'] = $vm['app_id'];
                $graph_array['device_id'] = $vm['device_id'];
                $graph_array['type'] = 'application_' . $key;
                $graph_array['port'] = $port['port'];
                $graph_array['vmid'] = $vm['vmid'];
                $graph_array['cluster'] = $vm['cluster'];
                $graph_array['hostname'] = $vm['description'];

                echo '<h3>' . $text . ' ' . $port['port'] . '@' . $vm['description'] . '</h3>';

                echo '<tr><td colspan=5>';

                include 'includes/html/print-graphrow.inc.php';

                echo '</td></tr>';
            }
        }
    }
}
