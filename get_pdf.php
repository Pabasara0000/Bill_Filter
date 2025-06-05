<?php
session_start();
if (isset($_SESSION['pdf_file']) && file_exists($_SESSION['pdf_file'])) {
    header('Content-Type: application/pdf');
    readfile($_SESSION['pdf_file']);
    exit;
}
http_response_code(404);
echo "PDF not found.";
