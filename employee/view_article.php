<?php
require_once '../config/database.php';

// Protect page
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: employee_login.php");
    exit();
}

// Validate ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: knowledge_base.php");
    exit();
}

$article_id = (int)$_GET['id'];

// 1. Increment Views
$updateStmt = $conn->prepare("UPDATE knowledge_base SET views = views + 1 WHERE id = ?");
$updateStmt->bind_param("i", $article_id);
$updateStmt->execute();

// 2. Fetch Article
$stmt = $conn->prepare("SELECT * FROM knowledge_base WHERE id = ?");
$stmt->bind_param("i", $article_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: knowledge_base.php");
    exit();
}

$article = $result->fetch_assoc();

function renderArticleContent($text) {
    // 1. Escape HTML for safety
    $text = htmlspecialchars($text);
    
    // 2. Bold (**text**)
    $text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);
    
    // 3. Process lines for Headers and Lists
    $lines = explode("\n", $text);
    $output = '';
    $inList = false;
    
    foreach ($lines as $line) {
        $trimmed = trim($line);
        
        if (empty($trimmed)) {
            if ($inList) {
                $output .= "</ul>";
                $inList = false;
            }
            // Optional: Add <br> for empty lines if you want to preserve spacing
            // $output .= "<br>"; 
            continue;
        }
        
        // H1 (# Title)
        if (strpos($trimmed, '# ') === 0) {
            if ($inList) { $output .= "</ul>"; $inList = false; }
            $output .= "<h1>" . substr($trimmed, 2) . "</h1>";
        }
        // H2 (## Title)
        elseif (strpos($trimmed, '## ') === 0) {
            if ($inList) { $output .= "</ul>"; $inList = false; }
            $output .= "<h2>" . substr($trimmed, 3) . "</h2>";
        }
        // List Item (- Item)
        elseif (strpos($trimmed, '- ') === 0) {
            if (!$inList) {
                $output .= "<ul>";
                $inList = true;
            }
            $output .= "<li>" . substr($trimmed, 2) . "</li>";
        }
        // Normal Text
        else {
            if ($inList) { $output .= "</ul>"; $inList = false; }
            $output .= "<p>" . $trimmed . "</p>";
        }
    }
    
    if ($inList) {
        $output .= "</ul>";
    }
    
    return $output;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($article['title']) ?> | Leads Agri Helpdesk</title>
    <link rel="stylesheet" href="../css/employee-dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #F9FAFB;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }

        .article-container {
            max-width: 900px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #6B7280;
            text-decoration: none;
            font-weight: 500;
            margin-bottom: 30px;
            transition: color 0.2s;
        }

        .back-link:hover {
            color: #1B5E20;
        }

        .article-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            border: 1px solid #E5E7EB;
            overflow: hidden;
        }

        .article-header {
            padding: 40px 40px 30px;
            border-bottom: 1px solid #F3F4F6;
            background-color: #FFFFFF;
        }

        .article-meta {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .category-badge {
            background-color: #E8F5E9;
            color: #1B5E20;
            padding: 6px 12px;
            border-radius: 100px;
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .meta-info {
            color: #9CA3AF;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .article-title {
            font-size: 32px;
            font-weight: 800;
            color: #111827;
            line-height: 1.3;
            margin: 0;
        }

        .article-content {
            padding: 40px;
            color: #374151;
            line-height: 1.8;
            font-size: 16px;
        }

        /* Typography for content */
        .article-content h1, 
        .article-content h2, 
        .article-content h3 {
            color: #111827;
            margin-top: 1.5em;
            margin-bottom: 0.75em;
            font-weight: 700;
        }

        .article-content h2 { font-size: 24px; }
        .article-content h3 { font-size: 20px; }

        .article-content p {
            margin-bottom: 1.5em;
        }

        .article-content ul, 
        .article-content ol {
            margin-bottom: 1.5em;
            padding-left: 1.5em;
        }

        .article-content li {
            margin-bottom: 0.5em;
        }

        .article-content code {
            background-color: #F3F4F6;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 0.9em;
            color: #C026D3;
        }

        .article-content pre {
            background-color: #1F2937;
            color: #F9FAFB;
            padding: 20px;
            border-radius: 8px;
            overflow-x: auto;
            margin-bottom: 1.5em;
        }

        .article-content blockquote {
            border-left: 4px solid #1B5E20;
            background-color: #F9FAFB;
            padding: 16px 20px;
            margin: 0 0 1.5em 0;
            font-style: italic;
            color: #4B5563;
        }

        .article-content img {
            max-width: 100%;
            height: auto;
            border-radius: 8px;
            margin: 1em 0;
        }

        @media (max-width: 768px) {
            .article-header, .article-content {
                padding: 24px;
            }
            .article-title {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>

    <?php include '../includes/employee_navbar.php'; ?>

    <div class="article-container">
        
        <a href="knowledge_base.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Knowledge Base
        </a>

        <article class="article-card">
            <div class="article-header">
                <div class="article-meta">
                    <span class="category-badge">
                        <?= htmlspecialchars($article['category']) ?>
                    </span>
                    <span class="meta-info">
                        <i class="far fa-calendar"></i>
                        <?= date('M d, Y', strtotime($article['created_at'])) ?>
                    </span>
                    <span class="meta-info">
                        <i class="far fa-eye"></i>
                        <?= number_format($article['views']) ?> views
                    </span>
                </div>
                <h1 class="article-title">
                    <?= htmlspecialchars($article['title']) ?>
                </h1>
            </div>

            <div class="article-content">
                <?php if (!empty($article['image_path'])): ?>
                    <div style="margin-bottom: 30px;">
                        <img src="../<?= htmlspecialchars($article['image_path']) ?>" alt="<?= htmlspecialchars($article['title']) ?>" style="width: 100%; max-height: 500px; object-fit: cover; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
                    </div>
                <?php endif; ?>
                <?= renderArticleContent($article['content']) ?>
            </div>
        </article>

    </div>

    <script src="../js/employee-dashboard.js"></script>
</body>
</html>
