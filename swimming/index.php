<?php
// ============================================================
// SUVA HOTEL - SWIMMING MANAGEMENT DASHBOARD
// PostgreSQL Version
// File: /suva_hotel/swimming/index.php
// ============================================================

session_start();

require_once __DIR__ . '/../config/database.php';

// ============================================================
// AUTHENTICATION
// ============================================================

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// ============================================================
// HELPER
// ============================================================

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money($value)
{
    return 'Rs. ' . number_format((float)$value, 2);
}

// ============================================================
// TODAY
// ============================================================

$today = date('Y-m-d');

// ============================================================
// TODAY'S SWIMMING STATISTICS
// ============================================================

$todayEntries = 0;
$todayVisitors = 0;
$todayAdults = 0;
$todayChildren = 0;
$todayRevenue = 0;
$activeSwimmers = 0;

// ============================================================
// TOTAL STATISTICS
// ============================================================

$totalEntries = 0;
$totalRevenue = 0;
$totalVisitors = 0;

// ============================================================
// TODAY DATA
// ============================================================

$todayResult = pg_query_params(
    $pgConnection,
    "
    SELECT
        COUNT(*) AS entries,
        COALESCE(SUM(adults), 0) AS adults,
        COALESCE(SUM(children), 0) AS children,
        COALESCE(SUM(adults + children), 0) AS visitors,
        COALESCE(SUM(total_amount), 0) AS revenue
    FROM swimming
    WHERE visit_date = $1::date
    AND LOWER(COALESCE(status, '')) <> 'cancelled'
    ",
    [$today]
);

if ($todayResult !== false) {

    $todayData = pg_fetch_assoc($todayResult);

    if ($todayData) {

        $todayEntries = (int)($todayData['entries'] ?? 0);
        $todayAdults = (int)($todayData['adults'] ?? 0);
        $todayChildren = (int)($todayData['children'] ?? 0);
        $todayVisitors = (int)($todayData['visitors'] ?? 0);
        $todayRevenue = (float)($todayData['revenue'] ?? 0);

    }
}

// ============================================================
// ACTIVE SWIMMERS
// ============================================================

$activeResult = pg_query(
    $pgConnection,
    "
    SELECT COUNT(*)
    FROM swimming
    WHERE LOWER(COALESCE(status, '')) = 'active'
    "
);

if ($activeResult !== false) {

    $activeRow = pg_fetch_row($activeResult);

    if ($activeRow) {
        $activeSwimmers = (int)$activeRow[0];
    }
}

// ============================================================
// ALL-TIME STATISTICS
// ============================================================

$totalResult = pg_query(
    $pgConnection,
    "
    SELECT
        COUNT(*) AS entries,
        COALESCE(SUM(adults + children), 0) AS visitors,
        COALESCE(SUM(total_amount), 0) AS revenue
    FROM swimming
    WHERE LOWER(COALESCE(status, '')) <> 'cancelled'
    "
);

if ($totalResult !== false) {

    $totalData = pg_fetch_assoc($totalResult);

    if ($totalData) {

        $totalEntries = (int)($totalData['entries'] ?? 0);
        $totalVisitors = (int)($totalData['visitors'] ?? 0);
        $totalRevenue = (float)($totalData['revenue'] ?? 0);

    }
}

// ============================================================
// RECENT SWIMMING RECORDS
// ============================================================

$recentRecords = [];

$recentResult = pg_query(
    $pgConnection,
    "
    SELECT
        s.id,
        s.visitor_name,
        s.visitor_type,
        s.visit_date,
        s.entry_time,
        s.exit_time,
        s.adults,
        s.children,
        s.total_amount,
        s.status,
        g.full_name AS guest_name
    FROM swimming s
    LEFT JOIN guests g
        ON s.guest_id = g.id
    ORDER BY s.id DESC
    LIMIT 8
    "
);

if ($recentResult !== false) {

    while ($row = pg_fetch_assoc($recentResult)) {
        $recentRecords[] = $row;
    }
}

// ============================================================
// USER
// ============================================================

$userName =
    $_SESSION['username']
    ?? $_SESSION['name']
    ?? 'Administrator';

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Suva Hotel - Swimming Management</title>

    <style>

        /* =====================================================
           RESET
        ===================================================== */

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family:
                "Segoe UI",
                Arial,
                Helvetica,
                sans-serif;

            background: #f1f5f9;
            color: #1e293b;
        }

        a {
            text-decoration: none;
        }

        /* =====================================================
           MAIN CONTAINER
        ===================================================== */

        .container {
            width: 100%;
            max-width: 1450px;
            margin: auto;
            padding: 25px;
        }

        /* =====================================================
           HEADER
        ===================================================== */

        .header {
            background:
                linear-gradient(
                    135deg,
                    #0369a1,
                    #0284c7,
                    #06b6d4
                );

            color: white;

            padding: 28px 30px;

            border-radius: 20px;

            margin-bottom: 25px;

            box-shadow:
                0 12px 30px rgba(2, 132, 199, .20);
        }

        .header-top {
            display: flex;
            align-items: center;
            justify-content: space-between;

            gap: 20px;

            flex-wrap: wrap;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .brand-icon {
            width: 62px;
            height: 62px;

            background: rgba(255,255,255,.18);

            border-radius: 17px;

            display: flex;
            align-items: center;
            justify-content: center;

            font-size: 34px;

            backdrop-filter: blur(5px);
        }

        .brand h1 {
            font-size: 29px;
            font-weight: 800;
        }

        .brand p {
            margin-top: 5px;
            font-size: 14px;
            opacity: .85;
        }

        .user-box {
            text-align: right;
            font-size: 13px;
            opacity: .9;
        }

        .user-box strong {
            font-size: 15px;
        }

        /* =====================================================
           NAVIGATION
        ===================================================== */

        .navigation {
            margin-top: 25px;

            display: flex;
            gap: 10px;

            flex-wrap: wrap;
        }

        .nav-btn {
            padding: 11px 16px;

            border-radius: 10px;

            background: rgba(255,255,255,.15);

            color: white;

            font-size: 13px;
            font-weight: 700;

            transition: .2s;
        }

        .nav-btn:hover {
            background: white;
            color: #0369a1;
            transform: translateY(-2px);
        }

        /* =====================================================
           QUICK ACTIONS
        ===================================================== */

        .quick-actions {
            display: grid;

            grid-template-columns:
                repeat(4, minmax(0, 1fr));

            gap: 15px;

            margin-bottom: 25px;
        }

        .quick-action {
            background: white;

            border-radius: 16px;

            padding: 20px;

            display: flex;
            align-items: center;

            gap: 15px;

            color: #1e293b;

            border: 1px solid #e2e8f0;

            box-shadow:
                0 5px 18px rgba(15,23,42,.06);

            transition: .2s;
        }

        .quick-action:hover {
            transform: translateY(-3px);

            box-shadow:
                0 10px 25px rgba(15,23,42,.10);
        }

        .action-icon {
            width: 48px;
            height: 48px;

            border-radius: 13px;

            display: flex;
            align-items: center;
            justify-content: center;

            font-size: 24px;

            background: #e0f2fe;
        }

        .action-text strong {
            display: block;
            font-size: 15px;
        }

        .action-text span {
            display: block;
            margin-top: 4px;

            font-size: 12px;
            color: #64748b;
        }

        /* =====================================================
           STATISTICS
        ===================================================== */

        .stats-grid {
            display: grid;

            grid-template-columns:
                repeat(4, minmax(0, 1fr));

            gap: 18px;

            margin-bottom: 25px;
        }

        .stat-card {
            background: white;

            border-radius: 17px;

            padding: 22px;

            border: 1px solid #e2e8f0;

            box-shadow:
                0 6px 20px rgba(15,23,42,.06);

            position: relative;

            overflow: hidden;
        }

        .stat-card::after {
            content: "";

            position: absolute;

            width: 100px;
            height: 100px;

            border-radius: 50%;

            right: -35px;
            top: -35px;

            background: rgba(14,165,233,.07);
        }

        .stat-icon {
            font-size: 25px;
        }

        .stat-title {
            margin-top: 12px;

            color: #64748b;

            font-size: 12px;

            font-weight: 800;

            text-transform: uppercase;

            letter-spacing: .5px;
        }

        .stat-value {
            margin-top: 6px;

            font-size: 27px;

            font-weight: 900;

            color: #0f172a;
        }

        .stat-description {
            margin-top: 6px;

            color: #94a3b8;

            font-size: 12px;
        }

        .blue .stat-value {
            color: #0284c7;
        }

        .green .stat-value {
            color: #059669;
        }

        .purple .stat-value {
            color: #7c3aed;
        }

        .orange .stat-value {
            color: #ea580c;
        }

        /* =====================================================
           CONTENT GRID
        ===================================================== */

        .content-grid {
            display: grid;

            grid-template-columns:
                minmax(0, 1.5fr)
                minmax(300px, .8fr);

            gap: 25px;

            margin-bottom: 25px;
        }

        .panel {
            background: white;

            border-radius: 17px;

            padding: 23px;

            border: 1px solid #e2e8f0;

            box-shadow:
                0 6px 20px rgba(15,23,42,.06);
        }

        .panel-header {
            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 15px;

            margin-bottom: 20px;
        }

        .panel-title {
            font-size: 19px;
            font-weight: 800;
        }

        .panel-subtitle {
            color: #64748b;

            font-size: 12px;

            margin-top: 4px;
        }

        .view-all {
            background: #e0f2fe;

            color: #0369a1;

            padding: 8px 12px;

            border-radius: 9px;

            font-size: 12px;

            font-weight: 800;
        }

        .view-all:hover {
            background: #bae6fd;
        }

        /* =====================================================
           TABLE
        ===================================================== */

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;

            border-collapse: collapse;

            min-width: 700px;
        }

        th {
            background: #f8fafc;

            color: #64748b;

            font-size: 11px;

            text-transform: uppercase;

            letter-spacing: .5px;

            text-align: left;

            padding: 12px;

            border-bottom: 1px solid #e2e8f0;
        }

        td {
            padding: 13px 12px;

            border-bottom: 1px solid #f1f5f9;

            font-size: 13px;
        }

        tbody tr:hover {
            background: #f8fafc;
        }

        .visitor-name {
            font-weight: 700;
        }

        .visitor-sub {
            margin-top: 3px;

            color: #94a3b8;

            font-size: 11px;
        }

        .amount {
            font-weight: 800;
        }

        /* =====================================================
           STATUS
        ===================================================== */

        .status {
            display: inline-block;

            padding: 5px 9px;

            border-radius: 50px;

            font-size: 10px;

            font-weight: 800;

            text-transform: uppercase;
        }

        .status-active {
            background: #dcfce7;
            color: #15803d;
        }

        .status-completed {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .status-cancelled {
            background: #fee2e2;
            color: #b91c1c;
        }

        .status-default {
            background: #f1f5f9;
            color: #475569;
        }

        /* =====================================================
           SUMMARY PANEL
        ===================================================== */

        .summary-list {
            display: flex;

            flex-direction: column;

            gap: 13px;
        }

        .summary-row {
            display: flex;

            justify-content: space-between;

            align-items: center;

            padding: 14px;

            background: #f8fafc;

            border-radius: 11px;
        }

        .summary-label {
            color: #64748b;

            font-size: 12px;

            font-weight: 700;
        }

        .summary-value {
            font-size: 14px;

            font-weight: 900;
        }

        /* =====================================================
           RATE CARD
        ===================================================== */

        .rate-card {
            margin-top: 20px;

            padding: 18px;

            border-radius: 13px;

            background:
                linear-gradient(
                    135deg,
                    #ecfeff,
                    #f0f9ff
                );

            border: 1px solid #bae6fd;
        }

        .rate-title {
            font-size: 14px;

            font-weight: 800;

            color: #0369a1;

            margin-bottom: 12px;
        }

        .rate-row {
            display: flex;

            justify-content: space-between;

            padding: 8px 0;

            font-size: 13px;

            border-bottom: 1px solid rgba(14,116,144,.10);
        }

        .rate-row:last-child {
            border-bottom: none;
        }

        .rate-price {
            font-weight: 900;

            color: #0369a1;
        }

        /* =====================================================
           EMPTY STATE
        ===================================================== */

        .empty {
            padding: 45px 20px;

            text-align: center;

            color: #94a3b8;
        }

        .empty-icon {
            font-size: 40px;
            margin-bottom: 10px;
        }

        .empty strong {
            display: block;

            color: #64748b;

            margin-bottom: 5px;
        }

        /* =====================================================
           FOOTER
        ===================================================== */

        .footer {
            text-align: center;

            padding: 20px;

            color: #94a3b8;

            font-size: 12px;
        }

        /* =====================================================
           RESPONSIVE
        ===================================================== */

        @media (max-width: 1100px) {

            .stats-grid {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));
            }

            .quick-actions {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));
            }

            .content-grid {
                grid-template-columns: 1fr;
            }

        }

        @media (max-width: 650px) {

            .container {
                padding: 12px;
            }

            .header {
                padding: 20px;
            }

            .brand h1 {
                font-size: 23px;
            }

            .user-box {
                text-align: left;
            }

            .stats-grid,
            .quick-actions {
                grid-template-columns: 1fr;
            }

            .panel {
                padding: 17px;
            }

        }

        /* =====================================================
           PRINT
        ===================================================== */

        @media print {

            body {
                background: white;
            }

            .container {
                max-width: none;
                padding: 0;
            }

            .navigation,
            .quick-actions,
            .view-all {
                display: none !important;
            }

            .header {
                background: white !important;

                color: black !important;

                box-shadow: none;

                border: 2px solid #111827;
            }

            .panel,
            .stat-card {
                box-shadow: none;

                break-inside: avoid;
            }

        }

    </style>

</head>

<body>

<div class="container">

    <!-- ======================================================
         HEADER
    ======================================================= -->

    <div class="header">

        <div class="header-top">

            <div class="brand">

                <div class="brand-icon">
                    🏊
                </div>

                <div>

                    <h1>
                        Swimming Management
                    </h1>

                    <p>
                        Suva Hotel Swimming Pool
                    </p>

                </div>

            </div>


            <div class="user-box">

                Logged in as:

                <strong>
                    <?= e($userName) ?>
                </strong>

                <br>

                <?= date('l, d F Y') ?>

            </div>

        </div>


        <div class="navigation">

            <a
                href="../dashboard.php"
                class="nav-btn"
            >
                🏠 Main Dashboard
            </a>

            <a
                href="view.php"
                class="nav-btn"
            >
                📋 All Swimming Records
            </a>

            <a
                href="add.php"
                class="nav-btn"
            >
                ➕ Add Swimming Entry
            </a>

            <a
                href="bill.php"
                class="nav-btn"
            >
                💳 Swimming Bills
            </a>

        </div>

    </div>


    <!-- ======================================================
         QUICK ACTIONS
    ======================================================= -->

    <div class="quick-actions">

        <a
            href="add.php"
            class="quick-action"
        >

            <div class="action-icon">
                ➕
            </div>

            <div class="action-text">

                <strong>
                    New Entry
                </strong>

                <span>
                    Register swimming visitor
                </span>

            </div>

        </a>


        <a
            href="view.php"
            class="quick-action"
        >

            <div class="action-icon">
                📋
            </div>

            <div class="action-text">

                <strong>
                    View Records
                </strong>

                <span>
                    Manage all visitors
                </span>

            </div>

        </a>


        <a
            href="bill.php"
            class="quick-action"
        >

            <div class="action-icon">
                💳
            </div>

            <div class="action-text">

                <strong>
                    Swimming Bills
                </strong>

                <span>
                    View and manage bills
                </span>

            </div>

        </a>


        <button
            type="button"
            onclick="window.print()"
            class="quick-action"
            style="
                border:none;
                text-align:left;
                cursor:pointer;
                font-family:inherit;
            "
        >

            <div class="action-icon">
                🖨
            </div>

            <div class="action-text">

                <strong>
                    Print Dashboard
                </strong>

                <span>
                    Print swimming summary
                </span>

            </div>

        </button>

    </div>


    <!-- ======================================================
         TODAY'S STATISTICS
    ======================================================= -->

    <div class="stats-grid">

        <div class="stat-card blue">

            <div class="stat-icon">
                🏊
            </div>

            <div class="stat-title">
                Today's Entries
            </div>

            <div class="stat-value">
                <?= number_format($todayEntries) ?>
            </div>

            <div class="stat-description">
                Swimming registrations today
            </div>

        </div>


        <div class="stat-card green">

            <div class="stat-icon">
                👥
            </div>

            <div class="stat-title">
                Today's Visitors
            </div>

            <div class="stat-value">
                <?= number_format($todayVisitors) ?>
            </div>

            <div class="stat-description">
                Adults + children
            </div>

        </div>


        <div class="stat-card purple">

            <div class="stat-icon">
                💰
            </div>

            <div class="stat-title">
                Today's Revenue
            </div>

            <div class="stat-value">
                <?= money($todayRevenue) ?>
            </div>

            <div class="stat-description">
                Swimming revenue today
            </div>

        </div>


        <div class="stat-card orange">

            <div class="stat-icon">
                🟢
            </div>

            <div class="stat-title">
                Active Swimmers
            </div>

            <div class="stat-value">
                <?= number_format($activeSwimmers) ?>
            </div>

            <div class="stat-description">
                Currently active entries
            </div>

        </div>

    </div>


    <!-- ======================================================
         CONTENT
    ======================================================= -->

    <div class="content-grid">


        <!-- ==================================================
             RECENT RECORDS
        =================================================== -->

        <div class="panel">

            <div class="panel-header">

                <div>

                    <div class="panel-title">
                        Recent Swimming Entries
                    </div>

                    <div class="panel-subtitle">
                        Latest swimming pool registrations
                    </div>

                </div>

                <a
                    href="view.php"
                    class="view-all"
                >
                    View All
                </a>

            </div>


            <?php if (!empty($recentRecords)): ?>

                <div class="table-wrapper">

                    <table>

                        <thead>

                        <tr>

                            <th>
                                Visitor
                            </th>

                            <th>
                                Date
                            </th>

                            <th>
                                Entry
                            </th>

                            <th>
                                Visitors
                            </th>

                            <th>
                                Amount
                            </th>

                            <th>
                                Status
                            </th>

                        </tr>

                        </thead>


                        <tbody>

                        <?php foreach ($recentRecords as $record): ?>

                            <?php

                            $visitorName =
                                trim($record['visitor_name'] ?? '');

                            if ($visitorName === '') {

                                $visitorName =
                                    trim($record['guest_name'] ?? '');

                            }

                            if ($visitorName === '') {
                                $visitorName = 'Walk-in Visitor';
                            }

                            $status =
                                strtolower(
                                    trim($record['status'] ?? '')
                                );

                            $statusClass = 'status-default';

                            if ($status === 'active') {
                                $statusClass = 'status-active';
                            } elseif ($status === 'completed') {
                                $statusClass = 'status-completed';
                            } elseif (
                                $status === 'cancelled'
                                ||
                                $status === 'canceled'
                            ) {
                                $statusClass = 'status-cancelled';
                            }

                            $adultCount =
                                (int)($record['adults'] ?? 0);

                            $childCount =
                                (int)($record['children'] ?? 0);

                            $visitorCount =
                                $adultCount + $childCount;

                            ?>

                            <tr>

                                <td>

                                    <div class="visitor-name">
                                        <?= e($visitorName) ?>
                                    </div>

                                    <div class="visitor-sub">

                                        <?= e(
                                            ucfirst(
                                                $record['visitor_type']
                                                ?? 'Visitor'
                                            )
                                        ) ?>

                                    </div>

                                </td>


                                <td>
                                    <?= e(
                                        date(
                                            'd M Y',
                                            strtotime(
                                                $record['visit_date']
                                            )
                                        )
                                    ) ?>
                                </td>


                                <td>

                                    <?= e(
                                        $record['entry_time']
                                        ?? '-'
                                    ) ?>

                                </td>


                                <td>

                                    <strong>
                                        <?= number_format($visitorCount) ?>
                                    </strong>

                                    <div class="visitor-sub">

                                        <?= $adultCount ?>
                                        adult<?= $adultCount == 1 ? '' : 's' ?>

                                        +

                                        <?= $childCount ?>
                                        child<?= $childCount == 1 ? '' : 'ren' ?>

                                    </div>

                                </td>


                                <td class="amount">

                                    <?= money(
                                        $record['total_amount']
                                        ?? 0
                                    ) ?>

                                </td>


                                <td>

                                    <span
                                        class="status <?= e($statusClass) ?>"
                                    >
                                        <?= e(
                                            ucfirst(
                                                $record['status']
                                                ?? 'Unknown'
                                            )
                                        ) ?>
                                    </span>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="empty">

                    <div class="empty-icon">
                        🏊
                    </div>

                    <strong>
                        No swimming records found
                    </strong>

                    There are currently no swimming entries.

                </div>

            <?php endif; ?>

        </div>


        <!-- ==================================================
             SUMMARY
        =================================================== -->

        <div class="panel">

            <div class="panel-header">

                <div>

                    <div class="panel-title">
                        Swimming Summary
                    </div>

                    <div class="panel-subtitle">
                        Overall swimming statistics
                    </div>

                </div>

            </div>


            <div class="summary-list">

                <div class="summary-row">

                    <span class="summary-label">
                        Today's Adults
                    </span>

                    <span class="summary-value">
                        <?= number_format($todayAdults) ?>
                    </span>

                </div>


                <div class="summary-row">

                    <span class="summary-label">
                        Today's Children
                    </span>

                    <span class="summary-value">
                        <?= number_format($todayChildren) ?>
                    </span>

                </div>


                <div class="summary-row">

                    <span class="summary-label">
                        Today's Visitors
                    </span>

                    <span class="summary-value">
                        <?= number_format($todayVisitors) ?>
                    </span>

                </div>


                <div class="summary-row">

                    <span class="summary-label">
                        Total Entries
                    </span>

                    <span class="summary-value">
                        <?= number_format($totalEntries) ?>
                    </span>

                </div>


                <div class="summary-row">

                    <span class="summary-label">
                        Total Visitors
                    </span>

                    <span class="summary-value">
                        <?= number_format($totalVisitors) ?>
                    </span>

                </div>


                <div class="summary-row">

                    <span class="summary-label">
                        Total Revenue
                    </span>

                    <span
                        class="summary-value"
                        style="color:#059669"
                    >
                        <?= money($totalRevenue) ?>
                    </span>

                </div>

            </div>


            <!-- ==============================================
                 RATES
            =============================================== -->

            <div class="rate-card">

                <div class="rate-title">
                    💰 Swimming Pool Rates
                </div>


                <div class="rate-row">

                    <span>
                        Adult
                    </span>

                    <span class="rate-price">
                        Rs. 500
                    </span>

                </div>


                <div class="rate-row">

                    <span>
                        Child
                    </span>

                    <span class="rate-price">
                        Rs. 300
                    </span>

                </div>

            </div>

        </div>

    </div>


    <!-- ======================================================
         TODAY REVENUE SUMMARY
    ======================================================= -->

    <div class="panel">

        <div class="panel-header">

            <div>

                <div class="panel-title">
                    Today's Revenue Overview
                </div>

                <div class="panel-subtitle">
                    <?= date('d F Y') ?>
                </div>

            </div>

        </div>


        <div class="stats-grid" style="margin-bottom:0">

            <div class="stat-card">

                <div class="stat-title">
                    Adult Revenue
                </div>

                <div class="stat-value">

                    <?= money(
                        $todayAdults * 500
                    ) ?>

                </div>

                <div class="stat-description">
                    <?= $todayAdults ?> adult visitors × Rs. 500
                </div>

            </div>


            <div class="stat-card">

                <div class="stat-title">
                    Child Revenue
                </div>

                <div class="stat-value">

                    <?= money(
                        $todayChildren * 300
                    ) ?>

                </div>

                <div class="stat-description">
                    <?= $todayChildren ?> children × Rs. 300
                </div>

            </div>


            <div class="stat-card">

                <div class="stat-title">
                    Today's Visitors
                </div>

                <div class="stat-value">
                    <?= number_format($todayVisitors) ?>
                </div>

                <div class="stat-description">
                    Total adults and children
                </div>

            </div>


            <div class="stat-card green">

                <div class="stat-title">
                    Total Collected
                </div>

                <div class="stat-value">
                    <?= money($todayRevenue) ?>
                </div>

                <div class="stat-description">
                    Swimming pool revenue
                </div>

            </div>

        </div>

    </div>


    <!-- ======================================================
         FOOTER
    ======================================================= -->

    <div class="footer">

        <strong>
            SUVA HOTEL
        </strong>

        &nbsp; | &nbsp;

        Swimming Management System

        <br>

        Today:
        <?= date('d M Y, h:i A') ?>

    </div>

</div>

</body>

</html>

