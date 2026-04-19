<?php
// expenses.php - Expense Tracking Page
require_once 'auth_check.php';
require_once 'includes/commerce.php';
require_once 'includes/brand.php';

$context = commerce_get_tenant_context();
$conn = $context['conn'];
$prefix = $context['prefix'];
commerce_ensure_tables($conn, $prefix);

// Handle Expense Creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_expense') {
    $date = $_POST['expense_date'] ?? date('Y-m-d');
    $cat = $_POST['category'] ?? 'General';
    $desc = $_POST['description'] ?? '';
    $amount = (float)($_POST['amount'] ?? 0);
    $pmode = $_POST['payment_mode'] ?? 'Cash';

    $stmt = $conn->prepare("INSERT INTO {$prefix}expenses (expense_date, category, description, amount, payment_mode) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$date, $cat, $desc, $amount, $pmode]);
    header("Location: expenses.php?success=1");
    exit;
}

// Fetch expenses
$stmt = $conn->query("SELECT * FROM {$prefix}expenses ORDER BY expense_date DESC");
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalExpense = array_sum(array_column($expenses, 'amount'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(brand_page_title('Expenses')) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link rel="shortcut icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <style>
        .module-hero {
            background: linear-gradient(135deg, #4c1d95 0%, #6d28d9 52%, #8b5cf6 100%);
            color: #fff;
            border-radius: 24px;
            padding: 28px;
            box-shadow: var(--shadow-lg);
            margin-bottom: 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .hero-title { font-size: 30px; margin-bottom: 6px; color: #fff; }
        .hero-stat { background: rgba(255, 255, 255, 0.15); padding: 20px 30px; border-radius: 20px; border: 1px solid rgba(255,255,255,0.2); }
        .hero-stat span { display: block; font-size: 13px; opacity: 0.8; margin-bottom: 4px; }
        .hero-stat strong { font-size: 28px; }

        .module-grid { display: grid; grid-template-columns: 350px 1fr; gap: 30px; }
        .form-card { background: var(--surface); border-radius: 22px; padding: 24px; box-shadow: var(--shadow-md); align-self: start; }
        .table-panel { background: var(--surface); border-radius: 22px; padding: 24px; box-shadow: var(--shadow-md); }
        .panel-title { font-size: 18px; font-weight: 700; margin-bottom: 20px; }
        @media (max-width: 1000px) { .module-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main-content">
        <header class="topbar">
            <div class="breadcrumb">Finance / <span class="current">Expenses</span></div>
            <div class="topbar-right">
                <div class="profile-pill">
                    <img src="/images/admin.jpg" alt="Admin">
                    <span class="name"><?= htmlspecialchars($_SESSION["username"]) ?></span>
                </div>
            </div>
        </header>
        <div class="content-scroll">
            <section class="module-hero">
                <div>
                    <h1 class="hero-title">Expense Tracking</h1>
                    <p style="opacity: 0.8;">Record and categorize all your non-inventory business costs.</p>
                </div>
                <div style="display: flex; flex-direction: column; gap: 15px; align-items: flex-end;">
                    <a href="api/export.php?type=expenses" class="btn-secondary" style="background: rgba(255,255,255,0.1); border-color: rgba(255,255,255,0.2); color: #fff;"><i class="fa-solid fa-file-export"></i> Export CSV</a>
                    <div class="hero-stat">
                        <span>Total Operational Expense</span>
                        <strong>₹<?= number_format($totalExpense, 2) ?></strong>
                    </div>
                </div>
            </section>

            <div class="module-grid">
                <!-- Add Expense Form -->
                <div class="form-card">
                    <div class="panel-title">Add New Expense</div>
                    <form method="POST">
                        <input type="hidden" name="action" value="save_expense">
                        <div class="form-group">
                            <label class="form-label">Date</label>
                            <input type="date" name="expense_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Category</label>
                            <select name="category" class="form-control" required>
                                <option value="Rent">Rent</option>
                                <option value="Electricity">Electricity</option>
                                <option value="Salary">Salary</option>
                                <option value="Marketing">Marketing</option>
                                <option value="Office Supplies">Office Supplies</option>
                                <option value="Internet">Internet/Phone</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Amount (₹)</label>
                            <input type="number" step="0.01" name="amount" class="form-control" placeholder="0.00" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Payment Mode</label>
                            <select name="payment_mode" class="form-control">
                                <option value="Cash">Cash</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="UPI">UPI/Digital</option>
                                <option value="Cheque">Cheque</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Description (Optional)</label>
                            <textarea name="description" class="form-control" rows="3"></textarea>
                        </div>
                        <button type="submit" class="btn-primary" style="width: 100%; margin-top: 10px;">Record Expense</button>
                    </form>
                </div>

                <!-- Expense Listing -->
                <div class="table-panel">
                    <div class="panel-title">Expense Log</div>
                    <div class="table-responsive">
                        <table class="crm-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Category</th>
                                    <th>Description</th>
                                    <th>Mode</th>
                                    <th>Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($expenses)): ?>
                                    <tr><td colspan="5" style="text-align:center; padding: 40px; color: var(--text-muted);">No expenses recorded yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($expenses as $e): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($e['expense_date']) ?></td>
                                            <td><span class="badge" style="background: rgba(123, 94, 240, 0.1); color: var(--primary); padding: 5px 10px; border-radius: 12px; font-weight: 700;"><?= htmlspecialchars($e['category']) ?></span></td>
                                            <td><?= htmlspecialchars($e['description'] ?: '-') ?></td>
                                            <td><?= htmlspecialchars($e['payment_mode']) ?></td>
                                            <td class="text-bold" style="color: #ef4444;">₹<?= number_format($e['amount'], 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<script>
    function toggleSidebar() { document.getElementById('sidebar').classList.toggle('sidebar-collapsed'); }
</script>
</body>
</html>
