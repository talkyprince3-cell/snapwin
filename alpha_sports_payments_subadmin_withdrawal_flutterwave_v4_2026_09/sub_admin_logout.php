<?php
session_start();
unset($_SESSION['sub_admin_id'], $_SESSION['sub_admin_username'], $_SESSION['sub_admin_ref']);
header("Location: sub_admin_login.php"); exit;
