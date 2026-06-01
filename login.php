<?php
session_start();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';

    $host = 'localhost';
    $dbname = 'u82517';
    $username = 'u82517';
    $dbpass = '2297334';
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $dbpass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->prepare("SELECT * FROM users WHERE login = ?");
        $stmt->execute([$login]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['application_id'] = $user['application_id'];
            $_SESSION['login'] = $user['login'];
            header('Location: form.php');
            exit;
        } else {
            $error = "Неверный логин или пароль";
        }
    } catch (PDOException $e) {
        $error = "Ошибка БД: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Вход</title>
    <style>
        body { background: #111827; font-family: 'Segoe UI', sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; }
        .login-box { background: #1e293b; padding: 2rem; border-radius: 2rem; width: 300px; box-shadow: 0 0 20px rgba(0,255,255,0.2); }
        input { width: 100%; padding: 0.7rem; margin: 0.5rem 0; border-radius: 1rem; border: none; background: #0f172a; color: white; }
        button { background: #2dd4bf; border: none; padding: 0.7rem; border-radius: 2rem; width: 100%; font-weight: bold; cursor: pointer; }
        .error { color: #ef4444; margin-bottom: 1rem; }
        a { color: #2dd4bf; display: block; text-align: center; margin-top: 1rem; }
    </style>
</head>
<body>
<div class="login-box">
    <h2 style="color: white;">Вход</h2>
    <?php if (isset($error)) echo "<div class='error'>$error</div>"; ?>
    <form method="post">
        <input type="text" name="login" placeholder="Логин" required>
        <input type="password" name="password" placeholder="Пароль" required>
        <button type="submit">Войти</button>
    </form>
    <a href="form.php">← Вернуться к анкете</a>
</div>
</body>
</html>