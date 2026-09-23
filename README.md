# TasksBoard PWA — MySQL Edition

A PHP + MySQL PWA for task management with real browser push notifications.

---

## Quick Setup

### 1. Database
```bash
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS taskboard CHARACTER SET utf8mb4;"
mysql -u root -p taskboard < data/create_users.sql
mysql -u root -p taskboard < data/create_taskboard_tables.sql
```

Edit **`db.php`** with your MySQL credentials.

### 2. VAPID Keys (required for push notifications)
```bash
# Option A — Node.js (easiest)
npx web-push generate-vapid-keys

# Option B — PHP (after composer install)
php -r "require 'vendor/autoload.php'; \
  \$k = Minishlink\WebPush\VAPID::createVapidKeys(); \
  echo json_encode(\$k, JSON_PRETTY_PRINT);"
```
Copy `vapid.php.example` → `vapid.php` and paste your keys.

### 3. Install WebPush Library
```bash
composer require minishlink/web-push
```
> The bundled `web-push-php-master/` folder is a fallback if Composer isn't available.

### 4. Cron Job (fires notifications at due time)
```cron
* * * * *  php /var/www/html/taskboard/send_due_notifications.php >> /tmp/tb_notif.log 2>&1
```
This runs every minute and sends a push notification for any task whose due time has passed.

### 5. Google OAuth (optional, for login)
Put your credentials in the Google Cloud Console, then update `auth.php`.

---

## Notification Architecture

```
Browser                         Server
──────                          ──────
1. User clicks 🔔 bell
2. Notification.requestPermission()
3. SW registers (sw.js)
4. PushManager.subscribe()  →  push.php  →  push_subscriptions table
                                              (endpoint, p256dh, auth)

Cron (every minute)
send_due_notifications.php
  → queries tasks WHERE due <= NOW() AND NOT IN notifications_sent
  → WebPush::send()  →  Browser push service  →  sw.js push event
  → sw.js shows notification toast
```

---

## Files Changed / Fixed (v2)

| File | What was fixed |
|------|----------------|
| `sw.js` | Added proper `push` event handler with actions; fixed `notificationclick` to focus existing tab |
| `app.js` | SW now **awaited** before push subscribe; `getSubscription()` check prevents duplicate subscriptions; re-subscribes on page reload if permission already granted |
| `push.php` | Accepts both `{ subscription: {...} }` and direct `{endpoint, keys}` formats |
| `send_due_notifications.php` | Fixed SQL (`board_lists` table name); auto-removes stale 404/410 subscriptions; `INSERT IGNORE` prevents duplicate sent records |
| `vapid_public.php` | Better error messages for missing/empty config |
| `data/create_taskboard_tables.sql` | Added `UNIQUE KEY` on `notifications_sent.task_id` to prevent double-sends |
| `vapid.php.example` | New: key generation instructions |

---

## Troubleshooting

**Notifications not showing?**
1. Open DevTools → Application → Service Workers — is `sw.js` Active?
2. DevTools → Application → Notifications — is permission "Granted"?
3. DevTools → Application → Push Messaging — is there a subscription endpoint?
4. Check `data/push_debug.log` — does push.php receive the subscription?
5. Run `php send_due_notifications.php` manually — any errors?
6. Verify your VAPID keys match between `vapid.php` and what was used to create the subscription.

**"Failed to subscribe" in console?**
- Make sure the site is served over **HTTPS** (or `localhost` for dev). Push API requires a secure context.
- Check that `vapid_public.php` returns a valid base64url public key.

**Cron not running?**
- Test manually: `php send_due_notifications.php`
- Check PHP error log for exceptions.
- Make sure `notifications_sent` table exists with the `UNIQUE KEY` on `task_id`.
