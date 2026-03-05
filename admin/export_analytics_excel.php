<?php
require_once '../config/database.php';

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

// Handle null values (SUM returns null if no rows match, though COUNT(*) usually prevents empty result set for the group, but safe to default)
$received = $metrics['received'] ?? 0;
$resolved = $metrics['resolved'] ?? 0;
$closed = $metrics['closed'] ?? 0;

// Calculate Resolution Rate
$total_tickets = $received;
$resolved_closed = $resolved + $closed;
$resolution_rate = $total_tickets > 0 ? round(($resolved_closed / $total_tickets) * 100, 2) : 0;

// 2. Generate Excel XML (SpreadsheetML)
// Clear any previous output to prevent file corruption
if (ob_get_level()) ob_end_clean();

header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="analytics_' . $selected_month . '.xls"');
header('Cache-Control: max-age=0');

echo '<?xml version="1.0"?>';
echo '<?mso-application progid="Excel.Sheet"?>';
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
 <Worksheet ss:Name="Analytics_<?= $selected_month ?>">
  <Table>
   <Row>
    <Cell><Data ss:Type="String">Month</Data></Cell>
    <Cell><Data ss:Type="String">Received</Data></Cell>
    <Cell><Data ss:Type="String">Resolved</Data></Cell>
    <Cell><Data ss:Type="String">Closed</Data></Cell>
    <Cell><Data ss:Type="String">Resolution Rate (%)</Data></Cell>
   </Row>
   <Row>
    <Cell><Data ss:Type="String"><?= $selected_month ?></Data></Cell>
    <Cell><Data ss:Type="Number"><?= $received ?></Data></Cell>
    <Cell><Data ss:Type="Number"><?= $resolved ?></Data></Cell>
    <Cell><Data ss:Type="Number"><?= $closed ?></Data></Cell>
    <Cell><Data ss:Type="Number"><?= $resolution_rate ?></Data></Cell>
   </Row>
  </Table>
 </Worksheet>
</Workbook>
