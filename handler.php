<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Подключение к БД
$host = 'localhost';
$dbname = 'u82517';
$username = 'u82517';
$password = '2297334';
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Ошибка БД: " . $e->getMessage());
}

// Валидация (полностью как в задании 4)
$errors = [];
$fullname = trim($_POST['fullname'] ?? '');
if ($fullname === '') $errors['fullname'] = 'ФИО обязательно для заполнения';
elseif (!preg_match("/^[a-zA-Zа-яА-ЯёЁ\s\-]+$/u", $fullname)) $errors['fullname'] = 'Допустимы только буквы, пробелы и дефис';
elseif (mb_strlen($fullname) > 150) $errors['fullname'] = 'ФИО не должно превышать 150 символов';

$phone = trim($_POST['phone'] ?? '');
if ($phone !== '') {
    if (!preg_match("/^[\+\d\s\-\(\)]+$/", $phone)) $errors['phone'] = 'Допустимы только цифры, +, пробелы, скобки и дефис';
    elseif (mb_strlen($phone) > 50) $errors['phone'] = 'Телефон не должен превышать 50 символов';
}

$email = trim($_POST['email'] ?? '');
if ($email === '') $errors['email'] = 'E-mail обязателен для заполнения';
elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Некорректный формат e-mail';
elseif (mb_strlen($email) > 100) $errors['email'] = 'E-mail не должен превышать 100 символов';

$birthdate = trim($_POST['birthdate'] ?? '');
if ($birthdate !== '') {
    if (!preg_match("/^\d{4}\-\d{2}\-\d{2}$/", $birthdate)) $errors['birthdate'] = 'Некорректный формат даты (ГГГГ-ММ-ДД)';
    else {
        $d = DateTime::createFromFormat('Y-m-d', $birthdate);
        if (!$d || $d->format('Y-m-d') !== $birthdate) $errors['birthdate'] = 'Дата не существует';
        elseif ($d > new DateTime()) $errors['birthdate'] = 'Дата не может быть в будущем';
    }
}

$gender = $_POST['gender'] ?? 'unspecified';
if (!in_array($gender, ['male', 'female', 'other', 'unspecified'])) $errors['gender'] = 'Некорректное значение пола';

$favLangs = $_POST['fav_langs'] ?? [];
$allowedLangs = ['Pascal', 'C', 'C++', 'JavaScript', 'PHP', 'Python', 'Java', 'Haskell', 'Clojure', 'Prolog', 'Scala', 'Go'];
if (!is_array($favLangs) || count($favLangs) === 0) $errors['fav_langs'] = 'Выберите хотя бы один язык';
elseif (count($favLangs) > 12) $errors['fav_langs'] = 'Выбрано слишком много языков (макс. 12)';
else {
    foreach ($favLangs as $lang) {
        if (!in_array($lang, $allowedLangs)) {
            $errors['fav_langs'] = 'Недопустимый язык';
            break;
        }
    }
}

$bio = trim($_POST['bio'] ?? '');
if ($bio !== '' && mb_strlen($bio) > 10000) $errors['bio'] = 'Биография не должна превышать 10000 символов';

$contract = $_POST['contract_agreed'] ?? '';
if ($contract !== 'on') $errors['contract_agreed'] = 'Необходимо принять условия соглашения';

// Если есть ошибки – сохраняем в куки и редирект (как в задании 4)
if (!empty($errors)) {
    setcookie('form_errors', json_encode($errors), 0, '/');
    setcookie('form_old_values', json_encode($_POST), 0, '/');
    header('Location: form.php');
    exit;
}

// ---- Ошибок нет ----
// Проверяем, авторизован ли пользователь (есть сессия)
if (isset($_SESSION['user_id']) && isset($_SESSION['application_id'])) {
    // ** РЕДАКТИРОВАНИЕ **
    $appId = $_SESSION['application_id'];
    try {
        $pdo->beginTransaction();
        // Обновляем основную запись
        $stmt = $pdo->prepare("UPDATE applications SET 
            fullname = ?, phone = ?, email = ?, birthdate = ?, gender = ?, biography = ?, contract_agreed = ?
            WHERE id = ?");
        $stmt->execute([$fullname, $phone ?: null, $email, $birthdate ?: null, $gender, $bio ?: null, 1, $appId]);
        // Удаляем старые языки
        $stmtDel = $pdo->prepare("DELETE FROM application_languages WHERE application_id = ?");
        $stmtDel->execute([$appId]);
        // Вставляем новые
        $stmtLangId = $pdo->prepare("SELECT id FROM programming_languages WHERE name = ?");
        $stmtIns = $pdo->prepare("INSERT INTO application_languages (application_id, language_id) VALUES (?, ?)");
        foreach ($favLangs as $lang) {
            $stmtLangId->execute([$lang]);
            $langId = $stmtLangId->fetchColumn();
            if ($langId) $stmtIns->execute([$appId, $langId]);
        }
        $pdo->commit();

        // Обновляем куку saved_data (для неавторизованных, но всё равно обновим)
        $saveData = [
            'fullname' => $fullname, 'phone' => $phone, 'email' => $email,
            'birthdate' => $birthdate, 'gender' => $gender, 'fav_langs' => $favLangs,
            'bio' => $bio, 'contract_agreed' => 'on'
        ];
        setcookie('saved_data', json_encode($saveData), time() + 365*24*3600, '/');
        setcookie('saved_success', '1', 0, '/');

        $_SESSION['flash_message'] = "Данные успешно обновлены!";
        header('Location: form.php');
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $errors['db'] = "Ошибка обновления: " . $e->getMessage();
        setcookie('form_errors', json_encode($errors), 0, '/');
        setcookie('form_old_values', json_encode($_POST), 0, '/');
        header('Location: form.php');
        exit;
    }
} else {
    // ** НОВЫЙ ПОЛЬЗОВАТЕЛЬ **
    try {
        $pdo->beginTransaction();
        // Вставка в applications
        $stmt = $pdo->prepare("INSERT INTO applications (fullname, phone, email, birthdate, gender, biography, contract_agreed)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$fullname, $phone ?: null, $email, $birthdate ?: null, $gender, $bio ?: null, 1]);
        $appId = $pdo->lastInsertId();

        // Вставка языков
        $stmtLangId = $pdo->prepare("SELECT id FROM programming_languages WHERE name = ?");
        $stmtIns = $pdo->prepare("INSERT INTO application_languages (application_id, language_id) VALUES (?, ?)");
        foreach ($favLangs as $lang) {
            $stmtLangId->execute([$lang]);
            $langId = $stmtLangId->fetchColumn();
            if ($langId) $stmtIns->execute([$appId, $langId]);
        }

        // Генерация логина и пароля
        $login = 'user_' . $appId; // уникальный логин
        $plainPassword = substr(bin2hex(random_bytes(5)), 0, 10); // 10 символов
        $hash = password_hash($plainPassword, PASSWORD_DEFAULT);

        // Сохраняем в таблицу users
        $stmtUser = $pdo->prepare("INSERT INTO users (login, password_hash, application_id) VALUES (?, ?, ?)");
        $stmtUser->execute([$login, $hash, $appId]);

        $pdo->commit();

        // Сохраняем данные в куки (для удобства неавторизованного)
        $saveData = [
            'fullname' => $fullname, 'phone' => $phone, 'email' => $email,
            'birthdate' => $birthdate, 'gender' => $gender, 'fav_langs' => $favLangs,
            'bio' => $bio, 'contract_agreed' => 'on'
        ];
        setcookie('saved_data', json_encode($saveData), time() + 365*24*3600, '/');
        setcookie('saved_success', '1', 0, '/');

        // Сохраняем логин/пароль в сессионное сообщение (одноразовое)
        $_SESSION['flash_message'] = "Ваша анкета сохранена! Логин: $login, пароль: $plainPassword. Сохраните их для редактирования.";
        header('Location: form.php');
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $errors['db'] = "Ошибка сохранения: " . $e->getMessage();
        setcookie('form_errors', json_encode($errors), 0, '/');
        setcookie('form_old_values', json_encode($_POST), 0, '/');
        header('Location: form.php');
        exit;
    }
}
