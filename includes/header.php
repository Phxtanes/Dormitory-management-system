<?php
// เริ่มต้น session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
// เชื่อมต่อฐานข้อมูล
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/functions.php';

check_login();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?>ระบบจัดการหอพัก</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Google Fonts -->
    
    <!-- Custom CSS -->
    <style>
        body {
            font-family: 'Kanit', sans-serif;
            background-color: #f8f9fa;
        }
        
        .navbar-brand {
            font-weight: 600;
            font-size: 1.5rem;
        }
        
        .card {
            border: none;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border-radius: 0.5rem;
        }
        
        .card-header {
            background-color: #fff;
            border-bottom: 1px solid #dee2e6;
            font-weight: 500;
        }
        
        .btn {
            border-radius: 0.375rem;
            font-weight: 500;
        }
        
        .table th {
            font-weight: 600;
            background-color: #f8f9fa;
        }
        
        .status-badge {
            font-size: 0.875rem;
            padding: 0.25rem 0.5rem;
        }
        
        .sidebar {
            min-height: 100vh;
            background-color: #343a40;
        }
        
        .sidebar .nav-link {
            color: #fff;
            padding: 0.75rem 1rem;
            margin: 0.25rem 0;
            border-radius: 0.375rem;
        }
        
        .sidebar .nav-link:hover {
            background-color: #495057;
            color: #fff;
        }
        
        .sidebar .nav-link.active {
            background-color: #0d6efd;
            color: #fff;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                min-height: auto;
            }
        }
    </style>
</head>
<body>