<?php

/// START CUSTOM PART

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

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$device_id = $vars['device'];

// SQL query to retrieve required data from the devices table
$sql = "SELECT device_id, hostname, hardware, ceph_state, ceph_pool_usage FROM devices WHERE device_id = $device_id";
$result = $conn->query($sql);

// Check if the query returns any results
if ($result->num_rows > 0) {
    // Output the data in an HTML table
    echo "<style>
            table {
                width: 80%;
                border-collapse: collapse;
                margin: 25px 0;
                font-size: 18px;
                text-align: left;
                box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
            }
            th, td {
                padding: 12px 15px;
                border: 1px solid #ddd;
            }
            th {
                background-color: #f2f2f2;
                font-weight: bold;
            }
            tr:nth-child(even) {
                background-color: #f9f9f9;
            }
          </style>";

    echo "<table>";
    echo '<tr><th>Device ID</th><th>Hostname</th><th>Hardware</th><th>Ceph State</th><th>Ceph Pool Storage Usage</th></tr>';

    // Loop through each row in the result set
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo '<td>' . $row['device_id'] . '</td>';
        echo '<td>' . $row['hostname'] . '</td>';
        echo '<td>' . $row['hardware'] . '</td>';
        echo '<td>' . $row['ceph_state'] . '</td>';
        echo '<td>' . $row['ceph_pool_usage'] . '</td>';
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "No records found.";
}

// Close the database connection
$conn->close();

/// END OF CUSTOM PART

$graphs = [
    'ceph_poolstats' => 'Pool stats',
    'ceph_osdperf' => 'OSD Performance',
    'ceph_df' => 'Usage',

];

foreach ($graphs as $key => $text) {
    echo '<h3>' . $text . '</h3>';
    $graph_array['height'] = '100';
    $graph_array['width'] = '215';
    $graph_array['to'] = \LibreNMS\Config::get('time.now');
    $graph_array['id'] = $app['app_id'];

    if ($key == 'ceph_poolstats') {
        foreach (glob(Rrd::name($device['hostname'], ['app', 'ceph', $app['app_id'], 'pool'], '-*.rrd')) as $rrd_filename) {
            if (preg_match("/.*-pool-(.+)\.rrd$/", $rrd_filename, $pools)) {
                $graph_array['to'] = \LibreNMS\Config::get('time.now');
                $graph_array['id'] = $app['app_id'];
                $pool = $pools[1];
                echo '<h3>' . $pool . ' Reads/Writes</h3>';
                $graph_array['type'] = 'application_ceph_pool_io';
                $graph_array['pool'] = $pool;

                echo "<tr bgcolor='$row_colour'><td colspan=5>";
                include 'includes/html/print-graphrow.inc.php';
                echo '</td></tr>';

                $graph_array['to'] = \LibreNMS\Config::get('time.now');
                $graph_array['id'] = $app['app_id'];
                echo '<h3>' . $pool . ' IOPS</h3>';
                $graph_array['type'] = 'application_ceph_pool_iops';
                $graph_array['pool'] = $pool;

                echo "<tr bgcolor='$row_colour'><td colspan=5>";
                include 'includes/html/print-graphrow.inc.php';
                echo '</td></tr>';
            }
        }
    } elseif ($key == 'ceph_osdperf') {
        foreach (glob(Rrd::name($device['hostname'], ['app', 'ceph', $app['app_id'], 'osd'], '-*.rrd')) as $rrd_filename) {
            $graph_array['to'] = \LibreNMS\Config::get('time.now');
            $graph_array['id'] = $app['app_id'];
            if (preg_match("/.*-osd-(.+)\.rrd$/", $rrd_filename, $osds)) {
                $osd = $osds[1];
                echo '<h3>' . $osd . ' Latency</h3>';
                $graph_array['type'] = 'application_ceph_osd_performance';
                $graph_array['osd'] = $osd;

                echo "<tr bgcolor='$row_colour'><td colspan=5>";
                include 'includes/html/print-graphrow.inc.php';
                echo '</td></tr>';
            }
        }
    } elseif ($key == 'ceph_df') {
        foreach (glob(Rrd::name($device['hostname'], ['app', 'ceph', $app['app_id'], 'df'], '-*.rrd')) as $rrd_filename) {
            if (preg_match("/.*-df-(.+)\.rrd$/", $rrd_filename, $pools)) {
                $pool = $pools[1];
                if ($pool == 'c') {
                    echo '<h3>Cluster Usage</h3>';
                    $graph_array['to'] = \LibreNMS\Config::get('time.now');
                    $graph_array['id'] = $app['app_id'];
                    $graph_array['type'] = 'application_ceph_pool_df';
                    $graph_array['pool'] = $pool;

                    echo "<tr bgcolor='$row_colour'><td colspan=5>";
                    include 'includes/html/print-graphrow.inc.php';
                    echo '</td></tr>';
                } else {
                    echo '<h3>' . $pool . ' Usage</h3>';
                    $graph_array['to'] = \LibreNMS\Config::get('time.now');
                    $graph_array['id'] = $app['app_id'];
                    $graph_array['type'] = 'application_ceph_pool_df';
                    $graph_array['pool'] = $pool;

                    echo "<tr bgcolor='$row_colour'><td colspan=5>";
                    include 'includes/html/print-graphrow.inc.php';
                    echo '</td></tr>';

                    echo '<h3>' . $pool . ' Objects</h3>';
                    $graph_array['to'] = \LibreNMS\Config::get('time.now');
                    $graph_array['id'] = $app['app_id'];
                    $graph_array['type'] = 'application_ceph_pool_objects';
                    $graph_array['pool'] = $pool;

                    echo "<tr bgcolor='$row_colour'><td colspan=5>";
                    include 'includes/html/print-graphrow.inc.php';
                    echo '</td></tr>';
                }
            }
        }
    }
}
