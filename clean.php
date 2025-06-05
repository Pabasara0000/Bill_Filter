<?php
session_start();
if (isset($_SESSION['pdf_file']) && file_exists($_SESSION['pdf_file'])) {
    unlink($_SESSION['pdf_file']);
}
session_destroy();
header("Location: index.php");
exit;
