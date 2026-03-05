<?php
require_once '../config/database.php';

// Define font path to avoid errors
define('FPDF_FONTPATH', dirname(__DIR__) . '/vendor/fpdf/font/');
require_once '../vendor/fpdf/fpdf.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("Access Denied");
}

$selected_month = $_GET['month'] ?? date('Y-m');

// 1. Get Metrics
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as received,
        SUM(CASE WHEN status = 'Resolved' THEN 1 ELSE 0 END) as resolved,
        SUM(CASE WHEN status = 'Closed' THEN 1 ELSE 0 END) as closed
    FROM employee_tickets 
    WHERE DATE_FORMAT(created_at, '%Y-%m') = ?
");

if (!$stmt) {
    die("Query preparation failed: " . $conn->error);
}

$stmt->bind_param("s", $selected_month);
$stmt->execute();
$result = $stmt->get_result();
$metrics = $result->fetch_assoc();

// Handle null values
$received = $metrics['received'] ?? 0;
$resolved = $metrics['resolved'] ?? 0;
$closed = $metrics['closed'] ?? 0;

// Calculate Resolution Rate
$total_tickets = $received;
$resolved_closed = $resolved + $closed;
$resolution_rate = $total_tickets > 0 ? round(($resolved_closed / $total_tickets) * 100, 2) : 0;

// 2. Generate PDF
// Clear any previous output
if (ob_get_level()) ob_end_clean();

$pdf = new FPDF();
$pdf->AddPage();
$pdf->SetFont('Helvetica', 'B', 16);
$pdf->Cell(0, 10, 'Monthly Ticket Analytics Report', 0, 1, 'C');
$pdf->Ln(10);

$pdf->SetFont('Helvetica', '', 12);
$pdf->Cell(50, 10, 'Selected Month:', 0, 0);
$pdf->Cell(0, 10, $selected_month, 0, 1);

$pdf->Cell(50, 10, 'Generated Date:', 0, 0);
$pdf->Cell(0, 10, date('Y-m-d H:i:s'), 0, 1);
$pdf->Ln(10);

// Table Header
$pdf->SetFillColor(200, 220, 255);
$pdf->SetFont('Helvetica', 'B', 12);
$pdf->Cell(40, 10, 'Received', 1, 0, 'C', true);
$pdf->Cell(40, 10, 'Resolved', 1, 0, 'C', true);
$pdf->Cell(40, 10, 'Closed', 1, 0, 'C', true);
$pdf->Cell(50, 10, 'Resolution Rate', 1, 1, 'C', true);

// Table Data
$pdf->SetFont('Helvetica', '', 12);
$pdf->Cell(40, 10, $received, 1, 0, 'C');
$pdf->Cell(40, 10, $resolved, 1, 0, 'C');
$pdf->Cell(40, 10, $closed, 1, 0, 'C');
$pdf->Cell(50, 10, $resolution_rate . '%', 1, 1, 'C');

$pdf->Output('D', 'analytics_' . $selected_month . '.pdf');
