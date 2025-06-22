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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        body {
            font-family: 'Kanit', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            width: 100%;
            max-width: 900px;
            min-height: 500px;
        }
        
        .login-left {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 40px;
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
            text-align: center;
        }
        
        .login-right {
            padding: 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        .login-title {
            font-size: 2.5rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        
        .login-subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
            margin-bottom: 2rem;
        }
        
        .form-title {
            font-size: 2rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 2rem;
            text-align: center;
        }
        
        .form-floating {
            margin-bottom: 1.5rem;
        }
        
        .form-control {
            border-radius: 12px;
            border: 1px solid #ddd;
            padding: 12px 16px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        
        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 12px;
            padding: 12px;
            font-size: 1.1rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3);
        }
        
        .alert {
            border-radius: 12px;
            border: none;
        }
        
        .building-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.9;
        }
        
        @media (max-width: 768px) {
            .login-container {
                margin: 20px;
                border-radius: 15px;
            }
            
            .login-left {
                padding: 30px 20px;
            }
            
            .login-right {
                padding: 30px 20px;
            }
            
            .login-title {
                font-size: 2rem;
            }
            
            .form-title {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="login-container">
            <div class="row g-0 h-100">
                <!-- ส่วนซ้าย - ข้อมูลระบบ -->
                <div class="col-lg-5 d-none d-lg-flex">
                    <div class="login-left">
                        <div class="building-icon">
                            <i class="bi bi-building"></i>
                        </div>
                        <h1 class="login-title">ระบบจัดการหอพัก</h1>
                        <p class="login-subtitle">
                            จัดการข้อมูลผู้เช่า ห้องพัก สัญญาเช่า และการชำระเงิน<br>
                            ได้อย่างมีประสิทธิภาพและสะดวกรวดเร็ว
                        </p>
                        <div class="mt-4">
                            <div class="d-flex align-items-center justify-content-center mb-2">
                                <i class="bi bi-shield-check me-2"></i>
                                <span>ปลอดภัยและเชื่อถือได้</span>
                            </div>
                            <div class="d-flex align-items-center justify-content-center mb-2">
                                <i class="bi bi-lightning me-2"></i>
                                <span>ใช้งานง่าย รวดเร็ว</span>
                            </div>
                            <div class="d-flex align-items-center justify-content-center">
                                <i class="bi bi-graph-up me-2"></i>
                                <span>รายงานและสถิติครบถ้วน</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- ส่วนขวา - ฟอร์มล็อกอิน -->
                <div class="col-lg-7">
                    <div class="login-right">
                        <h2 class="form-title">เข้าสู่ระบบ</h2>
                        
                        <?php if ($error_message): ?>
                            <div class="alert alert-danger d-flex align-items-center" role="alert">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                <?php echo htmlspecialchars($error_message); ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" action="">
                            <div class="form-floating">
                                <input type="text" class="form-control" id="username" name="username" 
                                       placeholder="ชื่อผู้ใช้" required autocomplete="username"
                                       value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                                <label for="username">
                                    <i class="bi bi-person me-2"></i>ชื่อผู้ใช้
                                </label>
                            </div>
                            
                            <div class="form-floating">
                                <input type="password" class="form-control" id="password" name="password" 
                                       placeholder="รหัสผ่าน" required autocomplete="current-password">
                                <label for="password">
                                    <i class="bi bi-lock me-2"></i>รหัสผ่าน
                                </label>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary btn-login">
                                    <i class="bi bi-box-arrow-in-right me-2"></i>เข้าสู่ระบบ
                                </button>
                            </div>
                        </form>
                        
                        <div class="text-center mt-4">
                            <small class="text-muted">
                                <i class="bi bi-info-circle me-1"></i>
                                หากลืมรหัสผ่าน กรุณาติดต่อผู้ดูแลระบบ
                            </small>
                        </div>
                        
                        <!-- ข้อมูลทดสอบ (ลบออกในการใช้งานจริง) -->
                        <div class="mt-4 p-3 bg-light rounded">
                            <small class="text-muted">
                                <strong>ข้อมูลทดสอบ:</strong><br>
                                <i class="bi bi-person-badge me-1"></i> ผู้ดูแล: admin / password<br>
                                <i class="bi bi-person me-1"></i> เจ้าหน้าที่: staff01 / password
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Auto focus ที่ username field
        document.getElementById('username').focus();
        
        // Show/Hide password
        document.addEventListener('DOMContentLoaded', function() {
            // เพิ่มปุ่ม show/hide password
            const passwordField = document.getElementById('password');
            const passwordContainer = passwordField.parentElement;
            
            const toggleButton = document.createElement('button');
            toggleButton.type = 'button';
            toggleButton.className = 'btn btn-outline-secondary position-absolute top-50 end-0 translate-middle-y me-3';
            toggleButton.style.border = 'none';
            toggleButton.style.background = 'none';
            toggleButton.innerHTML = '<i class="bi bi-eye"></i>';
            
            passwordContainer.style.position = 'relative';
            passwordContainer.appendChild(toggleButton);
            
            toggleButton.addEventListener('click', function() {
                if (passwordField.type === 'password') {
                    passwordField.type = 'text';
                    toggleButton.innerHTML = '<i class="bi bi-eye-slash"></i>';
                } else {
                    passwordField.type = 'password';
                    toggleButton.innerHTML = '<i class="bi bi-eye"></i>';
                }
            });
        });
    </script>
</body>
</html>