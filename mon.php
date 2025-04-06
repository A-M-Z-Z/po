<?php
session_start();

// Basic authentication check
if (!isset($_SESSION['user_id'])) {
    header("Location: expired.php");
    exit;
}

$isAdmin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
$username = $_SESSION['username'];

// Database Connection
$host = 'localhost';
$user = 'root';
$pass = 'root';
$dbname = 'cloudbox';
$conn = new mysqli($host, $user, $pass, $dbname);

// Function to safely run shell commands
function runCommand($command) {
    try {
        if (function_exists('shell_exec')) {
            $output = @shell_exec($command . " 2>&1");
            return $output !== null ? trim($output) : "Command failed";
        }
        return "shell_exec function is disabled";
    } catch (Exception $e) {
        return "Error: " . $e->getMessage();
    }
}

// Function to get system data
function getSystemData($conn, $userId, $isAdmin) {
    $data = [
        'user_storage' => [
            'used' => 0,
            'total' => 100, // Default 100MB
            'percent' => 0
        ]
    ];
    
    // Get user storage data
    $query = $conn->prepare("SELECT SUM(file_size) as total_size FROM files WHERE user_id = ?");
    $query->bind_param("i", $userId);
    $query->execute();
    $result = $query->get_result();
    $row = $result->fetch_assoc();
    $usedStorage = $row['total_size'] ?: 0;
    
    // Get user quota
    $query = $conn->prepare("SELECT storage_quota FROM users WHERE id = ?");
    $query->bind_param("i", $userId);
    $query->execute();
    $result = $query->get_result();
    $row = $result->fetch_assoc();
    $quota = $row['storage_quota'] ?: 104857600; // Default 100MB
    
    $data['user_storage']['used'] = round($usedStorage / (1024 * 1024), 2); // MB
    $data['user_storage']['total'] = round($quota / (1024 * 1024), 2); // MB
    $data['user_storage']['percent'] = $quota > 0 ? round(($usedStorage / $quota) * 100, 1) : 0;
    
    // Admin-only system information
    if ($isAdmin) {
        // CPU Usage
        $data['cpu_usage'] = substr(runCommand("top -bn1 | grep 'Cpu(s)' | sed 's/.*, *\\([0-9.]*\\)%* id.*/\\1/' | awk '{print 100 - $1}'"), 0, 5);
        
        // Memory Usage
        $meminfo = runCommand("free -m | grep Mem");
        preg_match_all('/\d+/', $meminfo, $matches);
        if (isset($matches[0][0]) && isset($matches[0][1])) {
            $total_mem = $matches[0][0];
            $used_mem = $matches[0][1];
            $data['memory'] = [
                'total' => $total_mem,
                'used' => $used_mem,
                'percent' => round(($used_mem / $total_mem) * 100, 1)
            ];
        }
        
        // Disk Usage
        $diskinfo = runCommand("df -h / | tail -1");
        preg_match('/(\d+)%/', $diskinfo, $matches);
        $data['disk_usage'] = isset($matches[1]) ? $matches[1] : "N/A";
        
        // System Temperature
        $temp_file = '/sys/class/thermal/thermal_zone0/temp';
        if (file_exists($temp_file)) {
            $temp = intval(file_get_contents($temp_file));
            $data['temperature'] = round($temp / 1000, 1);
        } else {
            $temp = runCommand("cat /sys/class/thermal/thermal_zone*/temp 2>/dev/null | head -1");
            if (is_numeric($temp) && $temp > 1000) {
                $data['temperature'] = round($temp / 1000, 1);
            } else {
                $data['temperature'] = "N/A";
            }
        }
        
        // Uptime
        $data['uptime'] = runCommand("uptime -p");
        
        // System Load
        $loadavg = runCommand("cat /proc/loadavg");
        $loads = explode(" ", $loadavg);
        $data['load_average'] = [
            '1min' => isset($loads[0]) ? $loads[0] : "N/A",
            '5min' => isset($loads[1]) ? $loads[1] : "N/A",
            '15min' => isset($loads[2]) ? $loads[2] : "N/A"
        ];
        
        // File counts
        $query = "SELECT COUNT(*) as count FROM files";
        $result = $conn->query($query);
        $row = $result->fetch_assoc();
        $data['file_count'] = $row['count'];
        
        $query = "SELECT COUNT(*) as count FROM users";
        $result = $conn->query($query);
        $row = $result->fetch_assoc();
        $data['user_count'] = $row['count'];
    }
    
    return $data;
}

// Handle AJAX request
if (isset($_GET['get_data'])) {
    header('Content-Type: application/json');
    echo json_encode(getSystemData($conn, $_SESSION['user_id'], $isAdmin));
    exit;
}

// Get initial data
$systemData = getSystemData($conn, $_SESSION['user_id'], $isAdmin);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CloudBOX - System Monitoring</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .top-bar {
            background-color: #4f46e5;
            padding: 15px;
            display: flex;
            align-items: center;
            color: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .logo {
            margin-right: 15px;
        }
        
        .top-bar h1 {
            margin: 0;
            font-size: 22px;
        }
        
        .search-bar {
            margin-left: auto;
        }
        
        .search-bar input {
            border-radius: 20px;
            padding: 8px 15px;
            border: none;
            width: 250px;
        }
        
        .dashboard-nav {
            background-color: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 15px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: center; /* Centrer les éléments de navigation */
        }
        
        .dashboard-nav a {
            color: #4b5563;
            text-decoration: none;
            padding: 8px 15px;
            border-radius: 6px;
            transition: background-color 0.2s;
        }
        
        .dashboard-nav a:hover {
            background-color: #f3f4f6;
            color: #4f46e5;
        }
        
        main {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .monitoring-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .metric-card {
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 20px;
            display: flex;
            flex-direction: column;
        }
        
        .metric-title {
            font-size: 16px;
            color: #6b7280;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
        }
        
        .metric-title .icon {
            margin-right: 10px;
            font-size: 20px;
        }
        
        .metric-value {
            font-size: 32px;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .metric-subtitle {
            font-size: 14px;
            color: #9ca3af;
        }
        
        .progress-bar {
            width: 100%;
            height: 8px;
            background-color: #e5e7eb;
            border-radius: 4px;
            margin-top: 15px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.5s ease;
        }
        
        .good {
            background-color: #10b981;
            color: #10b981;
        }
        
        .warning {
            background-color: #f59e0b;
            color: #f59e0b;
        }
        
        .danger {
            background-color: #ef4444;
            color: #ef4444;
        }
        
        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 5px;
        }
        
        .refresh-bar {
            width: 100%;
            height: 2px;
            background-color: #e5e7eb;
            margin-top: 30px;
            position: relative;
            overflow: hidden;
        }
        
        .refresh-progress {
            position: absolute;
            height: 100%;
            width: 100%;
            background-color: #4f46e5;
            transform: translateX(-100%);
            animation: refreshAnimation 30s linear infinite;
        }
        
        @keyframes refreshAnimation {
            0% {
                transform: translateX(-100%);
            }
            100% {
                transform: translateX(0);
            }
        }
        
        .refresh-info {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: #6b7280;
            margin-top: 5px;
        }
        
        .system-info {
            background-color: #fff;
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .system-info h3 {
            margin-top: 0;
            margin-bottom: 10px;
            font-size: 16px;
            color: #374151;
        }
        
        .system-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .info-item {
            padding: 10px;
            background-color: #f9fafb;
            border-radius: 6px;
        }
        
        .info-label {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 5px;
        }
        
        .info-value {
            font-size: 16px;
            font-weight: bold;
            color: #374151;
        }
        
        @media (max-width: 1024px) {
            .monitoring-container {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            }
        }
        
        @media (max-width: 768px) {
            .monitoring-container {
                grid-template-columns: 1fr;
            }
            
            .search-bar input {
                width: 150px;
            }
        }
    </style>
</head>
<body>
    <div class="top-bar">
        <div class="logo">
            <img src="logo.png" alt="CloudBOX Logo" height="40">
        </div>
        <h1>CloudBOX</h1>
        <div class="search-bar">
            <input type="text" placeholder="Search files and folders..." class="form-control">
        </div>
    </div>
    
    <nav class="dashboard-nav">
        <a href="home"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <a href="drive"><i class="fas fa-folder"></i> My Drive</a>
        <?php if($isAdmin): ?>
        <a href="admin"><i class="fas fa-crown"></i> Admin Panel</a>
        <?php endif; ?>
        <a href="shared"><i class="fas fa-share-alt"></i> Shared Files</a>
        <a href="monitoring"><i class="fas fa-chart-line"></i> Monitoring</a>
        <a href="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </nav>
    
    <main>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3">System Monitoring</h1>
            <div>
                <span class="text-muted">Welcome, <?= htmlspecialchars($username) ?>!</span>
            </div>
        </div>
        
        <div class="monitoring-container">
            <!-- Storage Usage Card -->
            <div class="metric-card">
                <div class="metric-title">
                    <div class="icon"><i class="fas fa-hdd"></i></div>
                    Your Storage Usage
                </div>
                <div class="metric-value" id="storage-percent"><?= $systemData['user_storage']['percent'] ?>%</div>
                <div class="metric-subtitle">
                    <span id="storage-used"><?= $systemData['user_storage']['used'] ?></span> MB of 
                    <span id="storage-total"><?= $systemData['user_storage']['total'] ?></span> MB used
                </div>
                <div class="progress-bar">
                    <div class="progress-fill <?= $systemData['user_storage']['percent'] > 90 ? 'danger' : ($systemData['user_storage']['percent'] > 70 ? 'warning' : 'good') ?>" 
                         style="width: <?= $systemData['user_storage']['percent'] ?>%"></div>
                </div>
            </div>
            
            <?php if($isAdmin): ?>
            <!-- CPU Usage Card -->
            <div class="metric-card">
                <div class="metric-title">
                    <div class="icon"><i class="fas fa-microchip"></i></div>
                    CPU Usage
                </div>
                <div class="metric-value" id="cpu-usage"><?= $systemData['cpu_usage'] ?>%</div>
                <div class="metric-subtitle">
                    System processor utilization
                </div>
                <div class="progress-bar">
                    <div class="progress-fill <?= $systemData['cpu_usage'] > 80 ? 'danger' : ($systemData['cpu_usage'] > 60 ? 'warning' : 'good') ?>" 
                         style="width: <?= $systemData['cpu_usage'] ?>%"></div>
                </div>
            </div>
            
            <!-- Memory Usage Card -->
            <div class="metric-card">
                <div class="metric-title">
                    <div class="icon"><i class="fas fa-memory"></i></div>
                    Memory Usage
                </div>
                <div class="metric-value" id="memory-percent"><?= $systemData['memory']['percent'] ?>%</div>
                <div class="metric-subtitle">
                    <span id="memory-used"><?= $systemData['memory']['used'] ?></span> MB of 
                    <span id="memory-total"><?= $systemData['memory']['total'] ?></span> MB used
                </div>
                <div class="progress-bar">
                    <div class="progress-fill <?= $systemData['memory']['percent'] > 90 ? 'danger' : ($systemData['memory']['percent'] > 70 ? 'warning' : 'good') ?>" 
                         style="width: <?= $systemData['memory']['percent'] ?>%"></div>
                </div>
            </div>
            
            <!-- Disk Usage Card -->
            <div class="metric-card">
                <div class="metric-title">
                    <div class="icon"><i class="fas fa-database"></i></div>
                    Disk Usage
                </div>
                <div class="metric-value" id="disk-usage"><?= $systemData['disk_usage'] ?>%</div>
                <div class="metric-subtitle">
                    Root filesystem utilization
                </div>
                <div class="progress-bar">
                    <div class="progress-fill <?= $systemData['disk_usage'] > 90 ? 'danger' : ($systemData['disk_usage'] > 70 ? 'warning' : 'good') ?>" 
                         style="width: <?= $systemData['disk_usage'] ?>%"></div>
                </div>
            </div>
            
            <!-- CPU Temperature Card -->
            <div class="metric-card">
                <div class="metric-title">
                    <div class="icon"><i class="fas fa-temperature-high"></i></div>
                    CPU Temperature
                </div>
                <div class="metric-value <?= $systemData['temperature'] > 80 ? 'danger' : ($systemData['temperature'] > 60 ? 'warning' : 'good') ?>" id="temperature">
                    <?= $systemData['temperature'] !== "N/A" ? $systemData['temperature'] . "°C" : "N/A" ?>
                </div>
                <div class="metric-subtitle">
                    Current processor temperature
                </div>
                <?php if($systemData['temperature'] !== "N/A"): ?>
                <div class="progress-bar">
                    <div class="progress-fill <?= $systemData['temperature'] > 80 ? 'danger' : ($systemData['temperature'] > 60 ? 'warning' : 'good') ?>" 
                         style="width: <?= min(100, ($systemData['temperature'] / 100) * 100) ?>%"></div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- System Details -->
            <div class="metric-card">
                <div class="metric-title">
                    <div class="icon"><i class="fas fa-server"></i></div>
                    System Overview
                </div>
                <div style="margin-top: 10px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 15px;">
                        <div>
                            <div class="info-label">Load Average</div>
                            <div class="info-value" id="load-average">
                                <?= $systemData['load_average']['1min'] ?> / <?= $systemData['load_average']['5min'] ?> / <?= $systemData['load_average']['15min'] ?>
                            </div>
                        </div>
                        <div>
                            <div class="info-label">Uptime</div>
                            <div class="info-value" id="uptime"><?= $systemData['uptime'] ?></div>
                        </div>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <div>
                            <div class="info-label">Total Files</div>
                            <div class="info-value" id="file-count"><?= $systemData['file_count'] ?></div>
                        </div>
                        <div>
                            <div class="info-label">Total Users</div>
                            <div class="info-value" id="user-count"><?= $systemData['user_count'] ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="metric-card">
                <div class="metric-title">
                    <div class="icon"><i class="fas fa-info-circle"></i></div>
                    System Information
                </div>
                <p style="margin-top: 20px;">System monitoring information is only available to administrators.</p>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Auto-refresh indicator -->
        <div class="refresh-bar">
            <div class="refresh-progress" id="refresh-progress"></div>
        </div>
        <div class="refresh-info">
            <span>Data auto-refreshes every 30 seconds</span>
            <span>Last updated: <span id="last-updated"><?= date('H:i:s') ?></span></span>
        </div>
    </main>
    
    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Auto-refresh data every 30 seconds
        setInterval(fetchData, 30000);
        
        // Initial fetch after page load
        document.addEventListener('DOMContentLoaded', function() {
            // Reset the animation
            restartRefreshAnimation();
        });
        
        // Function to fetch updated data
        function fetchData() {
            fetch('monitoring.php?get_data=true')
                .then(response => response.json())
                .then(data => {
                    updateUI(data);
                    restartRefreshAnimation();
                })
                .catch(error => {
                    console.error('Error fetching monitoring data:', error);
                });
        }
        
        // Function to update the UI with new data
        function updateUI(data) {
            // Update last updated time
            document.getElementById('last-updated').textContent = new Date().toLocaleTimeString();
            
            // Update storage info
            document.getElementById('storage-percent').textContent = data.user_storage.percent + '%';
            document.getElementById('storage-used').textContent = data.user_storage.used;
            document.getElementById('storage-total').textContent = data.user_storage.total;
            updateProgressBar('storage-percent', data.user_storage.percent, 70, 90);
            
            <?php if($isAdmin): ?>
            // Update CPU usage
            document.getElementById('cpu-usage').textContent = data.cpu_usage + '%';
            updateProgressBar('cpu-usage', data.cpu_usage, 60, 80);
            
            // Update memory usage
            document.getElementById('memory-percent').textContent = data.memory.percent + '%';
            document.getElementById('memory-used').textContent = data.memory.used;
            document.getElementById('memory-total').textContent = data.memory.total;
            updateProgressBar('memory-percent', data.memory.percent, 70, 90);
            
            // Update disk usage
            document.getElementById('disk-usage').textContent = data.disk_usage + '%';
            updateProgressBar('disk-usage', data.disk_usage, 70, 90);
            
            // Update temperature
            let tempDisplay = data.temperature !== "N/A" ? data.temperature + "°C" : "N/A";
            document.getElementById('temperature').textContent = tempDisplay;
            if (data.temperature !== "N/A") {
                updateProgressBar('temperature', (data.temperature / 100) * 100, 60, 80);
            }
            
            // Update system overview
            document.getElementById('load-average').textContent = `${data.load_average['1min']} / ${data.load_average['5min']} / ${data.load_average['15min']}`;
            document.getElementById('uptime').textContent = data.uptime;
            document.getElementById('file-count').textContent = data.file_count;
            document.getElementById('user-count').textContent = data.user_count;
            <?php endif; ?>
        }
        
        // Function to update progress bar colors
        function updateProgressBar(elementId, value, warningThreshold, dangerThreshold) {
            const progressBar = document.querySelector(`#${elementId}`).closest('.metric-card').querySelector('.progress-fill');
            if (!progressBar) return;
            
            // Remove existing classes
            progressBar.classList.remove('good', 'warning', 'danger');
            
            // Add appropriate class based on thresholds
            if (value > dangerThreshold) {
                progressBar.classList.add('danger');
            } else if (value > warningThreshold) {
                progressBar.classList.add('warning');
            } else {
                progressBar.classList.add('good');
            }
            
            // Update width
            progressBar.style.width = `${Math.min(100, value)}%`;
        }
        
        // Function to restart refresh animation
        function restartRefreshAnimation() {
            const progress = document.getElementById('refresh-progress');
            progress.style.animation = 'none';
            progress.offsetHeight; // Trigger reflow
            progress.style.animation = 'refreshAnimation 30s linear infinite';
        }
    </script>
</body>
</html>
