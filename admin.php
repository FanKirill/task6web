<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ---------- Функция подключения к БД (DRY) ----------
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
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
    }
    return $pdo;
}

// ---------- HTTP-авторизация через таблицу admin ----------
function authenticateAdmin() {
    if (empty($_SERVER['PHP_AUTH_USER']) || empty($_SERVER['PHP_AUTH_PW']) ||
        $_SERVER['PHP_AUTH_USER'] !== 'admin' || $_SERVER['PHP_AUTH_PW'] !== 'admin') {
        header('HTTP/1.1 401 Unauthorized');
        header('WWW-Authenticate: Basic realm="Admin Area"');
        die('<h1>401 Требуется авторизация</h1>');
    }
}

function sendAuthHeaders() {
    header('HTTP/1.1 401 Unauthorized');
    header('WWW-Authenticate: Basic realm="Admin Area"');
}

// ---------- Валидация данных анкеты (общая для админа и пользователя) ----------
function validateApplication($data, &$errors) {
    $errors = [];
    $fullname = trim($data['fullname'] ?? '');
    if ($fullname === '') {
        $errors['fullname'] = 'ФИО обязательно';
    } elseif (!preg_match("/^[a-zA-Zа-яА-ЯёЁ\s\-]+$/u", $fullname)) {
        $errors['fullname'] = 'Только буквы, пробелы и дефис';
    } elseif (strlen($fullname) > 150) {
        $errors['fullname'] = 'Не более 150 символов';
    }

    $phone = trim($data['phone'] ?? '');
    if ($phone !== '') {
        if (!preg_match("/^[\+\d\s\-\(\)]+$/", $phone)) {
            $errors['phone'] = 'Недопустимые символы в телефоне';
        } elseif (strlen($phone) > 50) {
            $errors['phone'] = 'Телефон не длиннее 50 символов';
        }
    }

    $email = trim($data['email'] ?? '');
    if ($email === '') {
        $errors['email'] = 'E-mail обязателен';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Неверный формат email';
    } elseif (strlen($email) > 100) {
        $errors['email'] = 'E-mail не длиннее 100 символов';
    }

    $birthdate = trim($data['birthdate'] ?? '');
    if ($birthdate !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $birthdate);
        if (!$d || $d->format('Y-m-d') !== $birthdate) {
            $errors['birthdate'] = 'Неверная дата (ГГГГ-ММ-ДД)';
        } elseif ($d > new DateTime()) {
            $errors['birthdate'] = 'Дата не может быть в будущем';
        }
    }

    $gender = $data['gender'] ?? 'unspecified';
    if (!in_array($gender, ['male', 'female', 'other', 'unspecified'])) {
        $errors['gender'] = 'Некорректный пол';
    }

    $favLangs = $data['fav_langs'] ?? [];
    $allowedLangs = ['Pascal', 'C', 'C++', 'JavaScript', 'PHP', 'Python', 'Java', 'Haskell', 'Clojure', 'Prolog', 'Scala', 'Go'];
    if (empty($favLangs)) {
        $errors['fav_langs'] = 'Выберите хотя бы один язык';
    } elseif (count($favLangs) > 12) {
        $errors['fav_langs'] = 'Не более 12 языков';
    } else {
        foreach ($favLangs as $lang) {
            if (!in_array($lang, $allowedLangs)) {
                $errors['fav_langs'] = 'Недопустимый язык';
                break;
            }
        }
    }

    $bio = trim($data['bio'] ?? '');
    if ($bio !== '' && strlen($bio) > 10000) {
        $errors['bio'] = 'Биография не длиннее 10000 символов';
    }

    $contract = $data['contract_agreed'] ?? '';
    if ($contract !== 'on') {
        $errors['contract_agreed'] = 'Необходимо согласие';
    }
    return empty($errors);
}

// ---------- Обработка действий админа ----------
$pdo = getDB();
$message = '';
$error = '';

// 1. Удаление анкеты (и связанных записей)
if (isset($_GET['delete']) && ctype_digit($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    // Удаляем applications; из-за ON DELETE CASCADE удалятся application_languages и users
    $stmt = $pdo->prepare("DELETE FROM applications WHERE id = ?");
    if ($stmt->execute([$id])) {
        $message = "Анкета #$id удалена.";
    } else {
        $error = "Ошибка удаления.";
    }
}

// 2. Редактирование анкеты (сохранение изменений)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_id'])) {
    $editId = (int)$_POST['edit_id'];
    $data = [
        'fullname'       => $_POST['fullname'] ?? '',
        'phone'          => $_POST['phone'] ?? '',
        'email'          => $_POST['email'] ?? '',
        'birthdate'      => $_POST['birthdate'] ?? '',
        'gender'         => $_POST['gender'] ?? 'unspecified',
        'fav_langs'      => $_POST['fav_langs'] ?? [],
        'bio'            => $_POST['bio'] ?? '',
        'contract_agreed'=> $_POST['contract_agreed'] ?? ''
    ];
    $errors = [];
    if (validateApplication($data, $errors)) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE applications SET 
                fullname = ?, phone = ?, email = ?, birthdate = ?, gender = ?, biography = ?, contract_agreed = ?
                WHERE id = ?");
            $stmt->execute([
                $data['fullname'],
                $data['phone'] ?: null,
                $data['email'],
                $data['birthdate'] ?: null,
                $data['gender'],
                $data['bio'] ?: null,
                1,
                $editId
            ]);
            // Обновляем языки
            $stmtDel = $pdo->prepare("DELETE FROM application_languages WHERE application_id = ?");
            $stmtDel->execute([$editId]);
            $stmtLangId = $pdo->prepare("SELECT id FROM programming_languages WHERE name = ?");
            $stmtIns = $pdo->prepare("INSERT INTO application_languages (application_id, language_id) VALUES (?, ?)");
            foreach ($data['fav_langs'] as $lang) {
                $stmtLangId->execute([$lang]);
                $langId = $stmtLangId->fetchColumn();
                if ($langId) $stmtIns->execute([$editId, $langId]);
            }
            $pdo->commit();
            $message = "Анкета #$editId успешно обновлена.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Ошибка обновления: " . $e->getMessage();
        }
    } else {
        $error = "Ошибки валидации. Исправьте форму и попробуйте снова.";
        // Сохраним ошибки для отображения в форме редактирования
        $_SESSION['admin_edit_errors'] = $errors;
        $_SESSION['admin_edit_data'] = $data;
        header("Location: admin.php?edit=$editId");
        exit;
    }
}

// 3. Если есть GET-параметр edit – показываем форму редактирования
$editMode = false;
$editData = null;
if (isset($_GET['edit']) && ctype_digit($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM applications WHERE id = ?");
    $stmt->execute([$editId]);
    $editData = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($editData) {
        $editMode = true;
        // Получаем языки для этой анкеты
        $stmtLang = $pdo->prepare("SELECT pl.name FROM application_languages al 
                                    JOIN programming_languages pl ON al.language_id = pl.id 
                                    WHERE al.application_id = ?");
        $stmtLang->execute([$editId]);
        $editLangNames = $stmtLang->fetchAll(PDO::FETCH_COLUMN);
        $editData['fav_langs'] = $editLangNames;
        // Если были ошибки валидации, подставляем сохранённые данные и ошибки
        if (isset($_SESSION['admin_edit_errors'])) {
            $editErrors = $_SESSION['admin_edit_errors'];
            $editData = array_merge($editData, $_SESSION['admin_edit_data'] ?? []);
            unset($_SESSION['admin_edit_errors'], $_SESSION['admin_edit_data']);
        }
    } else {
        $error = "Анкета не найдена.";
    }
}

// ---------- HTTP-авторизация (вызываем после возможных действий, но до вывода) ----------
authenticateAdmin();

// ---------- Получение всех анкет для таблицы ----------
$applications = $pdo->query("SELECT * FROM applications ORDER BY id DESC")->fetchAll();

// ---------- Статистика по языкам ----------
$stats = $pdo->query("
    SELECT pl.name, COUNT(al.application_id) as cnt 
    FROM programming_languages pl
    LEFT JOIN application_languages al ON pl.id = al.language_id
    GROUP BY pl.id
    ORDER BY cnt DESC
")->fetchAll();

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Панель администратора</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f0f2f5; margin: 20px; }
        .container { max-width: 1400px; margin: auto; background: white; padding: 20px; border-radius: 16px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h1, h2 { color: #1e293b; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; vertical-align: top; }
        th { background: #2dd4bf; color: #0f172a; }
        tr:nth-child(even) { background: #f9fafb; }
        .btn { display: inline-block; padding: 6px 12px; margin: 2px; border-radius: 8px; text-decoration: none; font-size: 0.8rem; }
        .btn-edit { background: #3b82f6; color: white; }
        .btn-delete { background: #ef4444; color: white; }
        .btn-save { background: #10b981; color: white; border: none; cursor: pointer; }
        .form-edit { background: #f8fafc; padding: 15px; border-radius: 12px; margin-bottom: 20px; border: 1px solid #cbd5e1; }
        .form-group { margin-bottom: 10px; }
        label { display: inline-block; width: 180px; font-weight: bold; }
        input, select, textarea { padding: 5px; width: 300px; border-radius: 6px; border: 1px solid #ccc; }
        select[multiple] { width: 300px; height: 100px; }
        .error { color: #dc2626; font-size: 0.8rem; }
        .message { background: #d1fae5; color: #065f46; padding: 10px; border-radius: 8px; margin-bottom: 20px; }
        .error-msg { background: #fee2e2; color: #991b1b; padding: 10px; border-radius: 8px; margin-bottom: 20px; }
        .stats { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 20px; }
        .stat-card { background: #e0f2fe; padding: 8px 12px; border-radius: 20px; }
    </style>
</head>
<body>
<div class="container">
    <h1>👑 Панель администратора</h1>
    <?php if ($message): ?><div class="message"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="error-msg"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if ($editMode && $editData): ?>
        <div class="form-edit">
            <h2>✏️ Редактирование анкеты #<?= $editId ?></h2>
            <form method="post">
                <input type="hidden" name="edit_id" value="<?= $editId ?>">
                <div class="form-group"><label>ФИО *:</label> <input type="text" name="fullname" value="<?= htmlspecialchars($editData['fullname'] ?? '') ?>" required>
                    <?php if (!empty($editErrors['fullname'])): ?><div class="error"><?= $editErrors['fullname'] ?></div><?php endif; ?>
                </div>
                <div class="form-group"><label>Телефон:</label> <input type="text" name="phone" value="<?= htmlspecialchars($editData['phone'] ?? '') ?>">
                    <?php if (!empty($editErrors['phone'])): ?><div class="error"><?= $editErrors['phone'] ?></div><?php endif; ?>
                </div>
                <div class="form-group"><label>E-mail *:</label> <input type="email" name="email" value="<?= htmlspecialchars($editData['email'] ?? '') ?>" required>
                    <?php if (!empty($editErrors['email'])): ?><div class="error"><?= $editErrors['email'] ?></div><?php endif; ?>
                </div>
                <div class="form-group"><label>Дата рождения:</label> <input type="date" name="birthdate" value="<?= htmlspecialchars($editData['birthdate'] ?? '') ?>">
                    <?php if (!empty($editErrors['birthdate'])): ?><div class="error"><?= $editErrors['birthdate'] ?></div><?php endif; ?>
                </div>
                <div class="form-group"><label>Пол:</label>
                    <select name="gender">
                        <option value="male" <?= ($editData['gender']??'') == 'male' ? 'selected' : '' ?>>Мужской</option>
                        <option value="female" <?= ($editData['gender']??'') == 'female' ? 'selected' : '' ?>>Женский</option>
                        <option value="other" <?= ($editData['gender']??'') == 'other' ? 'selected' : '' ?>>Другой</option>
                        <option value="unspecified" <?= ($editData['gender']??'') == 'unspecified' ? 'selected' : '' ?>>Не указан</option>
                    </select>
                </div>
                <div class="form-group"><label>Языки *:</label>
                    <select name="fav_langs[]" multiple>
                        <?php
                        $allLangs = ['Pascal','C','C++','JavaScript','PHP','Python','Java','Haskell','Clojure','Prolog','Scala','Go'];
                        foreach ($allLangs as $lang):
                            $selected = in_array($lang, $editData['fav_langs'] ?? []) ? 'selected' : '';
                        ?>
                            <option value="<?= $lang ?>" <?= $selected ?>><?= $lang ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!empty($editErrors['fav_langs'])): ?><div class="error"><?= $editErrors['fav_langs'] ?></div><?php endif; ?>
                </div>
                <div class="form-group"><label>Биография:</label> <textarea name="bio" rows="3"><?= htmlspecialchars($editData['biography'] ?? '') ?></textarea>
                    <?php if (!empty($editErrors['bio'])): ?><div class="error"><?= $editErrors['bio'] ?></div><?php endif; ?>
                </div>
                <div class="form-group"><label>Согласие:</label> <input type="checkbox" name="contract_agreed" value="on" <?= ($editData['contract_agreed'] ?? 0) ? 'checked' : '' ?> required>
                    <?php if (!empty($editErrors['contract_agreed'])): ?><div class="error"><?= $editErrors['contract_agreed'] ?></div><?php endif; ?>
                </div>
                <div class="form-group"><button type="submit" class="btn btn-save">Сохранить изменения</button> <a href="admin.php" class="btn">Отмена</a></div>
            </form>
        </div>
    <?php endif; ?>

    <h2>📋 Все анкеты (<?= count($applications) ?>)</h2>
    <table>
        <thead>
            <tr><th>ID</th><th>ФИО</th><th>Телефон</th><th>Email</th><th>Дата рожд.</th><th>Пол</th><th>Языки</th><th>Биография</th><th>Согласие</th><th>Действия</th></tr>
        </thead>
        <tbody>
        <?php foreach ($applications as $app): 
            // Получаем языки для каждой анкеты
            $stmtLang = $pdo->prepare("SELECT pl.name FROM application_languages al JOIN programming_languages pl ON al.language_id = pl.id WHERE al.application_id = ?");
            $stmtLang->execute([$app['id']]);
            $langs = $stmtLang->fetchAll(PDO::FETCH_COLUMN);
            $langList = implode(', ', $langs);
        ?>
            <tr>
                <td><?= $app['id'] ?></td>
                <td><?= htmlspecialchars($app['fullname']) ?></td>
                <td><?= htmlspecialchars($app['phone']) ?></td>
                <td><?= htmlspecialchars($app['email']) ?></td>
                <td><?= $app['birthdate'] ?></td>
                <td><?= $app['gender'] ?></td>
                <td><?= htmlspecialchars($langList) ?></td>
                <td><?= htmlspecialchars(substr($app['biography'] ?? '', 0, 100)) ?>...</td>
                <td><?= $app['contract_agreed'] ? 'Да' : 'Нет' ?></td>
                <td>
                    <a href="admin.php?edit=<?= $app['id'] ?>" class="btn btn-edit">Редактировать</a>
                    <a href="admin.php?delete=<?= $app['id'] ?>" class="btn btn-delete" onclick="return confirm('Удалить анкету #<?= $app['id'] ?>?')">Удалить</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2>📊 Статистика по языкам программирования</h2>
    <div class="stats">
        <?php foreach ($stats as $stat): ?>
            <div class="stat-card"><?= htmlspecialchars($stat['name']) ?>: <?= $stat['cnt'] ?> пользователей</div>
        <?php endforeach; ?>
    </div>
</div>
</body>
</html>
