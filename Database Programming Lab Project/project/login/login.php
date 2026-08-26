<?php
session_start();
if (isset($_SESSION['user_id'])) {
    // Redirect ตาม role
    if (isset($_SESSION['role'])) {
        switch ($_SESSION['role']) {
            case 'ADMIN':
                header('Location: ../superadmin/index.php');
                break;
            case 'STAFF':
                header('Location: ../staff/index.php');
                break;
            case 'CUSTOMER':
            default:
                header('Location: ../customer/dashboard.php');
                break;
        }
    } else {
        header('Location: ../customer/dashboard.php');
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/login.css">
</head>
<body>
    <!-- Navbar -->
    <div class="navbar">
        <div class="nav-content">
            <div class="nav-left">
                <img src="css/logo.png" alt="logo" style="height:36px;object-fit:contain"/>
            </div>
        </div>
    </div>
    
    <div class="container">
        <div class="login-box">
            <div class="profile-icon">
                <img src="Login logo.png" alt="Login Icon">
            </div>
            
            <form action="login_process.php" method="POST" class="login-form">
                <?php if (isset($_GET['error'])): ?>
                    <div class="error-message">
                        <?php 
                        switch ($_GET['error']) {
                            case 'invalid':
                                echo '❌ Invalid username or password';
                                break;
                            case 'empty':
                                echo '⚠️ Please fill in all fields';
                                break;
                            case 'inactive':
                                echo '⚠️ Your account is inactive. Please contact admin.';
                                break;
                            default:
                                echo '❌ Login failed. Please try again.';
                        }
                        ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['success']) && $_GET['success'] == 'registered'): ?>
                    <div class="success-message">✅ Registration successful! Please login.</div>
                <?php endif; ?>

                <?php if (isset($_GET['logout']) && $_GET['logout'] == 'success'): ?>
                    <div class="success-message">✅ You have been logged out successfully.</div>
                <?php endif; ?>

                <div class="input-group">
                    <input type="text" name="username" id="username" required>
                    <label for="username" class="placeholder">Username</label>
                </div>

                <div class="input-group">
                    <input type="password" name="password" id="password" required>
                    <label for="password" class="placeholder">Password</label>
                </div>

                <button type="submit" class="btn-login">Login</button>
                
                <!-- Demo accounts info -->
                <div style="margin-top: 15px; padding: 10px; background: #f5f5f5; border-radius: 5px; font-size: 12px;">
                    <strong>📝 Demo Accounts:</strong><br>
                    Admin: admin / 12<br>
                    Staff: staff1 / 123<br>
                    Customer: john123 / 1234
                </div>
            </form>
        </div>

        <button class="btn-register" onclick="window.location.href='register.php'">
            REGISTER
        </button>
    </div>
</body>
</html>