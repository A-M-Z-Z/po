<?php
session_start(); // Start session

// Verify user is logged in
if (!isset($_SESSION['username']) || !isset($_SESSION['user_id'])) {
    header("Location: expired");
    exit();
}

// Database Connection
$host = 'localhost';
$user = 'root';
$pass = 'root';
$dbname = 'cloudbox';
$conn = new mysqli($host, $user, $pass, $dbname);

$username = $_SESSION['username'];
$userid = $_SESSION['user_id'];

// Current folder ID
$current_folder_id = isset($_GET['folder_id']) ? intval($_GET['folder_id']) : null;

// Create folder
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_folder_name'])) {
    $folder_name = $conn->real_escape_string(trim($_POST['new_folder_name']));
    
    if (!empty($folder_name)) {
        // Create folder
        $query = "INSERT INTO folders (user_id, folder_name, parent_folder_id) VALUES ($userid, '$folder_name', ";
        $query .= $current_folder_id ? $current_folder_id : "NULL";
        $query .= ")";
        
        if ($conn->query($query)) {
            $folder_message = "<p style='color:green;'>Folder created successfully.</p>";
        } else {
            $folder_message = "<p style='color:red;'>Error creating folder: " . $conn->error . "</p>";
        }
    }
}

// Delete folder
if (isset($_GET['delete_folder']) && is_numeric($_GET['delete_folder'])) {
    $folder_id = intval($_GET['delete_folder']);
    
    // Check if folder belongs to user
    $check = $conn->query("SELECT id FROM folders WHERE id = $folder_id AND user_id = $userid");
    if ($check->num_rows > 0) {
        if ($conn->query("DELETE FROM folders WHERE id = $folder_id")) {
            $delete_message = "<p style='color:green;'>Folder deleted successfully.</p>";
            
            // Redirect if current folder was deleted
            if ($folder_id == $current_folder_id) {
                $parent = $conn->query("SELECT parent_folder_id FROM folders WHERE id = $folder_id")->fetch_assoc();
                $parent_id = $parent ? $parent['parent_folder_id'] : null;
                
                header("Location: home.php" . ($parent_id ? "?folder_id=$parent_id" : ""));
                exit();
            }
        } else {
            $delete_message = "<p style='color:red;'>Error deleting folder: " . $conn->error . "</p>";
        }
    }
}

// Get current folder info
$current_folder_name = "Root";
$parent_folder_id = null;

if ($current_folder_id) {
    $folder_info = $conn->query("SELECT folder_name, parent_folder_id FROM folders WHERE id = $current_folder_id AND user_id = $userid");
    if ($folder_info->num_rows > 0) {
        $folder = $folder_info->fetch_assoc();
        $current_folder_name = $folder['folder_name'];
        $parent_folder_id = $folder['parent_folder_id'];
    } else {
        // Invalid folder ID, redirect to root
        header("Location: home.php");
        exit();
    }
}

// Get subfolders
$folders = [];
$query = "SELECT id, folder_name FROM folders WHERE user_id = $userid AND ";
$query .= $current_folder_id ? "parent_folder_id = $current_folder_id" : "parent_folder_id IS NULL";
$query .= " ORDER BY folder_name";

$result = $conn->query($query);
while ($folder = $result->fetch_assoc()) {
    $folders[] = $folder;
}

// Get breadcrumb
function getBreadcrumb($conn, $folder_id, $userid) {
    $path = [];
    $current = $folder_id;
    
    while ($current) {
        $result = $conn->query("SELECT id, folder_name, parent_folder_id FROM folders WHERE id = $current AND user_id = $userid");
        if ($result->num_rows > 0) {
            $folder = $result->fetch_assoc();
            array_unshift($path, ['id' => $folder['id'], 'name' => $folder['folder_name']]);
            $current = $folder['parent_folder_id'];
        } else {
            break;
        }
    }
    
    return $path;
}

$breadcrumb = $current_folder_id ? getBreadcrumb($conn, $current_folder_id, $userid) : [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CloudBOX - Folder Structure</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .folder-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .folder-item {
            background-color: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 15px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .folder-icon {
            font-size: 48px;
            color: #4f46e5;
            margin-bottom: 10px;
        }
        
        .folder-name {
            text-align: center;
            font-weight: 500;
        }
        
        .folder-actions {
            display: flex;
            margin-top: 10px;
        }
        
        .folder-actions a {
            margin: 0 5px;
            padding: 5px 10px;
            background-color: #f3f4f6;
            border-radius: 4px;
            text-decoration: none;
            color: #374151;
        }
        
        .folder-actions a.delete {
            color: #ef4444;
        }
        
        .breadcrumb {
            background-color: #f9fafb;
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
        }
        
        .breadcrumb a {
            color: #4f46e5;
            text-decoration: none;
            margin: 0 5px;
        }
        
        .breadcrumb span {
            color: #9ca3af;
            margin: 0 5px;
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
            <input type="text" placeholder="Search here...">
        </div>
    </div>
    
    <nav class="dashboard-nav">
        <a href="home">📊 Dashboard</a>
        <a href="drive">📁 My Drive</a>
        <?php if(isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1): ?>
        <a href="admin">👑 Admin Panel</a>
        <?php endif; ?>
        <a href="shared">🔄 Shared Files</a>
        <a href="monitoring">📈 Monitoring</a>
        <a href="logout">🚪 Logout</a>
    </nav>

    <main>
        <h1>Welcome, <?= htmlspecialchars($username) ?>!</h1>
        
        <!-- Display messages -->
        <?php if (isset($folder_message)) echo $folder_message; ?>
        <?php if (isset($delete_message)) echo $delete_message; ?>
        
        <!-- Breadcrumb navigation -->
        <div class="breadcrumb">
            <a href="home.php">📁 Root</a>
            <?php foreach ($breadcrumb as $folder): ?>
                <span>›</span>
                <a href="home.php?folder_id=<?= $folder['id'] ?>"><?= htmlspecialchars($folder['name']) ?></a>
            <?php endforeach; ?>
        </div>
        
        <!-- Current folder info -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2>Current folder: <?= htmlspecialchars($current_folder_name) ?></h2>
            
            <!-- Create folder form -->
            <form method="POST" style="display: flex;">
                <input type="text" name="new_folder_name" placeholder="New folder name" required style="margin-right: 10px;">
                <button type="submit">Create Folder</button>
            </form>
        </div>
        
        <!-- Folders display -->
        <?php if (!empty($folders)): ?>
            <div class="folder-container">
                <?php foreach ($folders as $folder): ?>
                    <div class="folder-item">
                        <div class="folder-icon">📁</div>
                        <div class="folder-name"><?= htmlspecialchars($folder['folder_name']) ?></div>
                        <div class="folder-actions">
                            <a href="home.php?folder_id=<?= $folder['id'] ?>">Open</a>
                            <a href="home.php?delete_folder=<?= $folder['id'] ?><?= $current_folder_id ? '&folder_id='.$current_folder_id : '' ?>" 
                               class="delete" 
                               onclick="return confirm('Are you sure you want to delete this folder?');">Delete</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p>No folders in this location. Create a folder to get started.</p>
        <?php endif; ?>
    </main>
</body>
</html>
