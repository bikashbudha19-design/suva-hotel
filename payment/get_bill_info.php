
<?php
session_start();

header("Content-Type: application/json; charset=utf-8");

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["error" => "Please log in again."]);
    exit;
}

require_once __DIR__ . "/../config/database.php";

if (!isset($pgConnection) || !$pgConnection) {
    http_response_code(500);
    echo json_encode(["error" => "Database connection failed."]);
    exit;
}

$booking_id = filter_input(
    INPUT_GET,
    "booking_id",
    FILTER_VALIDATE_INT
);

if (!$booking_id || $booking_id <= 0) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid booking."]);
    exit;
}

$sql = "
    SELECT id, total_amount
    FROM billing
    WHERE booking_id = $1
    ORDER BY id DESC
    LIMIT 1
";

$result = pg_query_params(
    $pgConnection,
    $sql,
    [$booking_id]
);

if (!$result) {
    http_response_code(500);
    echo json_encode(["error" => "Could not load the bill."]);
    exit;
}

$bill = pg_fetch_assoc($result);

if (!$bill) {
    http_response_code(404);
    echo json_encode(["error" => "No bill found for this booking."]);
    exit;
}

$paid_sql = "
    SELECT COALESCE(SUM(amount), 0) AS paid_amount
    FROM payments
    WHERE booking_id = $1
      AND LOWER(TRIM(COALESCE(payment_status, '')))
          IN ('paid', 'partial')
";

$paid_result = pg_query_params(
    $pgConnection,
    $paid_sql,
    [$booking_id]
);

if (!$paid_result) {
    http_response_code(500);
    echo json_encode(["error" => "Could not calculate payments."]);
    exit;
}

$paid_row = pg_fetch_assoc($paid_result);

$bill_total = (float)$bill["total_amount"];
$already_paid = (float)$paid_row["paid_amount"];
$remaining = max(0, $bill_total - $already_paid);

echo json_encode([
    "bill_total" => number_format($bill_total, 2, ".", ""),
    "already_paid" => number_format($already_paid, 2, ".", ""),
    "remaining" => number_format($remaining, 2, ".", "")
]);
