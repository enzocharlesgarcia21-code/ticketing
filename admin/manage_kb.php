<?php
require_once '../config/database.php';

// Access Control
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

// Handle Form Submission (Add/Delete)
$success_msg = '';
$error_msg = '';

// 1. Add New Article
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $title = trim($_POST['title']);
    $category = trim($_POST['category']);
    $content = trim($_POST['content']);

    if (!empty($title) && !empty($category) && !empty($content)) {
        $image_path = null;
        
        // Handle Image Upload
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/kb_images/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $new_filename = uniqid('kb_', true) . '.' . $file_extension;
            $target_file = $upload_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
                $image_path = 'uploads/kb_images/' . $new_filename;
            }
        }

        $stmt = $conn->prepare("INSERT INTO knowledge_base (title, category, content, image_path, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("ssss", $title, $category, $content, $image_path);
        
        if ($stmt->execute()) {
            $success_msg = "Article added successfully!";
        } else {
            $error_msg = "Error adding article: " . $conn->error;
        }
    } else {
        $error_msg = "All fields are required.";
    }
}

// 2. Delete Article
if (isset($_GET['delete_id'])) {
    $delete_id = (int)$_GET['delete_id'];
    $stmt = $conn->prepare("DELETE FROM knowledge_base WHERE id = ?");
    $stmt->bind_param("i", $delete_id);
    
    if ($stmt->execute()) {
        $success_msg = "Article deleted successfully!";
    } else {
        $error_msg = "Error deleting article.";
    }
}

// 2.5 Check for Update Success Message
if (isset($_GET['msg']) && $_GET['msg'] === 'updated') {
    $success_msg = "Article updated successfully!";
}

// 3. Fetch Articles & Calculate Stats
$result = $conn->query("SELECT * FROM knowledge_base ORDER BY created_at DESC");
$articles = [];
$total_views = 0;
$unique_categories = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $articles[] = $row;
        $total_views += isset($row['views']) ? (int)$row['views'] : 0;
        if (!empty($row['category'])) {
            $unique_categories[$row['category']] = true;
        }
    }
}

$total_articles = count($articles);
$categories_count = count($unique_categories);

// Pre-defined categories
$categories = ['General', 'Technical Support', 'HR Policies', 'Software Guides', 'Hardware Troubleshooting', 'Network', 'Security'];
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Knowledge Base | Admin</title>
    <link rel="stylesheet" href="../css/admin.css?v=<?php echo time(); ?>">
    <!-- Using Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #F3F4F6; /* Ensure background is light gray */
        }

        .kb-wrapper {
            padding: 20px 40px 40px; /* Reduced top padding */
            max-width: 1250px; /* Adjusted width */
            margin: 0 auto;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 15px;
            border-bottom: 3px solid transparent;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .stat-card.green-accent { border-bottom-color: #10B981; }
        .stat-card.blue-accent { border-bottom-color: #3B82F6; }
        .stat-card.purple-accent { border-bottom-color: #8B5CF6; }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .stat-icon.green { background: #ECFDF5; color: #059669; }
        .stat-icon.blue { background: #EFF6FF; color: #2563EB; }
        .stat-icon.purple { background: #F5F3FF; color: #7C3AED; }

        .stat-info h3 {
            margin: 0;
            font-size: 24px;
            font-weight: 700;
            color: #111827;
        }

        .stat-info p {
            margin: 0;
            font-size: 13px;
            color: #6B7280;
            font-weight: 500;
        }

        /* Page Header */
        .kb-page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .page-title {
            font-size: 26px; /* Increased size */
            font-weight: 800;
            color: #111827;
            letter-spacing: -0.5px;
        }

        .btn-add-article {
            background-color: #10B981;
            color: white;
            border: none;
            padding: 14px 28px; /* Larger button */
            border-radius: 10px;
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.2s;
            box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.3);
        }

        .btn-add-article:hover {
            background-color: #059669;
            transform: translateY(-2px);
            box-shadow: 0 6px 12px -1px rgba(16, 185, 129, 0.4);
        }

        /* Table Card */
        .kb-table-card {
            background: white;
            border-radius: 16px; /* More rounded */
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 10px 15px -3px rgba(0, 0, 0, 0.05); /* Stronger shadow */
            border: 1px solid #E5E7EB;
            overflow: hidden;
            margin-bottom: 40px; /* Spacing at bottom */
        }

        .kb-table-header {
            padding: 20px 30px;
            border-bottom: 1px solid #E5E7EB;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: #FFFFFF;
        }

        .kb-table-title {
            font-size: 18px;
            font-weight: 700;
            color: #111827;
        }

        .kb-table {
            width: 100%;
            border-collapse: collapse;
        }

        .kb-table th, .kb-table td {
            padding: 20px 30px; /* Increased padding */
            text-align: left;
            border-bottom: 1px solid #F3F4F6;
        }

        .kb-table th {
            background-color: #F0FDF4; /* Green tint */
            font-weight: 600;
            color: #065F46; /* Darker green text */
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .kb-table tr:last-child td {
            border-bottom: none;
        }

        .kb-table tr:hover {
            background-color: #F9FAFB;
        }

        .kb-table tr {
            cursor: pointer;
        }

        .article-title {
            font-weight: 600;
            color: #111827;
            font-size: 15px;
        }

        .badge-category {
            background-color: #ECFDF5;
            color: #059669;
            padding: 6px 12px;
            border-radius: 6px; /* Slightly less rounded */
            font-size: 13px;
            font-weight: 600;
            display: inline-block;
            border: 1px solid #D1FAE5;
        }

        .meta-text {
            color: #6B7280;
            font-size: 14px;
        }

        .action-btn {
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all 0.2s;
        }

        .btn-delete {
            background-color: #FEF2F2;
            color: #B91C1C;
            border: 1px solid #FEE2E2;
        }

        .btn-delete:hover {
            background-color: #FEE2E2;
            border-color: #FECACA;
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .alert-success {
            background-color: #D1FAE5;
            color: #065F46;
            border: 1px solid #A7F3D0;
        }

        .alert-error {
            background-color: #FEE2E2;
            color: #991B1B;
            border: 1px solid #FECACA;
        }

        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6); /* Darker overlay */
            z-index: 1000;
            justify-content: center;
            align-items: center;
            animation: fadeIn 0.2s ease-out;
            backdrop-filter: blur(4px); /* Stronger blur */
        }

        .modal-content {
            background: white;
            padding: 35px;
            border-radius: 16px;
            width: 100%;
            max-width: 650px; /* Slightly wider */
            max-height: 85vh; /* Limit height */
            overflow-y: auto; /* Enable scrolling */
            position: relative;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            animation: slideUp 0.3s ease-out;
            display: flex;
            flex-direction: column;
        }

        /* Tab Styles */
        .modal-tabs {
            display: flex;
            border-bottom: 1px solid #E5E7EB;
            margin-bottom: 20px;
        }

        .modal-tab {
            padding: 10px 20px;
            font-weight: 600;
            color: #6B7280;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all 0.2s;
        }

        .modal-tab.active {
            color: #10B981;
            border-bottom-color: #10B981;
        }

        .modal-tab:hover:not(.active) {
            color: #374151;
        }

        /* Preview Content Styles */
        .preview-content {
            background: #F9FAFB;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #E5E7EB;
            min-height: 200px;
            max-height: 400px;
            overflow-y: auto;
            display: none; /* Hidden by default */
        }

        .preview-content h1, .preview-content h2, .preview-content h3 {
            margin-top: 0;
            color: #111827;
        }

        .preview-content p {
            line-height: 1.6;
            color: #374151;
            margin-bottom: 1em;
        }

        .preview-content ul, .preview-content ol {
            padding-left: 20px;
            margin-bottom: 1em;
        }
        
        .preview-content li {
            margin-bottom: 0.5em;
        }

        /* Loading Spinner */
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #10B981;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
            display: inline-block;
            margin-right: 10px;
            vertical-align: middle;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid #E5E7EB;
        }

        .modal-title {
            font-size: 22px;
            font-weight: 700;
            color: #111827;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            color: #9CA3AF;
            cursor: pointer;
            transition: color 0.2s;
            padding: 5px;
            line-height: 1;
            border-radius: 50%;
        }

        .close-modal:hover {
            color: #111827;
            background-color: #F3F4F6;
        }

        /* Form Styles in Modal */
        .form-group {
            margin-bottom: 24px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
            font-size: 14px;
        }

        .form-control {
            width: 100%;
            padding: 14px;
            border: 1px solid #D1D5DB;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.2s;
            box-sizing: border-box;
        }

        .form-control:focus {
            border-color: #10B981;
            outline: none;
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1);
        }

        textarea.form-control {
            min-height: 180px;
            resize: vertical;
            font-family: inherit;
            line-height: 1.5;
        }

        .btn-submit {
            background-color: #10B981;
            color: white;
            border: none;
            padding: 14px 20px;
            border-radius: 10px;
            cursor: pointer;
            width: 100%;
            font-weight: 600;
            font-size: 16px;
            transition: background-color 0.2s;
            box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.2);
        }

        .btn-submit:hover {
            background-color: #059669;
            transform: translateY(-1px);
            box-shadow: 0 6px 8px -1px rgba(16, 185, 129, 0.3);
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .kb-wrapper {
                padding: 20px;
            }
            .modal-content {
                margin: 20px;
                max-width: calc(100% - 40px);
            }
        }
    </style>
</head>
<body>

<div class="admin-page">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="kb-wrapper">
        
        <?php if ($success_msg): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_msg) ?>
            </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_msg) ?>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="kb-page-header">
            <div class="page-title">Knowledge Base</div>
            <button class="btn-add-article" onclick="openModal()">
                <i class="fas fa-plus"></i> Add New Article
            </button>
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card green-accent">
                <div class="stat-icon green">
                    <i class="fas fa-book"></i>
                </div>
                <div class="stat-info">
                    <h3><?= $total_articles ?></h3>
                    <p>Total Articles</p>
                </div>
            </div>
            <div class="stat-card blue-accent">
                <div class="stat-icon blue">
                    <i class="far fa-eye"></i>
                </div>
                <div class="stat-info">
                    <h3><?= number_format($total_views) ?></h3>
                    <p>Total Views</p>
                </div>
            </div>
            <div class="stat-card purple-accent">
                <div class="stat-icon purple">
                    <i class="fas fa-tags"></i>
                </div>
                <div class="stat-info">
                    <h3><?= $categories_count ?></h3>
                    <p>Categories</p>
                </div>
            </div>
        </div>

        <!-- LIST TABLE -->
        <div class="kb-table-card">
            <div class="kb-table-header">
                <div class="kb-table-title">Published Articles</div>
                <div style="font-size: 13px; color: #6B7280; background: #F3F4F6; padding: 4px 10px; border-radius: 20px;">
                    Showing <strong><?= $total_articles ?></strong> articles
                </div>
            </div>

            <table class="kb-table">
                <thead>
                    <tr>
                        <th width="40%">Article Title</th>
                        <th width="20%">Category</th>
                        <th width="15%">Views</th>
                        <th width="15%">Created Date</th>
                        <th width="10%" style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($articles) > 0): ?>
                        <?php foreach($articles as $row): ?>
                            <tr onclick="openEditModal(<?= $row['id'] ?>)">
                                <td>
                                    <div class="article-title"><?= htmlspecialchars($row['title']) ?></div>
                                </td>
                                <td>
                                    <span class="badge-category">
                                        <?= htmlspecialchars($row['category']) ?>
                                    </span>
                                </td>
                                <td class="meta-text">
                                    <i class="far fa-eye" style="margin-right: 5px;"></i> 
                                    <?= isset($row['views']) ? number_format($row['views']) : '0' ?>
                                </td>
                                <td class="meta-text">
                                    <?= date('M d, Y', strtotime($row['created_at'])) ?>
                                </td>
                                <td style="text-align: right;">
                                    <a href="?delete_id=<?= $row['id'] ?>" 
                                       class="action-btn btn-delete"
                                       onclick="event.stopPropagation(); return confirm('Are you sure you want to delete this article?');">
                                        <i class="fas fa-trash-alt"></i> Delete
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 80px 40px; color: #9CA3AF;">
                                <div style="font-size: 56px; margin-bottom: 20px; color: #E5E7EB;"><i class="fas fa-book-open"></i></div>
                                <div style="font-size: 18px; font-weight: 700; color: #374151;">No articles found</div>
                                <div style="font-size: 15px; margin-top: 8px;">Get started by creating your first article.</div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>
</div>

<!-- Add Article Modal -->
<div id="addArticleModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <div class="modal-title">
                <i class="fas fa-plus-circle" style="color: #10B981;"></i>
                Add New Article
            </div>
            <button class="close-modal" onclick="closeModal()">&times;</button>
        </div>
        
        <form method="POST" action="" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add">
            
            <div class="form-group">
                <label class="form-label">Article Title</label>
                <input type="text" name="title" class="form-control" required placeholder="e.g. How to reset password">
            </div>

            <div class="form-group">
                <label class="form-label">Category</label>
                <select name="category" class="form-control" required>
                    <option value="">Select Category</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Article Image (Optional)</label>
                <input type="file" name="image" class="form-control" accept="image/*" onchange="previewAddImage(this)">
                <div id="add-image-preview" style="margin-top: 10px; display: none;">
                    <img id="add-image-preview-img" src="" style="max-width: 100%; max-height: 200px; border-radius: 8px; border: 1px solid #ddd;">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Content</label>
                <textarea name="content" class="form-control" required placeholder="Write article content here..."></textarea>
            </div>

            <button type="submit" class="btn-submit">Publish Article</button>
        </form>
    </div>
</div>


<!-- Edit Article Modal -->
<div id="editArticleModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <div class="modal-title">
                <i class="fas fa-edit" style="color: #3B82F6;"></i>
                Edit Article
            </div>
            <button class="close-modal" onclick="closeEditModal()">&times;</button>
        </div>

        <div class="modal-tabs">
            <div class="modal-tab active" onclick="switchTab('edit')">Edit</div>
            <div class="modal-tab" onclick="switchTab('preview')">Preview</div>
        </div>
        
        <form id="editArticleForm" onsubmit="event.preventDefault(); saveArticle();">
            <input type="hidden" id="edit_id" name="id">
            
            <div id="edit-mode">
                <div class="form-group">
                    <label class="form-label">Article Title</label>
                    <input type="text" id="edit_title" name="title" class="form-control" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Category</label>
                    <select id="edit_category" name="category" class="form-control" required>
                        <option value="">Select Category</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Current Image</label>
                    <div id="current-image-container" style="margin-bottom: 15px;">
                        <div id="image-wrapper" style="position: relative; display: inline-block;">
                            <img id="current-image" src="" alt="Article Image" style="max-width: 100%; max-height: 200px; border-radius: 8px; display: none; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                            <button type="button" id="remove-image-btn" onclick="removeImage()" title="Remove Image" 
                                    style="position: absolute; top: -10px; right: -10px; background: #EF4444; color: white; border: 2px solid white; border-radius: 50%; width: 28px; height: 28px; cursor: pointer; display: none; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.2); transition: all 0.2s;">
                                <i class="fas fa-times" style="font-size: 14px;"></i>
                            </button>
                        </div>
                        <p id="no-image-text" style="color: #6B7280; font-style: italic; display: none; padding: 10px; background: #F9FAFB; border-radius: 8px; border: 1px dashed #D1D5DB;">No image uploaded</p>
                        <input type="hidden" id="remove_image_flag" name="remove_image" value="0">
                    </div>
                    
                    <label class="form-label">Update Image (Optional)</label>
                    <input type="file" id="edit_image_input" name="image" class="form-control" accept="image/*" onchange="previewNewImage(this)">
                </div>

                <div class="form-group">
                    <label class="form-label">Content</label>
                    <textarea id="edit_content" name="content" class="form-control" required></textarea>
                </div>
            </div>

            <div id="preview-mode" class="preview-content">
                <!-- Preview content will be injected here -->
            </div>

            <div style="margin-top: 20px; text-align: right;">
                <button type="button" onclick="closeEditModal()" style="background: #E5E7EB; color: #374151; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; margin-right: 10px; cursor: pointer;">Cancel</button>
                <button type="submit" class="btn-submit" style="width: auto; display: inline-flex; align-items: center; justify-content: center;">
                    <span id="save-text">Save Changes</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script src="../js/admin.js"></script>
<script>
    // Add Modal Logic
    const addModal = document.getElementById('addArticleModal');
    
    function openModal() {
        addModal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function previewAddImage(input) {
        const previewDiv = document.getElementById('add-image-preview');
        const previewImg = document.getElementById('add-image-preview-img');
        
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                previewImg.src = e.target.result;
                previewDiv.style.display = 'block';
            }
            reader.readAsDataURL(input.files[0]);
        } else {
            previewDiv.style.display = 'none';
        }
    }

    function closeModal() {
        addModal.style.display = 'none';
        document.body.style.overflow = '';
        // Reset preview
        document.getElementById('add-image-preview').style.display = 'none';
        document.querySelector('#addArticleModal form').reset();
    }

    // Edit Modal Logic
    const editModal = document.getElementById('editArticleModal');
    const editForm = document.getElementById('editArticleForm');

    function openEditModal(id) {
        // Show loading state or reset form
        document.getElementById('edit_id').value = id;
        document.getElementById('edit_title').value = 'Loading...';
        document.getElementById('edit_content').value = 'Loading...';
        document.getElementById('current-image').style.display = 'none';
        document.getElementById('remove-image-btn').style.display = 'none';
        document.getElementById('no-image-text').style.display = 'none';
        document.getElementById('remove_image_flag').value = '0';
        document.getElementById('edit_image_input').value = ''; // Reset file input
        
        editModal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        switchTab('edit'); // Reset to edit tab

        // Fetch data
        const formData = new FormData();
        formData.append('action', 'fetch');
        formData.append('id', id);

        fetch('kb_ajax.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const article = data.data;
                document.getElementById('edit_title').value = article.title;
                document.getElementById('edit_category').value = article.category;
                document.getElementById('edit_content').value = article.content;
                
                const img = document.getElementById('current-image');
                const removeBtn = document.getElementById('remove-image-btn');
                const noImgText = document.getElementById('no-image-text');

                if (article.image_path) {
                    img.src = '../' + article.image_path;
                    img.style.display = 'block';
                    removeBtn.style.display = 'flex';
                    noImgText.style.display = 'none';
                } else {
                    img.style.display = 'none';
                    removeBtn.style.display = 'none';
                    noImgText.style.display = 'block';
                }
            } else {
                alert('Error fetching article: ' + data.message);
                closeEditModal();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while fetching the article.');
            closeEditModal();
        });
    }

    function removeImage() {
        if (confirm('Are you sure you want to remove the image? This will take effect when you save changes.')) {
            document.getElementById('current-image').style.display = 'none';
            document.getElementById('remove-image-btn').style.display = 'none';
            document.getElementById('no-image-text').style.display = 'block';
            document.getElementById('remove_image_flag').value = '1';
            document.getElementById('edit_image_input').value = ''; // Clear any new upload
        }
    }

    function previewNewImage(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const img = document.getElementById('current-image');
                const removeBtn = document.getElementById('remove-image-btn');
                const noImgText = document.getElementById('no-image-text');
                
                img.src = e.target.result;
                img.style.display = 'block';
                removeBtn.style.display = 'flex';
                noImgText.style.display = 'none';
                
                // Reset remove flag since we are uploading a new one
                document.getElementById('remove_image_flag').value = '0';
            }
            reader.readAsDataURL(input.files[0]);
        }
    }

    function closeEditModal() {
        editModal.style.display = 'none';
        document.body.style.overflow = '';
    }

    function switchTab(mode) {
        const editMode = document.getElementById('edit-mode');
        const previewMode = document.getElementById('preview-mode');
        const tabs = document.querySelectorAll('.modal-tab');
        
        tabs.forEach(tab => tab.classList.remove('active'));

        if (mode === 'edit') {
            editMode.style.display = 'block';
            previewMode.style.display = 'none';
            tabs[0].classList.add('active');
        } else {
            editMode.style.display = 'none';
            previewMode.style.display = 'block';
            tabs[1].classList.add('active');
            renderPreview();
        }
    }

    function renderPreview() {
        const content = document.getElementById('edit_content').value;
        const previewDiv = document.getElementById('preview-mode');
        const currentImage = document.getElementById('current-image');
        
        let html = '';
        
        // Add image to preview if exists
        if (currentImage.style.display !== 'none') {
            html += `<img src="${currentImage.src}" style="max-width: 100%; border-radius: 8px; margin-bottom: 20px;">`;
        }

        // Simple Markdown-like parsing
        html += content
            .replace(/\n/g, '<br>')
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>') // Bold
            .replace(/## (.*?)(<br>|$)/g, '<h2>$1</h2>') // H2
            .replace(/# (.*?)(<br>|$)/g, '<h1>$1</h1>') // H1
            .replace(/- (.*?)(<br>|$)/g, '<li>$1</li>'); // List items
            
        // Wrap lists (simple heuristic)
        if (html.includes('<li>')) {
            html = html.replace(/((<li>.*<\/li>)+)/g, '<ul>$1</ul>');
        }

        previewDiv.innerHTML = html || '<p style="color: #9CA3AF; font-style: italic;">No content to preview</p>';
    }

    function saveArticle() {
        const saveBtn = document.querySelector('#editArticleForm .btn-submit');
        const saveText = document.getElementById('save-text');
        const originalText = saveText.innerText;
        
        saveBtn.disabled = true;
        saveText.innerHTML = '<span class="spinner"></span> Saving...';

        const formData = new FormData(editForm);
        formData.append('action', 'update');

        fetch('kb_ajax.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Show success feedback
                saveText.innerText = 'Saved!';
                setTimeout(() => {
                    closeEditModal();
                    // Reload page to show changes
                    window.location.href = window.location.pathname + '?msg=updated'; 
                }, 1000);
            } else {
                alert('Error updating article: ' + data.message);
                saveBtn.disabled = false;
                saveText.innerText = originalText;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while saving.');
            saveBtn.disabled = false;
            saveText.innerText = originalText;
        });
    }

    // Close modals when clicking outside
    window.onclick = function(event) {
        if (event.target == addModal) {
            closeModal();
        }
        if (event.target == editModal) {
            closeEditModal();
        }
    }
</script>

</body>
</html>