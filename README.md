# Трекер маршрутных карт ТСЗП (PHP + MySQL)

Минимальная PHP/MySQL реализация одностраничного трекера маршрутных карт с REST‑подобным бэкендом.

## Быстрый старт
1. Создайте файл `.env` (или передайте переменные окружения) для подключения к MySQL:
   ```env
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_NAME=mk_tracker
   DB_USER=root
   DB_PASS=secret
   UPLOAD_DIR=/path/to/uploads
   MAX_UPLOAD_MB=15
   ```
2. Примените миграции:
   ```bash
   mysql -u$DB_USER -p$DB_PASS -h$DB_HOST -P$DB_PORT $DB_NAME < migrations/001_init.sql
   mysql -u$DB_USER -p$DB_PASS -h$DB_HOST -P$DB_PORT $DB_NAME < migrations/seed.sql
   ```
3. Разместите проект в корне виртуального хоста или настройте PHP built‑in server (REST вызовы идут на `/api/index.php/...`, так что переписывание URL не требуется):
   ```bash
   php -S 0.0.0.0:8000
   ```
4. Откройте `http://localhost:8000` и убедитесь, что `api/index.php/health` отвечает JSON `{"status":"ok"}`.
5. Если бэкенд временно недоступен, интерфейс покажет баннер «Сервер недоступен…» и переключится в офлайн-режим с временным хранением карт, операций и участков в LocalStorage.

## Основные возможности
- SPA‑подобные вкладки «Дашборд», «Тех. карты», «Трекер», «Архив» с поиском и фильтрами.
- CRUD техкарт, справочников операций и участков; генерация EAN‑13 на сервере.
- Эндпоинты `/api/cards`, `/api/cards/{id}`, `/api/cards/{id}/operations`, `/api/cards/{id}/log`, `/api/cards/{id}/attachments`, `/api/cards/{id}/archive`, `/api/cards/{id}/repeat`, `/api/operations`, `/api/centers`, `/api/attachments/{id}`, `/api/health`.
- Проверка расширений и лимит 15 МБ для вложений, хранение файлов во внешнем каталоге.
- Логирование изменений карт в таблице `logs` для последующего просмотра и печати.

## Структура
- `index.php` — интерфейс и модальные окна.
- `app.js` — переключение вкладок, загрузка данных через fetch, обновление таблиц.
- `api/index.php` — простая маршрутизация и JSON‑эндпоинты.
- `migrations/` — SQL для схемы и стартовых данных.

Все метки и тексты интерфейса оставлены на русском языке.
