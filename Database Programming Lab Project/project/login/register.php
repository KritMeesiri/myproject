<?php
session_start();
if (isset($_SESSION['user_id'])) {
    // Redirect ตาม role
    if (isset($_SESSION['role'])) {
        switch ($_SESSION['role']) {
            case 'ADMIN':
            case 'STAFF':
                header('Location: ../staff/dashboard.php');
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
        <div class="login-box register-box">
            <h2 class="register-title">Create Account</h2>
            
            <form action="register_process.php" method="POST" class="login-form">
                <?php if (isset($_GET['error'])): ?>
                    <div class="error-message">
                        <?php 
                        switch ($_GET['error']) {
                            case 'empty':
                                echo '⚠️ Please fill in all required fields';
                                break;
                            case 'password_mismatch':
                                echo '❌ Passwords do not match';
                                break;
                            case 'username_exists':
                                echo '❌ Username already exists';
                                break;
                            case 'email_exists':
                                echo '❌ Email already exists';
                                break;
                            case 'phone_exists':
                                echo '❌ Phone number already exists';
                                break;
                            case 'invalid_phone':
                                echo '❌ Phone number must be exactly 10 digits';
                                break;
                            case 'invalid_email':
                                echo '❌ Invalid email address';
                                break;
                            case 'username_short':
                                echo '⚠️ Username must be at least 4 characters';
                                break;
                            case 'password_short':
                                echo '⚠️ Password must be at least 4 characters';
                                break;
                            case 'registration_failed':
                                echo '❌ Registration failed. Please try again.';
                                break;
                            default:
                                echo '❌ An error occurred. Please try again.';
                        }
                        ?>
                    </div>
                <?php endif; ?>

                <div class="input-group">
                    <input type="text" name="username" id="reg_username" required 
                           value="<?php echo htmlspecialchars($_GET['username'] ?? ''); ?>">
                    <label for="reg_username" class="placeholder">Username *</label>
                </div>

                <div class="input-group">
                    <input type="email" name="email" id="reg_email" required
                           value="<?php echo htmlspecialchars($_GET['email'] ?? ''); ?>">
                    <label for="reg_email" class="placeholder">Email *</label>
                </div>

                <div class="form-row">
                    <div class="input-group">
                        <input type="text" name="firstname" id="reg_firstname" required
                               value="<?php echo htmlspecialchars($_GET['firstname'] ?? ''); ?>">
                        <label for="reg_firstname" class="placeholder">First Name *</label>
                    </div>

                    <div class="input-group">
                        <input type="text" name="lastname" id="reg_lastname" required
                               value="<?php echo htmlspecialchars($_GET['lastname'] ?? ''); ?>">
                        <label for="reg_lastname" class="placeholder">Last Name *</label>
                    </div>
                </div>

                <div class="form-row">
                    <div class="input-group">
                        <input type="tel" name="phone" id="reg_phone" pattern="[0-9]{10}" 
                               placeholder="0812345678" maxlength="10" required
                               value="<?php echo htmlspecialchars($_GET['phone'] ?? ''); ?>">
                        <label for="reg_phone" class="placeholder">Phone (10 digits) *</label>
                    </div>

                    <div class="input-group">
                        <input type="date" name="dob" id="reg_dob" max="<?php echo date('Y-m-d'); ?>" required
                               value="<?php echo htmlspecialchars($_GET['dob'] ?? ''); ?>">
                        <label for="reg_dob" class="placeholder">Date of Birth *</label>
                    </div>
                </div>

                <div class="input-group">
                    <input type="password" name="password" id="reg_password" required minlength="4">
                    <label for="reg_password" class="placeholder">Password *</label>
                </div>

                <div class="input-group">
                    <input type="password" name="confirm_password" id="confirm_password" required minlength="4">
                    <label for="confirm_password" class="placeholder">Confirm Password *</label>
                </div>

                <button type="submit" class="btn-login">Register</button>
            </form>
        </div>

        <button class="btn-register" onclick="window.location.href='login.php'">
            BACK TO LOGIN
        </button>
    </div>
</body>
</html>