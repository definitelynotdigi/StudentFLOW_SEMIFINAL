<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    die("Access Denied.");
}

$type = $_GET['type'] ?? 'grades';
require_once 'database.php';

// Fetch complete user details including registered name fields
$stmt = $pdo->prepare("SELECT username, email, first_name, last_name FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$userProfile = $stmt->fetch();

// Build full registered name with fallback to username
$display_name = trim(($userProfile['first_name'] ?? '') . ' ' . ($userProfile['last_name'] ?? ''));
if (empty($display_name)) {
    $display_name = $userProfile['username'] ?? '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Official Document Output - Print Manifest</title>
    <!-- Google Fonts & Font Awesome -->
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; font-family: 'Inter', sans-serif; color: #212529; }
        .document-container {
            background: #ffffff;
            max-width: 800px;
            border: 2px solid #800000;
            padding: 45px;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
            margin: auto;
        }
        .print-header { 
            border-bottom: 2px solid #800000; 
            padding-bottom: 20px; 
            margin-bottom: 30px; 
        }
        .college-title {
            font-family: 'Cinzel', serif;
            color: #800000;
        }
        @media print { 
            body { background: #ffffff; }
            .no-print { display: none !important; } 
            .document-container { border: 1px solid #000; box-shadow: none; padding: 20px; }
        }
    </style>
</head>
<body class="p-4 p-md-5" onload="window.print()">

<div class="container text-center no-print mb-4">
    <button onclick="window.print()" class="btn btn-danger px-4 py-2 rounded-pill fw-bold">
        <i class="fa-solid fa-print me-2"></i>Execute Printer Routine
    </button>
    <hr class="w-50 mx-auto mt-4">
</div>

<div class="document-container">
    <div class="print-header text-center">
        <h2 class="fw-bold text-uppercase college-title mb-1">Global Reciprocal Colleges</h2>
        <p class="text-muted small mb-1"><i class="fa-solid fa-location-dot me-1"></i>454 Rizal Ave Ext Cor 9th Ave, Grace Park, Caloocan City</p>
        <span class="badge bg-dark mt-1">OFFICIAL STUDENT PORTAL</span>
    </div>

    <div class="row mb-4 bg-light p-3 rounded small">
        <div class="col-6"><strong>Account Profile Identification:</strong> <?= htmlspecialchars($display_name); ?></div>
        <div class="col-6 text-end"><strong>Date Generated:</strong> <?= date('F d, Y'); ?></div>
    </div>

    <?php if ($type === 'grades'): ?>
        <h4 class="text-center fw-bold text-decoration-underline mb-4 text-uppercase">Official Certificate of Academic Grades</h4>
        <table class="table table-bordered align-middle">
            <thead class="table-dark">
                <tr><th>Subject Code</th><th>Subject Description Title</th><th>Final Grade Mark</th></tr>
            </thead>
            <tbody>
                <?php
                $stmt = $pdo->prepare("SELECT * FROM academic_records WHERE student_id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $grades = $stmt->fetchAll();

                $total_grade = 0;
                $subject_count = count($grades);

                if ($subject_count > 0):
                    foreach ($grades as $r):
                        $total_grade += floatval($r['grade']);
                ?>
                        <tr>
                            <td><span class='fw-semibold'><?= htmlspecialchars($r['subject_code']) ?></span></td>
                            <td><?= htmlspecialchars($r['subject_title']) ?></td>
                            <td class='fw-bold text-success'><?= number_format($r['grade'], 2) ?></td>
                        </tr>
                <?php 
                    endforeach;
                else: 
                ?>
                    <tr><td colspan="3" class="text-center text-muted py-3">No academic records found.</td></tr>
                <?php endif; ?>
            </tbody>
            <?php if ($subject_count > 0): ?>
            <tfoot class="table-light border-top">
                <tr class="fw-bold">
                    <td colspan="2" class="text-end">General Weighted Average (GWA):</td>
                    <td class="text-success fs-6"><?= number_format($total_grade / $subject_count, 2) ?></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>

    <?php elseif ($type === 'ledger'): ?>
        <h4 class="text-center fw-bold text-decoration-underline mb-4 text-uppercase">Official Statement of Accounts Balance Ledger</h4>
        <table class="table table-bordered align-middle">
            <thead class="table-dark">
                <tr><th>Transaction Details Description</th><th>Accounting Action</th><th>Amount Metric</th></tr>
            </thead>
            <tbody>
                <?php
                $stmt = $pdo->prepare("SELECT * FROM financial_ledgers WHERE student_id = ? ORDER BY transaction_date ASC, id ASC");
                $stmt->execute([$_SESSION['user_id']]);
                $tot = 0;
                while($r = $stmt->fetch()) {
                    $tot += ($r['transaction_type'] === 'Charge') ? $r['amount'] : -$r['amount'];
                    echo "<tr><td>".htmlspecialchars($r['description'])."</td><td><span class='badge bg-secondary'>".htmlspecialchars($r['transaction_type'])."</span></td><td class='fw-semibold'>₱" . number_format($r['amount'], 2) . "</td></tr>";
                }
                ?>
                <tr class="table-warning fw-bold">
                    <td colspan="2">Net Outstanding Accounts Payable Balance Due:</td>
                    <td class="text-danger fs-6">₱<?= number_format($tot, 2); ?></td>
                </tr>
            </tbody>
        </table>

    <?php else: ?>
        <h4 class="text-center fw-bold text-decoration-underline mb-4 text-uppercase">Official Enrollment Status Verification Form</h4>
        <p class="lh-lg text-center fs-5 my-5">
            This certificate confirms that <strong><?= htmlspecialchars($display_name); ?></strong> is officially enrolled at <strong>Global Reciprocal Colleges</strong> for the current academic year.
        </p>
        <div class="mt-5 pt-5 text-center mx-auto" style="border-top: 1px dashed #000; width: 280px;">
            <small class="fw-bold d-block text-uppercase">Office of the College Registrar</small>
            <small class="text-muted">Global Reciprocal Colleges</small>
        </div>
    <?php endif; ?>
</div>

</body>
</html>