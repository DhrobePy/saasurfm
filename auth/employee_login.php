<?php
require_once __DIR__ . '/../core/init.php';

if (is_employee_logged_in()) {
    header('Location: ../employee/index.php');
    exit();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];
    $user = new User($pdo);
    if ($user->login($username, $password, 'employee')) {
        header('Location: ../employee/index.php');
        exit();
    } else {
        $error = 'Invalid login credentials.';
    }
}

$page_title = 'Employee Login';
include __DIR__ . '/../templates/header.php';
?>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card mt-5">
                <div class="card-header">Employee Login</div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>
                    <form action="employee_login.php" method="POST">
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" name="password" required>
                        </div>
                        <button type="submit" class="btn btn-primary">Login</button>
                        <a href="login.php" class="btn btn-link">Admin Login</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
include __DIR__ . '/../templates/footer.php';
?>
