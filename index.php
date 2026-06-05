<?php
/**
 * Login Module
 * Secure login page with database authentication and input validation.
 */
require_once 'includes/db_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect to dashboard if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

$errors = [];
$email = '';

// Handle form submission (Standard POST & AJAX POST support)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // Server-side validation
    if (empty($email)) {
        $errors['email'] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }
    
    if (empty($password)) {
        $errors['password'] = 'Password is required.';
    }
    
    if (empty($errors)) {
        try {
            // Fetch user from DB
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password_hash'])) {
                // Success! Set session variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_email'] = $user['email'];
                
                if (isset($_GET['json']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'redirect' => 'dashboard.php']);
                    exit;
                }
                
                header("Location: dashboard.php");
                exit;
            } else {
                $errors['general'] = 'Invalid email or password.';
            }
        } catch (PDOException $e) {
            $errors['general'] = 'Database connection error. Please try again.';
        }
    }
    
    // If we've made it here, there were validation or auth errors. 
    // If it's an AJAX call, return them in JSON format.
    if (isset($_GET['json']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'errors' => $errors]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - AdMetrics Dashboard</title>
    <link rel="icon" type="image/png" href="assets/images/fav.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/login.css?v=<?= time() ?>">
</head>
<body>

    <!-- Background SVG Shapes -->
    <div class="bg-shapes">
        <!-- Top Right Shape -->
        <svg class="bg-shape-top-right" viewBox="0 0 800 800" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none">
            <path fill="url(#grad1)" d="M400,0 C650,0 800,150 800,400 C800,600 700,750 500,800 C250,850 150,600 50,450 C-50,300 100,0 400,0 Z" />
            <defs>
                <linearGradient id="grad1" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" stop-color="#18c2f9" />
                    <stop offset="100%" stop-color="#287df0" />
                </linearGradient>
            </defs>
        </svg>

        <!-- Bottom Left Shape -->
        <svg class="bg-shape-bottom-left" viewBox="0 0 1000 1000" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none">
            <path fill="url(#grad2)" d="M100,1000 C-50,800 0,550 200,400 C400,250 650,350 750,550 C850,750 750,950 550,1050 C400,1100 250,1150 100,1000 Z" />
            <defs>
                <linearGradient id="grad2" x1="0%" y1="100%" x2="100%" y2="0%">
                    <stop offset="0%" stop-color="#23a9f3" />
                    <stop offset="100%" stop-color="#516ddf" />
                </linearGradient>
            </defs>
        </svg>
    </div>

    <!-- Login Area -->
    <div class="login-container">
        <div class="login-card">
            <div class="login-logo-container">
                <img src="assets/images/logo1.png" alt="AdMetrics Logo" class="login-logo">
            </div>
            <h2 class="align-c">Welcome back !</h2>
            <p class="subtitle align-c">User Login</p>

            <!-- General Error Message Banner -->
            <div class="general-error" id="general-error" <?php if (isset($errors['general'])): ?>style="display: flex;"<?php endif; ?>>
                <span class="material-symbols-outlined" style="font-size: 18px; vertical-align: middle;">error_outline</span>
                <span id="general-error-text"><?= htmlspecialchars($errors['general'] ?? '') ?></span>
            </div>

            <form id="login-form" method="POST" action="index.php" novalidate>
                <!-- Email Group -->
                <div class="form-group">
                    <input type="email" id="email" name="email" class="form-input" placeholder=" " value="<?= htmlspecialchars($email) ?>" autocomplete="username" required>
                    <label for="email" class="form-label">Email Address</label>
                    <div class="error-message <?php if (isset($errors['email'])): ?>visible<?php endif; ?>" id="email-error">
                        <?php if (isset($errors['email'])): ?>
                            <span class="material-symbols-outlined" style="font-size: 15px; vertical-align: middle; margin-right: 4px;">error</span>
                            <?= htmlspecialchars($errors['email']) ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Password Group -->
                <div class="form-group" style="margin-bottom: 12px;">
                    <input type="password" id="password" name="password" class="form-input" placeholder=" " autocomplete="current-password" required>
                    <label for="password" class="form-label">Password</label>
                    <div class="error-message <?php if (isset($errors['password'])): ?>visible<?php endif; ?>" id="password-error">
                        <?php if (isset($errors['password'])): ?>
                            <span class="material-symbols-outlined" style="font-size: 15px; vertical-align: middle; margin-right: 4px;">error</span>
                            <?= htmlspecialchars($errors['password']) ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Action Button -->
                <button type="submit" class="btn-login" id="btn-submit">
                    <span id="btn-text">Login</span>
                </button>
            </form>
        </div>
    </div>

    <!-- Client-side Validation Logic -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.getElementById('login-form');
            const emailInput = document.getElementById('email');
            const passwordInput = document.getElementById('password');
            const btnSubmit = document.getElementById('btn-submit');
            const btnText = document.getElementById('btn-text');
            const generalError = document.getElementById('general-error');
            const generalErrorText = document.getElementById('general-error-text');

            const emailError = document.getElementById('email-error');
            const passwordError = document.getElementById('password-error');

            function showValidationError(element, message) {
                element.innerHTML = `<span class="material-symbols-outlined" style="font-size: 15px; vertical-align: middle; margin-right: 4px;">error</span> ${message}`;
                element.classList.add('visible');
            }

            function clearValidationError(element) {
                element.classList.remove('visible');
                element.innerHTML = '';
            }

            // Input format validation helper
            function validateEmail(email) {
                const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                return re.test(String(email).toLowerCase());
            }

            // Clear errors on typing
            emailInput.addEventListener('input', () => {
                clearValidationError(emailError);
                generalError.style.display = 'none';
            });

            passwordInput.addEventListener('input', () => {
                clearValidationError(passwordError);
                generalError.style.display = 'none';
            });

            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                
                let hasErrors = false;
                const emailVal = emailInput.value.trim();
                const passwordVal = passwordInput.value;

                // Validation check
                if (!emailVal) {
                    showValidationError(emailError, 'Email address is required.');
                    hasErrors = true;
                } else if (!validateEmail(emailVal)) {
                    showValidationError(emailError, 'Please enter a valid email address.');
                    hasErrors = true;
                } else {
                    clearValidationError(emailError);
                }

                if (!passwordVal) {
                    showValidationError(passwordError, 'Password is required.');
                    hasErrors = true;
                } else {
                    clearValidationError(passwordError);
                }

                if (hasErrors) {
                    // Shake card to notify error visually
                    const card = document.querySelector('.login-card');
                    card.style.animation = 'none';
                    card.offsetHeight; // trigger reflow
                    card.style.animation = 'shake 0.4s ease-in-out';
                    return;
                }

                // Submitting via fetch for seamless UX
                btnSubmit.disabled = true;
                btnText.innerHTML = '<span class="spinner"></span>Logging in...';
                generalError.style.display = 'none';

                try {
                    const formData = new FormData(form);
                    const response = await fetch('index.php?json=1', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });

                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }

                    const data = await response.json();
                    
                    if (data.success) {
                        window.location.href = data.redirect || 'dashboard.php';
                    } else {
                        btnSubmit.disabled = false;
                        btnText.innerHTML = 'Login';
                        
                        if (data.errors) {
                            if (data.errors.email) {
                                showValidationError(emailError, data.errors.email);
                            }
                            if (data.errors.password) {
                                showValidationError(passwordError, data.errors.password);
                            }
                            if (data.errors.general) {
                                generalErrorText.textContent = data.errors.general;
                                generalError.style.display = 'flex';
                                generalError.style.animation = 'shake 0.4s ease-in-out';
                            }
                        }
                    }
                } catch (err) {
                    console.error('AJAX login error: ', err);
                    // Fallback to standard HTTP form post if fetch fails
                    form.submit();
                }
            });
        });
    </script>
</body>
</html>
