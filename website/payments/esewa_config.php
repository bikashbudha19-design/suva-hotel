<?php
declare(strict_types=1);

/**
 * ============================================================
 * SUVA HOTEL — ESEWA PAYMENT CONFIGURATION
 * File: website/payments/esewa_config.php
 * PHP 8+ | PostgreSQL | XAMPP
 *
 * Sandbox configuration for eSewa ePay v2.
 * ============================================================
 */

date_default_timezone_set('Asia/Kathmandu');

/*
|--------------------------------------------------------------------------
| PAYMENT MODE & MERCHANT CREDENTIALS
|--------------------------------------------------------------------------
| Uses eSewa's public test merchant credentials (EPAYTEST).
*/

if (!defined('ESEWA_MODE')) {
    define('ESEWA_MODE', 'sandbox');
}

if (ESEWA_MODE !== 'sandbox') {
    throw new RuntimeException('Live eSewa payments are not enabled in this project.');
}

define('ESEWA_PRODUCT_CODE', 'EPAYTEST');
define('ESEWA_SECRET_KEY', '8gBm/:&EnhH.1/q');

/*
|--------------------------------------------------------------------------
| ESEWA ePay v2 ENDPOINTS
|--------------------------------------------------------------------------
*/

define('ESEWA_PAYMENT_URL', 'https://rc-epay.esewa.com.np/api/epay/main/v2/form');
define('ESEWA_STATUS_URL', 'https://uat.esewa.com.np/api/epay/transaction/status/');

/*
|--------------------------------------------------------------------------
| HOTEL PAYMENT SETTINGS
|--------------------------------------------------------------------------
*/

define('HOTEL_CURRENCY', 'NPR');
define('HOTEL_ADVANCE_PERCENT', 20);
define('ESEWA_PAYMENT_TIMEOUT_MINUTES', 15);

/*
|--------------------------------------------------------------------------
| APPLICATION URLS & CALLBACKS
|--------------------------------------------------------------------------
| Formatted for: C:\xampp\htdocs\Suva_hotel\website\payments\
*/

define('HOTEL_BASE_URL', 'http://localhost/Suva_hotel/website');
define('ESEWA_SUCCESS_URL', HOTEL_BASE_URL . '/payments/esewa_verify.php');
define('ESEWA_FAILURE_URL', HOTEL_BASE_URL . '/payments/esewa_failed.php');

/*
|--------------------------------------------------------------------------
| HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/

/**
 * Check that the sandbox configuration is available and valid.
 */
function esewaConfigurationReady(): bool
{
    return (
        defined('ESEWA_PRODUCT_CODE') &&
        defined('ESEWA_SECRET_KEY') &&
        ESEWA_PRODUCT_CODE !== '' &&
        ESEWA_SECRET_KEY !== ''
    );
}

/**
 * Format a payment amount to two decimal places.
 */
function esewaMoney(float $amount): string
{
    return number_format($amount, 2, '.', '');
}