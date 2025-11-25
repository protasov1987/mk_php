<?php
$config = require __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Трекер маршрутных карт ТСЗП</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header class="app-header">
    <div class="brand">
        <h1>Трекер маршрутных карт ТСЗП</h1>
        <div class="clock" id="clock"></div>
    </div>
    <nav class="nav-tabs">
        <button data-target="dashboard" class="active">Дашборд</button>
        <button data-target="cards">Тех. карты</button>
        <button data-target="tracker">Трекер</button>
        <button data-target="archive">Архив</button>
    </nav>
    <div class="banner" id="server-banner" hidden>Сервер недоступен, изменения временно не сохраняются.</div>
</header>

<main>
    <section id="dashboard" class="section active">
        <div class="grid two">
            <div class="card">
                <h2>Статистика по статусам</h2>
                <div id="status-stats" class="status-grid"></div>
            </div>
            <div class="card">
                <h2>Недавние карты</h2>
                <div id="recent-cards" class="list"></div>
            </div>
        </div>
    </section>

    <section id="cards" class="section">
        <div class="tabs">
            <button class="subtab active" data-subtab="cards-list">Карты</button>
            <button class="subtab" data-subtab="refs">Операции и участки</button>
        </div>
        <div class="subtab-content active" id="cards-list">
            <div class="toolbar">
                <input type="search" id="card-search" placeholder="Поиск по EAN, названию или заказу">
                <select id="card-status">
                    <option value="">Все статусы</option>
                    <option value="NOT_STARTED">Не начато</option>
                    <option value="IN_PROGRESS">В работе</option>
                    <option value="PAUSED">Пауза</option>
                    <option value="MIXED">Комбинированный</option>
                    <option value="DONE">Готово</option>
                </select>
                <button id="card-reset">Сброс</button>
                <button id="card-create" class="primary">Создать карту</button>
            </div>
            <table class="data-table" id="cards-table">
                <thead>
                    <tr><th>EAN</th><th>Название</th><th>Заказ</th><th>Статус</th><th>Действия</th></tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <div class="subtab-content" id="refs">
            <div class="grid two">
                <div class="card">
                    <h3>Добавить участок</h3>
                    <label>Название<input id="center-name" type="text"></label>
                    <label>Описание<textarea id="center-description"></textarea></label>
                    <button id="center-save" class="primary">Сохранить участок</button>
                    <div class="list" id="centers-list"></div>
                </div>
                <div class="card">
                    <h3>Добавить операцию</h3>
                    <label>Код<input id="op-code" type="text"></label>
                    <label>Название<input id="op-name" type="text"></label>
                    <label>Описание<textarea id="op-description"></textarea></label>
                    <label>Рекоменд. время (мин)<input id="op-time" type="number"></label>
                    <button id="op-save" class="primary">Сохранить операцию</button>
                    <div class="list" id="operations-list"></div>
                </div>
            </div>
        </div>
    </section>

    <section id="tracker" class="section">
        <div class="toolbar">
            <input type="search" id="tracker-search" placeholder="Поиск карты">
            <select id="tracker-status">
                <option value="">ALL</option>
                <option value="NOT_STARTED">NOT_STARTED</option>
                <option value="IN_PROGRESS">IN_PROGRESS</option>
                <option value="PAUSED">PAUSED</option>
                <option value="MIXED">MIXED</option>
                <option value="DONE">DONE</option>
            </select>
            <button id="tracker-reset">Сброс</button>
            <div class="hint">Управляйте статусами операций и добавляйте комментарии</div>
        </div>
        <table class="data-table" id="tracker-table">
            <thead><tr><th>EAN</th><th>Название</th><th>Статус</th><th>Описание</th></tr></thead>
            <tbody></tbody>
        </table>
    </section>

    <section id="archive" class="section">
        <div class="toolbar">
            <input type="search" id="archive-search" placeholder="Поиск карты">
            <select id="archive-status">
                <option value="">ALL</option>
                <option value="NOT_STARTED">NOT_STARTED</option>
                <option value="IN_PROGRESS">IN_PROGRESS</option>
                <option value="PAUSED">PAUSED</option>
                <option value="MIXED">MIXED</option>
                <option value="DONE">DONE</option>
            </select>
            <button id="archive-reset">Сброс</button>
            <p class="hint">Архив только для чтения. «Повторить» создаст новую карту с новым штрихкодом.</p>
        </div>
        <table class="data-table" id="archive-table">
            <thead><tr><th>EAN</th><th>Название</th><th>Заказ</th><th>Действия</th></tr></thead>
            <tbody></tbody>
        </table>
    </section>
</main>

<div class="modal" id="card-modal" hidden>
    <div class="modal-content">
        <h3 id="card-modal-title">Новая карта</h3>
        <label>Название<input type="text" id="modal-card-name"></label>
        <label>Количество<input type="number" id="modal-card-qty"></label>
        <label>Заказ<input type="text" id="modal-card-order"></label>
        <label>Материал<input type="text" id="modal-card-material"></label>
        <label>Чертёж<input type="text" id="modal-card-drawing"></label>
        <label>Описание<textarea id="modal-card-description"></textarea></label>
        <div class="modal-actions">
            <button id="card-modal-cancel">Отмена</button>
            <button id="card-modal-save" class="primary">Сохранить</button>
        </div>
    </div>
</div>

<script>
    const API_BASE = '/api';
    const uploadLimit = <?php echo (int)$config['max_upload_mb']; ?>;
</script>
<script src="app.js"></script>
</body>
</html>
