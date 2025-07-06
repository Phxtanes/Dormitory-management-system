<?php
session_start();

// ตรวจสอบว่าเข้าสู่ระบบแล้วหรือไม่
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

require_once 'config.php';

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    if (!empty($username) && !empty($password)) {
        try {
            // ดึงข้อมูลผู้ใช้
            $stmt = $pdo->prepare("SELECT user_id, username, password_hash, full_name, user_role, is_active FROM users WHERE username = ? AND is_active = 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password_hash'])) {
                // เข้าสู่ระบบสำเร็จ
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['user_role'] = $user['user_role'];
                
                // อัพเดทเวลาล็อกอินล่าสุด
                $update_stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
                $update_stmt->execute([$user['user_id']]);
                
                // กลับไปยังหน้าที่ต้องการเข้าถึง หรือหน้าแรก
                $redirect = isset($_GET['redirect']) ? $_GET['redirect'] : 'index.php';
                header('Location: ' . $redirect);
                exit;
            } else {
                $error_message = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
            }
            
        } catch(PDOException $e) {
            $error_message = 'เกิดข้อผิดพลาดในระบบ กรุณาลองใหม่อีกครั้ง';
        }
    } else {
        $error_message = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ - ระบบจัดการหอพัก</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600&display=swap" rel="stylesheet">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Kanit', sans-serif;
            background-color: #f8f9fa;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .login-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            padding: 3rem 2.5rem;
            width: 100%;
            max-width: 400px;
        }
        
        .logo {
            text-align: center;
            margin-bottom: 2.5rem;
        }
        
        .logo-icon {
            font-size: 3rem;
            color: #6c757d;
            margin-bottom: 0.5rem;
        }
        
        .logo-text {
            font-size: 1.5rem;
            font-weight: 500;
            color: #333;
            margin: 0;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            font-size: 0.9rem;
            font-weight: 500;
            color: #555;
            margin-bottom: 0.5rem;
            display: block;
        }
        
        .form-control {
            width: 100%;
            padding: 0.875rem 1rem;
            border: 1.5px solid #e9ecef;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.2s ease;
            background-color: #fff;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #6c757d;
            box-shadow: 0 0 0 3px rgba(108, 117, 125, 0.1);
        }
        
        .btn-login {
            width: 100%;
            padding: 0.875rem;
            background-color: #212529;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-top: 1rem;
        }
        
        .btn-login:hover {
            background-color: #343a40;
            transform: translateY(-1px);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .alert {
            padding: 0.875rem 1rem;
            border-radius: 8px;
            border: none;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }
        
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .test-info {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 2rem;
            font-size: 0.85rem;
            color: #6c757d;
            text-align: center;
            border: 1px solid #e9ecef;
        }
        
        .test-info strong {
            color: #495057;
        }
        
        .divider {
            text-align: center;
            margin: 1.5rem 0;
            position: relative;
        }
        
        .divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background-color: #e9ecef;
        }
        
        .divider span {
            background-color: white;
            padding: 0 1rem;
            color: #6c757d;
            font-size: 0.85rem;
        }
        
        @media (max-width: 480px) {
            .login-container {
                padding: 2rem 1.5rem;
                margin: 1rem;
                border-radius: 8px;
            }
            
            .logo-text {
                font-size: 1.25rem;
            }
            
            .logo-icon {
                font-size: 2.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <!-- Logo และชื่อระบบ -->
        <div class="logo">
            <div class="logo-icon">
                <i class="bi bi-building"></i>
            </div>
            <h1 class="logo-text">ระบบจัดการหอพัก</h1>
        </div>
        
        <!-- แสดงข้อผิดพลาด -->
        <?php if ($error_message): ?>
            <div class="alert alert-danger" role="alert">
                <i class="bi bi-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>
        
        <!-- ฟอร์มล็อกอิน -->
        <form method="POST" action="">
            <div class="form-group">
                <label for="username" class="form-label">ชื่อผู้ใช้</label>
                <input type="text" class="form-control" id="username" name="username" 
                       required autocomplete="username"
                       value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
            </div>
            
            <div class="form-group">
                <label for="password" class="form-label">รหัสผ่าน</label>
                <input type="password" class="form-control" id="password" name="password" 
                       required autocomplete="current-password">
            </div>
            
            <button type="submit" class="btn-login">
                เข้าสู่ระบบ
            </button>
        </form>
        
        <!-- ข้อมูลทดสอบ -->
        <div class="divider">
            <span>ข้อมูลทดสอบ</span>
        </div>
        
       <!--  <div class="test-info">
            <div style="margin-bottom: 0.5rem;">
                <strong>ผู้ดูแล:</strong> admin / password
            </div>
            <div>
                <strong>เจ้าหน้าที่:</strong> staff01 / password
            </div>
        </div> -->
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Auto focus ที่ username field
        document.getElementById('username').focus();
    </script>
</body>
</html>