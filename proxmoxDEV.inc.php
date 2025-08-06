<?php
/**
 * LibreNMS Proxmox Web Interface
 * Displays Proxmox cluster and VM information in a web interface
 */

// Gérer les requêtes AJAX pour la mise à jour de No_Qemu_GA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_qemu_ga'])) {
    // Vérifier le token CSRF
    if (!isset($_POST['_token']) || $_POST['_token'] !== csrf_token()) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }

    // Récupérer les paramètres
    $vmid = isset($_POST['vmid']) ? intval($_POST['vmid']) : 0;
    $status = isset($_POST['status']) && $_POST['status'] === 'Yes' ? 'Yes' : 'No';

    // Vérifier que le VMID est valide
    if ($vmid <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid VM ID']);
        exit;
    }

    // Configuration de la base de données
    $env = file_get_contents(__DIR__."/opt/librenms/.env");
    $lines = explode("\n", $env);

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

    // Connexion à la base de données
    $conn = new mysqli($servername, $username, $password, $dbname);

    // Vérifier la connexion
    if ($conn->connect_error) {
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit;
    }

    // Préparer et exécuter la requête
    $stmt = $conn->prepare("UPDATE proxmox SET `No_Qemu_GA` = ? WHERE vmid = ?");
    $stmt->bind_param('si', $status, $vmid);

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database update failed']);
    }

    $stmt->close();
    $conn->close();
    exit;
}

require_once 'includes/html/application/proxmox.inc.php';

// Define available graphs
$graphs['proxmox'] = [
    'netif',
];

/**
 * Helper Functions
 */

/**
 * Convert bytes to human-readable format
 * @param int $bytes Number of bytes
 * @return string Formatted size with unit
 */
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

/**
 * Convert seconds to human-readable uptime format
 * @param int $seconds Total seconds
 * @return string Formatted uptime string
 */
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

/**
 * Escape HTML special characters
 * @param string $string Input string
 * @return string Escaped string
 */
function escape_html($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Get Proxmox clusters from database
$pmxcl = dbFetchRows('SELECT DISTINCT(app_instance) FROM applications WHERE app_type = ?', ['proxmox']);
$instance = Request::get('instance', $pmxcl[0]['app_instance'] ?? null);

// Navigation bar for clusters
print_optionbar_start();
echo "<span style='font-weight: bold;'>Proxmox Clusters</span> &#187; ";

$sep = '';
foreach ($pmxcl as $pmxc) {
    echo $sep;
    $selected = $pmxc['app_instance'] == $instance || (empty($instance) && empty($sep));
    if ($selected) {
        echo "<span class='pagemenu-selected'>";
    }
    echo generate_link(
        \LibreNMS\Util\StringHelpers::niceCase($pmxc->app_instance),
        [
            'page' => 'apps',
            'app' => 'proxmox',
            'instance' => $pmxc['app_instance']
        ]
    );
    if ($selected) {
        echo '</span>';
    }
    $sep = ' | ';
}

print_optionbar_end();

// Set page title
$pagetitle[] = 'Proxmox';
$pagetitle[] = $instance;

// CSV Download button
echo '
<form method="POST" action="" style="margin-bottom: 20px;">
    <input type="hidden" name="_token" value="' . csrf_token() . '">
    <button type="submit" name="download_csv" class="btn btn-primary">
        Download CSV
    </button>
</form>';

// Database connection setup
$env = file_get_contents(__DIR__."/opt/librenms/.env");
$lines = explode("\n", $env);

foreach ($lines as $line) {
    preg_match("/([^#]+)\=(.*)/", $line, $matches);
    if (isset($matches[2])) {
        putenv(trim($line));
    }
}

// Fetch filter parameters
$filter_state = Request::get('state');
$filter_vmid = Request::get('vmid');
$filter_cpu_percent = Request::get('cpu_percent');
$filter_disk_empty = Request::get('disk_empty');
$filter_bigger_disk = Request::get('bigger_disk');
$filter_oldest_snapshot = Request::get('oldest_snapshot');
$filter_qemu_info = Request::get('qemu_info');
$filter_HA_State = Request::get('HA_State');
$filter_hide_no_qemu_ga = Request::get('hide_no_qemu_ga');

$servername = getenv('DB_HOST');
$username = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');
$dbname = getenv('DB_USERNAME');

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Define available columns for display
$available_columns = [
    'state' => 'State',
    'vmid' => 'VM ID',
    'name' => 'Name',
    'node_name' => 'Node name',
    'cpu_usage' => 'CPU Usage',
    'cpu_percent' => 'CPU Percent Usage',
    'mem_usage' => 'Memory Used',
    'disk_usage' => 'Disk Usage',
    'bigger_disk' => 'Bigger<br>Disk Usage',
    'ceph_snapshots' => 'Ceph Snapshots',
    'total_snapshots' => 'Ceph Total<br>Snapshots(GiB)',
    'oldest_snapshot' => 'Oldest<br>snapshot(days)',
    'qemu_info' => 'Qemu Info',
    'network_in' => 'Network IN',
    'network_out' => 'Network OUT',
    'uptime' => 'Uptime',
    'HA_State' => 'HA State',
    'No_Qemu_GA' => 'No Qemu-GA',
];

// Conditional include for VM details
if (isset($vars['vmid'])) {
    include 'includes/html/pages/apps/proxmox/vm.inc.php';
    $pagetitle[] = $vars['vmid'];
} else {
    // Display VM grid for running VMs
    echo '<div class="container-fluid"><div class="row"><div class="col-md-12"><div class="row">';
    foreach (proxmox_cluster_vms($instance) as $pmxvm) {
        if ($pmxvm['status'] == 'running') {
            echo '<div class="col-sm-4 col-md-3 col-lg-2">' . 
                generate_link(
                    $pmxvm['vmid'] . ' (' . $pmxvm['description'] . ')',
                    [
                        'page' => 'apps',
                        'app' => 'proxmox',
                        'instance' => $instance,
                        'vmid' => $pmxvm['vmid']
                    ]
                ) . 
                '</div>';
        }
    }
    echo '</div></div></div></div>';
}

// Fetch and group VMs by node
$sqlGetVms = 'SELECT * FROM proxmox WHERE 1=1';
if (!empty($filter_state)) {
    $sqlGetVms .= " AND status = '" . $conn->real_escape_string($filter_state) . "'";
}
if (!empty($filter_vmid)) {
    $sqlGetVms .= " AND vmid = '" . $conn->real_escape_string($filter_vmid) . "'";
}
if (!empty($filter_cpu_percent)) {
    $sqlGetVms .= " AND cpu_percent >= " . intval($filter_cpu_percent);
}
if ($filter_disk_empty === 'yes') {
    $sqlGetVms .= " AND disk = 'a:1:{i:0;s:0:\"\";}'"; // disk empty
}
if (!empty($filter_bigger_disk)) {
    $sqlGetVms .= " AND bigger_disk_percent_usage >= " . intval($filter_bigger_disk);
}
if (!empty($filter_oldest_snapshot)) {
    $sqlGetVms .= " AND oldest_snapshot >= " . intval($filter_oldest_snapshot);
}
if ($filter_qemu_info === 'null') {
    $sqlGetVms .= " AND qemu_info = 'null'";
}
if ($filter_HA_State === 'null') {
    $sqlGetVms .= " AND HA_State != 'UP'";
}
if ($filter_hide_no_qemu_ga === 'yes') {
    $sqlGetVms .= " AND (`No_Qemu_GA` IS NULL OR `No_Qemu_GA` != 'Yes')";
}

$result = $conn->query($sqlGetVms);

$grouped_vms = [];
if ($result->num_rows > 0) {
    while ($vm = $result->fetch_assoc()) {
        $node_name = $vm['node_name'];
        if (!isset($grouped_vms[$node_name])) {
            $grouped_vms[$node_name] = [];
        }
        $grouped_vms[$node_name][] = $vm;
    }
}

// Include CSS styles
?>
<style>
    .filters-form,
    .column-selector {
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
        justify-content: flex-start;
        margin-bottom: 20px;
    }

    .filters-form label,
    .column-selector label {
        font-size: 1rem;
        margin-right: 10px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .filters-form input[type="text"],
    .filters-form input[type="number"],
    .filters-form select {
        padding: 8px;
        font-size: 1rem;
        border-radius: 5px;
        border: 1px solid #ccc;
        width: 200px;
    }

    .filters-form input[type="checkbox"] {
        width: auto;
        margin: 0 8px;
    }

    .filters-form button,
    .column-selector button {
        background-color: #007bff;
        color: white;
        border: none;
        padding: 8px 16px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 1rem;
        transition: background-color 0.3s ease;
    }

    .filters-form button:hover,
    .column-selector button:hover {
        background-color: #0056b3;
    }

    .column-selector {
        margin-top: 20px;
    }

    .column-selector input {
        margin-right: 10px;
    }

    .column-selector label {
        display: inline-block;
        margin-bottom: 10px;
    }
    .container-fluid {
        width: 95%;
        margin: auto;
        padding: 20px;
    }
    
    .node-group {
        margin-bottom: 15px;
        width: 100%;
        display: block;
    }
    
    .toggle-button {
        width: 100%;
        text-align: left;
        padding: 12px 20px;
        background-color: #007bff;
        color: white;
        border: none;
        cursor: pointer;
        border-radius: 5px 5px 0 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 1.1em;
        transition: background-color 0.3s ease;
    }
    
    .toggle-button:hover {
        background-color: #0056b3;
    }
    
    .toggle-button .node-info {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .toggle-button .vm-count {
        background: rgba(255,255,255,0.2);
        padding: 3px 8px;
        border-radius: 12px;
        font-size: 0.9em;
    }
    
    .node-content {
        max-height: none;
        opacity: 1;
        overflow: hidden;
        transition: none;
    }

    .node-content.active {
        max-height: none;
        opacity: 1;
        transition: max-height 0.5s ease-in, opacity 0.3s ease-in;
    }

    .node-content.inactive {
        max-height: 0;
        opacity: 0;
        transition: max-height 0.3s ease-out, opacity 0.2s ease-out;
    }
    
    /* New table wrapper styles */
    .table-wrapper {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        margin-bottom: 1rem;
        box-shadow: inset 0 0 5px rgba(0,0,0,0.1);
        border-radius: 4px;
    }
    
    .table {
        margin-bottom: 0;
        width: 100%;
        white-space: nowrap;
    }
    
    /* Fixed column widths */
    .table th {
        background-color: #f8f9fa;
        position: sticky;
        top: 0;
        z-index: 1;
        min-width: 120px;  /* Base minimum width for all columns */
    }
    
    /* Specific column widths */
    .table th.col-state { min-width: 100px; }
    .table th.col-vmid { min-width: 80px; }
    .table th.col-name { min-width: 150px; }
    .table th.col-node_name { min-width: 150px; }
    .table th.col-cpu_usage { min-width: 120px; }
    .table th.col-cpu_percent { min-width: 120px; }
    .table th.col-mem_usage { min-width: 200px; }
    .table th.col-disk_usage { min-width: 250px; }
    .table th.col-bigger_disk { min-width: 120px; }
    .table th.col-ceph_snapshots { min-width: 200px; }
    .table th.col-total_snapshots { min-width: 150px; }
    .table th.col-oldest_snapshot { min-width: 150px; }
    .table th.col-qemu_info { min-width: 150px; }
    .table th.col-network_in { min-width: 120px; }
    .table th.col-network_out { min-width: 120px; }
    .table th.col-uptime { min-width: 200px; }
    .table th.col-No_Qemu_GA { min-width: 120px; }
    
    .table td, .table th {
        padding: 12px;
        vertical-align: middle;
    }
    
    /* Maintain text wrapping for disk usage column */
    .table td.disk-usage {
        white-space: normal;
        min-width: 250px;
    }
    
    .text-success {
        color: #28a745;
        font-weight: bold;
    }
    
    .text-danger {
        color: #dc3545;
        font-weight: bold;
    }
    
    @media (max-width: 1200px) {
        .container-fluid {
            width: 98%;
            padding: 10px;
        }
    }
    
    @media (max-width: 768px) {
        .container-fluid {
            width: 100%;
            padding: 5px;
        }
        
        .toggle-button {
            padding: 10px 15px;
            font-size: 1em;
        }
    }
</style>

<!-- JavaScript for node toggling and No_Qemu_GA updating -->
<script>
document.addEventListener("DOMContentLoaded", function() {
    // Initialize all nodes as open
    const allContents = document.querySelectorAll(".node-content");
    allContents.forEach(content => {
        content.classList.add('active');
    });

    const allButtons = document.querySelectorAll(".toggle-button");
    allButtons.forEach(button => {
        button.querySelector(".toggle-icon").textContent = "➖";
    });
    
    const checkboxes = document.querySelectorAll('.qemu-ga-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const vmId = this.getAttribute('data-vmid');
            const newStatus = this.checked ? 'Yes' : 'No';
            const originalState = this.checked;
            
            // Désactiver temporairement la case à cocher et montrer le chargement
            this.disabled = true;
            const originalCursor = this.style.cursor;
            this.style.cursor = 'wait';
            
            // Créer un objet FormData
            const formData = new FormData();
            formData.append('update_qemu_ga', '1');
            formData.append('vmid', vmId);
            formData.append('status', newStatus);
            formData.append('_token', '<?php echo csrf_token(); ?>');
            
            // Envoyer la requête AJAX
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                // Vérifions d'abord le type de contenu
                const contentType = response.headers.get('content-type');
                if (contentType && contentType.includes('application/json')) {
                    return response.json();
                } else {
                    // Si ce n'est pas du JSON, traitons la réponse comme un succès quand même
                    console.log('Response is not JSON, but we will consider it a success');
                    return { success: true };
                }
            })
            .then(data => {
                if (data.success) {
                    console.log('No_Qemu_GA status updated successfully');
                    // Mettre à jour l'état visuel de la case à cocher
                    this.checked = newStatus === 'Yes';
                } else {
                    console.error('Failed to update No_Qemu_GA status:', data.message);
                    // Revenir à l'état précédent en cas d'erreur
                    this.checked = originalState;
                }
                // Réactiver la case à cocher
                this.disabled = false;
                this.style.cursor = originalCursor;
            })
            .catch(error => {
                console.error('Error:', error);
                // Revenir à l'état précédent en cas d'erreur
                this.checked = originalState;
                // Réactiver la case à cocher
                this.disabled = false;
                this.style.cursor = originalCursor;
            });
        });
    });
});

function toggleNode(nodeId) {
    const nodeContent = document.getElementById(nodeId);
    const button = document.querySelector(`[data-target="${nodeId}"]`);

    if (nodeContent.classList.contains('active')) {
        nodeContent.classList.remove('active');
        nodeContent.classList.add('inactive');
        button.querySelector(".toggle-icon").textContent = "➕";
    } else {
        nodeContent.classList.remove('inactive');
        nodeContent.classList.add('active');
        button.querySelector(".toggle-icon").textContent = "➖";
    }
}
</script>
<?php
echo "------------\n";
// Column selector form
echo '<form method="POST" id="column-selector" class="column-selector">
    <input type="hidden" name="_token" value="' . csrf_token() . '">';

foreach ($available_columns as $key => $label) {
    $checked = in_array($key, $_POST['columns'] ?? array_keys($available_columns)) ? ' checked' : '';
    echo '<label><input type="checkbox" name="columns[]" value="' . $key . '"' . $checked . '> ' . 
         strip_tags($label) . '</label>';
}

echo '<button type="submit" style="background-color: #007bff; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer;">
        Apply column selection
    </button>
</form>';

$selected_columns = $_POST['columns'] ?? array_keys($available_columns);

?>

<form method="GET" class="filters-form">
    <label>State:
        <select name="state">
            <option value="">All</option>
            <option value="running" <?php echo $filter_state === 'running' ? 'selected' : ''; ?>>Running</option>
            <option value="stopped" <?php echo $filter_state === 'stopped' ? 'selected' : ''; ?>>Stopped</option>
        </select>
    </label>
    <label>VM ID: <input type="text" name="vmid" value="<?php echo escape_html($filter_vmid); ?>"></label>
    <label>CPU Usage > <input type="number" name="cpu_percent" min="0" value="<?php echo escape_html($filter_cpu_percent); ?>"></label>
    <label>Empty Disk: <input type="checkbox" name="disk_empty" value="yes" <?php echo $filter_disk_empty === 'yes' ? 'checked' : ''; ?>></label>
    <label>Bigger Disk Usage > <input type="number" name="bigger_disk" min="0" value="<?php echo escape_html($filter_bigger_disk); ?>"></label>
    <label>Oldest Snapshot > <input type="number" name="oldest_snapshot" min="0" value="<?php echo escape_html($filter_oldest_snapshot); ?>"></label>
    <label>QEMU Info Null: <input type="checkbox" name="qemu_info" value="null" <?php echo $filter_qemu_info === 'null' ? 'checked' : ''; ?>></label>
    <label>HA State NOT UP: <input type="checkbox" name="HA_State" value="null" <?php echo $filter_HA_State === 'null' ? 'checked' : ''; ?>></label>
    <label>Hide No Qemu-GA VMs: <input type="checkbox" name="hide_no_qemu_ga" value="yes" <?php echo $filter_hide_no_qemu_ga === 'yes' ? 'checked' : ''; ?>></label>
    <button type="submit">Apply Filters</button>
</form>

<?php

// Display VMs grouped by node
if (!empty($grouped_vms)) {
    echo '<div class="container-fluid">';
    foreach ($grouped_vms as $node_name => $vms) {
        $node_id = 'node-' . preg_replace('/[^a-zA-Z0-9]/', '-', $node_name);
        
        // Node header
        echo '<div class="node-group">
            <button class="toggle-button" onclick="toggleNode(\'' . $node_id . '\')" data-target="' . $node_id . '">
                <div class="node-info">
                    <span class="toggle-icon">➖</span>
                    <span>Node: ' . escape_html($node_name) . '</span>
                </div>
                <span class="vm-count">' . count($vms) . ' VMs</span>
            </button>
            <div id="' . $node_id . '" class="node-content">';

        // Table header
        echo '<div class="table-wrapper">'; // Add table wrapper
        echo '<table class="table table-striped"><thead><tr>';
        foreach ($selected_columns as $col) {
            if (isset($available_columns[$col])) {
                $columnClass = 'col-' . $col;
                echo '<th class="' . $columnClass . '">' . $available_columns[$col] . '</th>';
            }
        }
        echo '</tr></thead><tbody>';

        // Table content
        foreach ($vms as $vm) {
            echo '<tr>';
            foreach ($selected_columns as $col) {
                $tdClass = $col === 'disk_usage' ? 'disk-usage' : '';
                echo '<td class="' . $tdClass . '">';
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
                    case 'node_name':
                        echo escape_html($vm['node_name']);
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
                        if (is_array($ceph_snapshots) && count($ceph_snapshots) > 0) {
                            echo '<div style="max-height: 100px; overflow-y: auto; border: 1px solid #ddd; padding: 5px;">';
                            foreach ($ceph_snapshots as $snapshot) {
                                echo escape_html(preg_replace('/vm-\d+-state-/', '', $snapshot)) . '<br>';
                            }
                            echo '</div>';
                        } else {
                            echo 'No Snapshots';
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
                    case 'HA_State':
                        echo escape_html($vm['HA_State']);
                        break;
                    case 'No_Qemu_GA':
                        $checkedStatus = (isset($vm['No_Qemu_GA']) && $vm['No_Qemu_GA'] === 'Yes') ? 'checked' : '';
                        echo '<input type="checkbox" class="qemu-ga-checkbox" data-vmid="' . escape_html($vm['vmid']) . '" ' . $checkedStatus . '>';
                        break;
                }
                echo '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div></div>';
    }
    echo '</div>';
} else {
    echo '<div class="container-fluid">No virtual machines found.</div>';
}

// Handle CSV download logic
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['download_csv'])) {
    // Vérifiez si le token CSRF est valide
    if (!isset($_POST['_token']) || $_POST['_token'] !== csrf_token()) {
        die('Erreur de sécurité : le token CSRF est invalide.');
    }

    // Clear any previous output
    ob_clean();
    
    // Fetch VMs data
    $sqlGetVms = 'SELECT * FROM proxmox;';
    $result = $conn->query($sqlGetVms);

    // Define the CSV headers (columns)
    $csv_headers = array('State', 'VM ID', 'Name', 'Node name', 'CPU Usage', 'CPU Percent Usage', 'Memory Used', 'Disk Usage', 'Bigger Disk Usage', 'Ceph Snapshots', 'Total Snapshots', 'Oldest Snapshot', 'Qemu Info', 'Network IN', 'Network OUT', 'Uptime', 'HA_State');

    // Open PHP output stream for CSV
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename=proxmox_vms.csv');

    $output = fopen('php://output', 'w');
    
    // Write the headers to CSV
    fputcsv($output, $csv_headers, ';');

    // Write the VM data rows
    if ($result->num_rows > 0) {
        while ($vm = $result->fetch_assoc()) {
            // Prepare data for each row
        $row = [
            $vm['status'] ?? 'NULL',
            $vm['vmid'] ?? 'NULL',
            $vm['name'] ?? 'NULL',
            $vm['node_name'] ?? 'NULL',
            ($vm['cpu'] ?? 'NULL') . ' / ' . ($vm['cpus'] ?? 'NULL'),
            ($vm['cpu_percent'] ?? 'NULL') . ' %',
            ($vm['mem'] ? formatBytes($vm['mem']) : 'NULL') . ' / ' . ($vm['maxmem'] ? formatBytes($vm['maxmem']) : 'NULL'),
            $vm['disk_usage'] ?? 'NULL',
            ($vm['bigger_disk_percent_usage'] ?? 'NULL') . '%',
            $vm['ceph_snapshots'] ? implode(',', unserialize($vm['ceph_snapshots'])) : 'NULL',
            $vm['ceph_total_snapshots'] ?? 'NULL',
            $vm['oldest_snapshot'] ?? 'NULL',
            $vm['qemu_info'] ?? 'NULL',
            $vm['netin'] ? formatBytes($vm['netin']) : 'NULL',
            $vm['netout'] ? formatBytes($vm['netout']) : 'NULL',
            $vm['uptime'] ? formatUptime($vm['uptime']) : 'NULL',
	    $vm['HA_State'] ? ($vm['HA_State']) : 'NULL',
	];
            // Write the row to the CSV
            fputcsv($output, $row, ';');
        }
    }

    // Close the output stream
    fclose($output);
    exit();
}

$conn->close();
?>

