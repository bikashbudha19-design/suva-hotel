<?php
// ============================================================
// SUVA HOTEL - PRINT SWIMMING BILL
// File: /suva_hotel/swimming/print_bill.php
// ============================================================

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id <= 0) {
    die("Invalid swimming ID.");
}
?>

<script>
window.onload = function () {
    window.print();
};
</script>

<?php

include __DIR__ . "/print.php";

?>