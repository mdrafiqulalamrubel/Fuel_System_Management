<?php
require_once 'config/database.php';

if(isLoggedIn()) {
    redirect('dashboard.php');
}

$error = '';
if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = md5($_POST['password']);
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND password = ? AND is_active = 1");
    $stmt->execute([$username, $password]);
    $user = $stmt->fetch();
    
    if($user) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['full_name'];
        $_SESSION['user_role'] = $user['role'];
        
        // Log activity
        $log = $pdo->prepare("INSERT INTO activity_logs (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)");
        $log->execute([$user['id'], 'login', 'User logged in', $_SERVER['REMOTE_ADDR']]);
        
        redirect('dashboard.php');
    } else {
        $error = 'Invalid username or password!';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DAFFODIL - Fuel Station Management System</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            height: 100vh;
            overflow: hidden;
            background: #fff;
        }

        /* Split Screen Container */
        .split-container {
            display: flex;
            height: 100vh;
            width: 100%;
        }

        /* Left Side - Image Section with Fuel Station Background */
        .image-section {
            flex: 1;
            position: relative;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            overflow: hidden;
        }

        /* Fuel Station Background Image with Multiple Fallbacks */
        .image-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                /* Dark overlay for better text readability */
                linear-gradient(180deg, 
                    rgba(0,0,0,0.3) 0%, 
                    rgba(0,0,0,0.2) 30%,
                    rgba(0,0,0,0.1) 60%,
                    rgba(0,0,0,0.3) 100%
                ),
                /* Multiple image fallbacks */
                url('images/fuelbk.jpg'),
                url('https://images.unsplash.com/photo-1565022532506-bf2573e6e170?ixlib=rb-4.0.3&auto=format&fit=crop&w=1470&q=80'),
                url('https://images.unsplash.com/photo-1593459853561-a5fa2be2fcbd?ixlib=rb-4.0.3&auto=format&fit=crop&w=1470&q=80') 
                center/cover no-repeat;
            z-index: 0;
            animation: slowZoom 20s ease-in-out infinite alternate;
        }

        /* Animated zoom effect for the background */
        @keyframes slowZoom {
            0% {
                transform: scale(1);
            }
            100% {
                transform: scale(1.05);
            }
        }

        /* Decorative overlay with gradient for depth */
        .image-section::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(
                ellipse at 30% 50%,
                rgba(102, 126, 234, 0.15) 0%,
                rgba(118, 75, 162, 0.1) 50%,
                transparent 100%
            );
            z-index: 1;
        }

        .image-content {
            position: relative;
            z-index: 2;
            padding: 60px 40px;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            color: white;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-icon {
            width: 50px;
            height: 50px;
            background: rgba(255,255,255,0.15);
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.1);
        }

        .logo-text h2 {
            font-size: 24px;
            font-weight: 700;
            margin: 0;
            text-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }

        .logo-text p {
            font-size: 12px;
            opacity: 0.8;
            margin: 0;
            text-shadow: 0 1px 5px rgba(0,0,0,0.3);
        }

        .hero-text {
            text-align: center;
            max-width: 85%;
            margin: 0 auto;
            padding: 20px;
            background: rgba(0,0,0,0.25);
            backdrop-filter: blur(8px);
            border-radius: 20px;
            border: 1px solid rgba(255,255,255,0.1);
        }

        .hero-text h1 {
            font-size: 38px;
            font-weight: 800;
            margin-bottom: 20px;
            line-height: 1.2;
            text-shadow: 0 4px 15px rgba(0,0,0,0.3);
        }

        .hero-text h1 .highlight {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hero-text p {
            font-size: 15px;
            opacity: 0.9;
            line-height: 1.6;
            text-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }

        .features {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 35px;
            flex-wrap: wrap;
        }

        .feature-item {
            text-align: center;
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            padding: 18px 22px;
            border-radius: 15px;
            min-width: 130px;
            border: 1px solid rgba(255,255,255,0.08);
            transition: all 0.3s ease;
            flex: 1;
            max-width: 180px;
        }

        .feature-item:hover {
            transform: translateY(-5px);
            background: rgba(255,255,255,0.2);
            border-color: rgba(255,255,255,0.2);
        }

        .feature-item i {
            font-size: 32px;
            margin-bottom: 10px;
            display: block;
        }

        .feature-item .feature-icon-orange {
            color: #f6a85b;
        }

        .feature-item .feature-icon-blue {
            color: #5bc0de;
        }

        .feature-item .feature-icon-green {
            color: #5cb85c;
        }

        .feature-item .feature-icon-purple {
            color: #b39ddb;
        }

        .feature-item span {
            font-size: 13px;
            font-weight: 600;
            display: block;
        }

        .feature-item small {
            font-size: 10px;
            opacity: 0.7;
            display: block;
            margin-top: 2px;
        }

        .footer-note {
            text-align: center;
            font-size: 12px;
            opacity: 0.7;
            text-shadow: 0 1px 5px rgba(0,0,0,0.3);
        }

        /* Right Side - Login Section */
        .login-section {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #fff;
            padding: 40px;
        }

        .login-container {
            width: 100%;
            max-width: 450px;
        }

        .login-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .login-header h2 {
            font-size: 32px;
            font-weight: 700;
            color: #333;
            margin-bottom: 8px;
        }

        .login-header h2 i {
            color: #667eea;
        }

        .login-header p {
            color: #666;
            font-size: 14px;
        }

        .login-form {
            background: #fff;
        }

        .input-group {
            margin-bottom: 25px;
            position: relative;
        }

        .input-group i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            font-size: 18px;
        }

        .input-group input {
            width: 100%;
            padding: 15px 15px 15px 50px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 14px;
            font-family: 'Poppins', sans-serif;
            transition: all 0.3s ease;
        }

        .input-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .login-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            font-size: 13px;
        }

        .remember-me {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #666;
            cursor: pointer;
        }

        .remember-me input {
            width: 16px;
            height: 16px;
            cursor: pointer;
        }

        .forgot-password {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }

        .forgot-password:hover {
            text-decoration: underline;
        }

        .login-btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
        }

        .error-alert {
            background: #fee2e2;
            border-left: 4px solid #ef4444;
            padding: 12px 15px;
            border-radius: 10px;
            margin-bottom: 25px;
            color: #dc2626;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .error-alert i {
            font-size: 18px;
        }

        .demo-credentials {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 15px 20px;
            margin-top: 30px;
            text-align: center;
            border: 1px solid #e0e0e0;
        }

        .demo-credentials p {
            font-size: 12px;
            color: #666;
            margin-bottom: 8px;
        }

        .demo-credentials .credentials {
            display: flex;
            justify-content: center;
            gap: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .demo-credentials .credentials span {
            color: #667eea;
        }

        /* Responsive Design */
        @media (max-width: 968px) {
            .image-section {
                display: none;
            }
            
            .login-section {
                flex: 1;
            }
            
            .login-container {
                max-width: 400px;
            }
        }

        @media (max-width: 480px) {
            .login-section {
                padding: 20px;
            }
            
            .login-header h2 {
                font-size: 28px;
            }
            
            .login-options {
                flex-direction: column;
                gap: 10px;
                align-items: flex-start;
            }
            
            .hero-text {
                max-width: 95%;
                padding: 15px;
            }
            
            .hero-text h1 {
                font-size: 28px;
            }
            
            .features {
                gap: 10px;
            }
            
            .feature-item {
                min-width: 80px;
                padding: 12px 15px;
                max-width: 100%;
            }
            
            .feature-item i {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="split-container">
        <!-- Left Side - Image & Content -->
        <div class="image-section">
            <div class="image-content">
                <div class="logo-section">
                    <div class="logo-icon">
                        <i class="fas fa-gas-pump"></i>
                    </div>
                    <div class="logo-text">
                        <h2>Daffodil FuelStation</h2>
                        <p>Fuel Station Management System</p>
                    </div>
                </div>
                
                <div class="hero-text">
                    <h1>Welcome to <br><span class="highlight">Fuel Station</span> Management</h1>
                    <p>Complete solution for managing fuel sales, inventory, accounting, payroll, and more. Streamline your gas station operations with our powerful software.</p>
                    
                    <div class="features">
                        <div class="feature-item">
                            <i class="fas fa-gas-pump feature-icon-orange"></i>
                            <span>Fuel POS</span>
                            <small>Quick fuel sales</small>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-boxes feature-icon-blue"></i>
                            <span>Item POS</span>
                            <small>Products & services</small>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-calculator feature-icon-green"></i>
                            <span>Real-Time Accounting</span>
                            <small>Live financial tracking</small>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-chart-bar feature-icon-purple"></i>
                            <span>Real-Time Reporting</span>
                            <small>Instant insights</small>
                        </div>
                    </div>
                </div>
                
                <div class="footer-note">
                    <p>&copy; 2024 Daffodil Software Limited. All rights reserved.</p>
                </div>
            </div>
        </div>
        
        <!-- Right Side - Login Form -->
        <div class="login-section">
            <div class="login-container">
                <div class="login-header">
                    <h2><i class="fas fa-sign-in-alt"></i> Sign In</h2>
                    <p>Enter your credentials to access the system</p>
                </div>
                
                <?php if($error): ?>
                    <div class="error-alert">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?php echo $error; ?></span>
                    </div>
                <?php endif; ?>
                
                <form method="POST" class="login-form">
                    <div class="input-group">
                        <i class="fas fa-user"></i>
                        <input type="text" name="username" placeholder="Username" required autocomplete="off">
                    </div>
                    
                    <div class="input-group">
                        <i class="fas fa-lock"></i>
                        <input type="password" name="password" placeholder="Password" required>
                    </div>
                    
                    <div class="login-options">
                        <label class="remember-me">
                            <input type="checkbox" name="remember">
                            <span>Remember me</span>
                        </label>
                        <a href="#" class="forgot-password">Forgot Password?</a>
                    </div>
                    
                    <button type="submit" class="login-btn">
                        <i class="fas fa-sign-in-alt"></i> Login
                    </button>
                </form>
                
                <div class="demo-credentials">
                    <p><i class="fas fa-info-circle"></i> Demo Credentials</p>
                    <div class="credentials">
                        <div>Username: <span>admin</span></div>
                        <div>Password: <span>admin123</span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Optional: Add remember me functionality
        if(localStorage.getItem('rememberedUsername')) {
            document.querySelector('input[name="username"]').value = localStorage.getItem('rememberedUsername');
            document.querySelector('input[name="remember"]').checked = true;
        }
        
        document.querySelector('form').addEventListener('submit', function() {
            if(document.querySelector('input[name="remember"]').checked) {
                localStorage.setItem('rememberedUsername', document.querySelector('input[name="username"]').value);
            } else {
                localStorage.removeItem('rememberedUsername');
            }
        });
    </script>
</body>
</html>