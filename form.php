<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Подключение к БД (для авторизованных)
$pdo = null;
if (isset($_SESSION['user_id']) && isset($_SESSION['application_id'])) {
    $host = 'localhost';
    $dbname = 'u82517';
    $username = 'u82517';
    $password = '2297334';
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Загружаем данные анкеты
        $stmt = $pdo->prepare("SELECT * FROM applications WHERE id = ?");
        $stmt->execute([$_SESSION['application_id']]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);
        // Загружаем языки
        $stmtLang = $pdo->prepare("
            SELECT pl.name FROM application_languages al
            JOIN programming_languages pl ON al.language_id = pl.id
            WHERE al.application_id = ?
        ");
        $stmtLang->execute([$_SESSION['application_id']]);
        $langs = $stmtLang->fetchAll(PDO::FETCH_COLUMN);
        if ($userData) {
            // Сохраняем в переменную для подстановки в форму
            $values = [
                'fullname' => $userData['fullname'],
                'phone' => $userData['phone'],
                'email' => $userData['email'],
                'birthdate' => $userData['birthdate'],
                'gender' => $userData['gender'],
                'fav_langs' => $langs,
                'bio' => $userData['biography'],
                'contract_agreed' => $userData['contract_agreed'] ? 'on' : ''
            ];
        }
    } catch (PDOException $e) {
        // Если ошибка БД, не показываем данные, просто работаем с куками
        $values = [];
    }
}

// Если не авторизован или не загрузились данные – используем куки (задание 4)
if (!isset($values)) {
    // Загружаем ошибки и старые значения из кук
    $errors = [];
    $old = [];
    if (isset($_COOKIE['form_errors'])) {
        $errors = json_decode($_COOKIE['form_errors'], true);
        setcookie('form_errors', '', time() - 3600, '/');
    }
    if (isset($_COOKIE['form_old_values'])) {
        $old = json_decode($_COOKIE['form_old_values'], true);
        setcookie('form_old_values', '', time() - 3600, '/');
    }
    // Загружаем успешно сохранённые данные из кук (живут 1 год)
    $saved = [];
    if (empty($errors) && isset($_COOKIE['saved_data'])) {
        $saved = json_decode($_COOKIE['saved_data'], true);
    }
    // Приоритет: ошибки -> saved -> пусто
    $values = !empty($old) ? $old : $saved;
}

// Функции для вывода
function val($key, $default = '') {
    global $values;
    return htmlspecialchars($values[$key] ?? $default);
}
function isChecked($key, $val) {
    global $values;
    return (isset($values[$key]) && $values[$key] === $val) ? 'checked' : '';
}
function isLangSelected($lang) {
    global $values;
    return (isset($values['fav_langs']) && is_array($values['fav_langs']) && in_array($lang, $values['fav_langs'])) ? 'selected' : '';
}
function isContractChecked() {
    global $values;
    return (isset($values['contract_agreed']) && $values['contract_agreed'] === 'on') ? 'checked' : '';
}
function fieldClass($key) {
    global $errors;
    return isset($errors[$key]) ? 'field-error' : '';
}
function errorMsg($key) {
    global $errors;
    return isset($errors[$key]) ? '<span class="error-text">' . htmlspecialchars($errors[$key]) . '</span>' : '';
}

// Одноразовое сообщение (например, о сгенерированном пароле)
if (isset($_SESSION['flash_message'])) {
    $flash = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Анкета разработчика</title>
    <style>
        /* ВСЕ СТИЛИ ОСТАЮТСЯ ТОЧНО ТАКИМИ ЖЕ, КАК В ВАШЕМ form.php из задания 4 */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: radial-gradient(circle at 10% 30%, #0b1120, #111827);
            font-family: 'Inter', 'Segoe UI', sans-serif;
            padding: 2rem 1.5rem;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .form-container {
            max-width: 980px;
            width: 100%;
            background: rgba(18, 25, 45, 0.75);
            backdrop-filter: blur(14px);
            border-radius: 3rem;
            box-shadow: 0 30px 50px -20px rgba(0, 0, 0, 0.6), 0 0 0 1px rgba(0, 255, 255, 0.15);
            overflow: hidden;
        }
        .form-header {
            background: linear-gradient(115deg, #0a0f1f 0%, #10172b 100%);
            padding: 2rem 2.5rem;
            border-bottom: 1px solid rgba(0, 255, 255, 0.25);
        }
        .form-header h1 {
            font-weight: 700;
            font-size: 2rem;
            background: linear-gradient(135deg, #FFFFFF, #7dd3fc);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin-bottom: 0.5rem;
        }
        .form-header p {
            font-size: 0.9rem;
            color: #94a3b8;
            border-left: 3px solid #2dd4bf;
            padding-left: 0.75rem;
        }
        .form-body { padding: 2.2rem 2.5rem; }
        .field-group {
            margin-bottom: 1.8rem;
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            gap: 0.75rem;
        }
        .field-group > label {
            width: 180px;
            font-weight: 600;
            color: #cbd5e6;
            font-size: 0.9rem;
            padding-top: 0.7rem;
            flex-shrink: 0;
        }
        .input-wrapper { flex: 1; min-width: 220px; }
        input, select, textarea {
            width: 100%;
            padding: 0.8rem 1.2rem;
            background: #0f172ad9;
            border: 1px solid #2d3a5e;
            border-radius: 1.5rem;
            font-size: 0.9rem;
            font-family: 'Inter', monospace;
            color: #f1f5f9;
            outline: none;
            transition: all 0.25s ease;
        }
        input:focus, select:focus, textarea:focus {
            border-color: #2dd4bf;
            box-shadow: 0 0 0 3px rgba(45, 212, 191, 0.25);
            background: #0b1122;
        }
        input::placeholder, textarea::placeholder {
            color: #5b6e8c;
        }
        .field-error {
            border-color: #ef4444 !important;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.3) !important;
        }
        .error-text {
            color: #ef4444;
            font-size: 0.8rem;
            display: block;
            margin-top: 0.3rem;
            margin-left: 0.5rem;
        }
        .radio-group {
            display: flex;
            gap: 2rem;
            align-items: center;
            flex-wrap: wrap;
            background: #0f172a80;
            padding: 0.55rem 1.2rem;
            border-radius: 2rem;
            border: 1px solid #2d3a5e;
        }
        .radio-group label {
            width: auto;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            color: #cbd5f0;
            padding-top: 0;
        }
        .radio-group input { width: 18px; height: 18px; accent-color: #2dd4bf; }
        .checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            background: #0f172a80;
            padding: 0.65rem 1.2rem;
            border-radius: 2rem;
            border: 1px solid #2d3a5e;
        }
        .checkbox-wrapper input { width: 20px; height: 20px; accent-color: #2dd4bf; }
        .checkbox-wrapper label { font-weight: 500; cursor: pointer; color: #e2e8ff; }
        select[multiple] { min-height: 150px; background: #0f172ad9; border-radius: 1.2rem; padding: 0.5rem; }
        select[multiple] option {
            padding: 0.7rem 1rem;
            border-radius: 1rem;
            margin-bottom: 4px;
            background: #1e293b;
            color: #e2e8f0;
        }
        select[multiple] option:checked {
            background: #2dd4bf linear-gradient(0deg, #14b8a6 0%, #2dd4bf 100%);
            color: #0f172a;
            font-weight: 600;
        }
        textarea { resize: vertical; min-height: 110px; line-height: 1.55; border-radius: 1.5rem; }
        .action-buttons {
            margin-top: 2.2rem;
            text-align: right;
            border-top: 1px solid #1e2a44;
            padding-top: 1.8rem;
        }
        .save-btn {
            background: linear-gradient(105deg, #2dd4bf, #0f766e);
            border: none;
            padding: 0.85rem 2.5rem;
            font-size: 1rem;
            font-weight: 700;
            border-radius: 3rem;
            color: #0f172a;
            cursor: pointer;
            transition: 0.25s ease;
            box-shadow: 0 6px 14px rgba(0, 0, 0, 0.3);
        }
        .save-btn:hover {
            transform: translateY(-3px);
            background: linear-gradient(105deg, #5eead4, #2dd4bf);
            box-shadow: 0 18px 28px -10px rgba(45, 212, 191, 0.4);
        }
        .helper-text { font-size: 0.7rem; color: #7e8eb3; margin-top: 0.4rem; }
        .success-msg {
            background: rgba(45, 212, 191, 0.2);
            border: 1px solid #2dd4bf;
            color: #2dd4bf;
            padding: 1rem;
            border-radius: 1rem;
            margin-bottom: 1.5rem;
            font-weight: 600;
        }
        .errors-block {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid #ef4444;
            color: #fca5a5;
            padding: 1rem;
            border-radius: 1rem;
            margin-bottom: 1.5rem;
        }
        .login-bar {
            text-align: right;
            margin-bottom: 1rem;
            padding: 0.5rem 0;
            border-bottom: 1px dashed #2d3a5e;
        }
        .login-bar a {
            color: #2dd4bf;
            text-decoration: none;
            margin-left: 1rem;
        }
        @media (max-width: 720px) {
            .form-body { padding: 1.5rem; }
            .field-group { flex-direction: column; }
            .field-group > label { width: 100%; padding-top: 0; }
            .radio-group { gap: 1rem; }
        }
        input[type="date"]::-webkit-calendar-picker-indicator { filter: invert(0.7); cursor: pointer; }
        select[multiple]::-webkit-scrollbar { width: 6px; }
        select[multiple]::-webkit-scrollbar-track { background: #1e293b; border-radius: 10px; }
        select[multiple]::-webkit-scrollbar-thumb { background: #2dd4bf; border-radius: 10px; }
    </style>
</head>
<body>
<div class="form-container">
    <div class="form-header">
        <h1>⚡ dev·анкета</h1>
        <p>заполните профиль разработчика — данные остаются в безопасности</p>
    </div>
    <div class="form-body">
        <!-- Блок статуса (авторизация / выход) -->
        <div class="login-bar">
            <?php if (isset($_SESSION['user_id'])): ?>
                Вы вошли как <?= htmlspecialchars($_SESSION['login'] ?? '') ?>
                <a href="logout.php">[Выйти]</a>
            <?php else: ?>
                <a href="login.php">Войти для редактирования</a>
            <?php endif; ?>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="errors-block">⚠️ Пожалуйста, исправьте ошибки в отмеченных полях</div>
        <?php endif; ?>

        <?php if (isset($flash)): ?>
            <div class="success-msg"><?= htmlspecialchars($flash) ?></div>
        <?php endif; ?>

        <form method="POST" action="handler.php">
            <!-- ФИО -->
            <div class="field-group">
                <label for="fullname">🧑‍💻 ФИО *</label>
                <div class="input-wrapper">
                    <input type="text" id="fullname" name="fullname"
                           class="<?= fieldClass('fullname') ?>"
                           placeholder="Иванов Иван Иванович"
                           value="<?= val('fullname') ?>">
                    <?= errorMsg('fullname') ?>
                </div>
            </div>

            <!-- Телефон -->
            <div class="field-group">
                <label for="phone">📱 Телефон</label>
                <div class="input-wrapper">
                    <input type="text" id="phone" name="phone"
                           class="<?= fieldClass('phone') ?>"
                           placeholder="+7 (999) 123-45-67"
                           value="<?= val('phone') ?>">
                    <?= errorMsg('phone') ?>
                </div>
            </div>

            <!-- Email -->
            <div class="field-group">
                <label for="email">✉️ E-mail *</label>
                <div class="input-wrapper">
                    <input type="text" id="email" name="email"
                           class="<?= fieldClass('email') ?>"
                           placeholder="hello@example.com"
                           value="<?= val('email') ?>">
                    <?= errorMsg('email') ?>
                </div>
            </div>

            <!-- Дата рождения -->
            <div class="field-group">
                <label for="birthdate">🎂 Дата рождения</label>
                <div class="input-wrapper">
                    <input type="date" id="birthdate" name="birthdate"
                           class="<?= fieldClass('birthdate') ?>"
                           value="<?= val('birthdate') ?>">
                    <?= errorMsg('birthdate') ?>
                </div>
            </div>

            <!-- Пол -->
            <div class="field-group">
                <label>⚥ Пол</label>
                <div class="input-wrapper radio-group">
                    <label><input type="radio" name="gender" value="male" <?= isChecked('gender', 'male') ?>> Мужской</label>
                    <label><input type="radio" name="gender" value="female" <?= isChecked('gender', 'female') ?>> Женский</label>
                    <label><input type="radio" name="gender" value="other" <?= isChecked('gender', 'other') ?>> Другой</label>
                    <label><input type="radio" name="gender" value="unspecified" <?= (!isset($values['gender']) || isChecked('gender', 'unspecified')) ? 'checked' : '' ?>> Не указан</label>
                </div>
                <?= errorMsg('gender') ?>
            </div>

            <!-- Языки -->
            <div class="field-group">
                <label>💻 Любимые языки *</label>
                <div class="input-wrapper">
                    <select name="fav_langs[]" id="fav_langs" multiple size="6" class="<?= fieldClass('fav_langs') ?>">
                        <option value="Pascal" <?= isLangSelected('Pascal') ?>>Pascal</option>
                        <option value="C" <?= isLangSelected('C') ?>>C</option>
                        <option value="C++" <?= isLangSelected('C++') ?>>C++</option>
                        <option value="JavaScript" <?= isLangSelected('JavaScript') ?>>JavaScript</option>
                        <option value="PHP" <?= isLangSelected('PHP') ?>>PHP</option>
                        <option value="Python" <?= isLangSelected('Python') ?>>Python</option>
                        <option value="Java" <?= isLangSelected('Java') ?>>Java</option>
                        <option value="Haskell" <?= isLangSelected('Haskell') ?>>Haskell</option>
                        <option value="Clojure" <?= isLangSelected('Clojure') ?>>Clojure</option>
                        <option value="Prolog" <?= isLangSelected('Prolog') ?>>Prolog</option>
                        <option value="Scala" <?= isLangSelected('Scala') ?>>Scala</option>
                        <option value="Go" <?= isLangSelected('Go') ?>>Go</option>
                    </select>
                    <div class="helper-text">⌘ Ctrl / Cmd + клик для выбора нескольких языков</div>
                    <?= errorMsg('fav_langs') ?>
                </div>
            </div>

            <!-- Биография -->
            <div class="field-group">
                <label for="bio">📝 Биография</label>
                <div class="input-wrapper">
                    <textarea id="bio" name="bio" rows="4" class="<?= fieldClass('bio') ?>"
                              placeholder="Расскажите о своём опыте в разработке, проектах и интересах..."><?= val('bio') ?></textarea>
                    <?= errorMsg('bio') ?>
                </div>
            </div>

            <!-- Контракт -->
            <div class="field-group">
                <label>📑 Согласие</label>
                <div class="input-wrapper checkbox-wrapper">
                    <input type="checkbox" id="contractCheck" name="contract_agreed" <?= isContractChecked() ?>>
                    <label for="contractCheck">Я принимаю условия обработки данных и пользовательского соглашения *</label>
                </div>
                <?= errorMsg('contract_agreed') ?>
            </div>

            <div class="action-buttons">
                <button type="submit" class="save-btn">✨ Сохранить анкету</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>