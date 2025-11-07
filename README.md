## 🧂 WP Salts Rotator

**Automatically refresh your WordPress secret keys & salts every 30 days (by default).**
This plugin securely rotates all your authentication keys and salts stored in `wp-config.php` — either automatically or manually — helping strengthen your WordPress security by invalidating all old sessions and cookies.

---

### 🔐 Features

* Automatically **regenerates and replaces** all 8 WordPress salts:

  * `AUTH_KEY`
  * `SECURE_AUTH_KEY`
  * `LOGGED_IN_KEY`
  * `NONCE_KEY`
  * `AUTH_SALT`
  * `SECURE_AUTH_SALT`
  * `LOGGED_IN_SALT`
  * `NONCE_SALT`
* Uses official WordPress API:
  👉 [`https://api.wordpress.org/secret-key/1.1/salt/`](https://api.wordpress.org/secret-key/1.1/salt/)
* Automatically schedules **rotation every 30 days** (via WP-Cron)
* Allows **manual rotation** via admin panel
* Displays the **current salts** directly in the dashboard
* Creates an automatic **backup** of your `wp-config.php` before every update
* Fully compatible with standard WordPress setups

---

### ⚙️ Installation

1. Download or clone the repository into your WordPress plugins directory:

   ```bash
   wp-content/plugins/wp-salts-rotator/
   ```

2. Activate the plugin via **WordPress Admin → Plugins**.

3. Navigate to
   **Settings → Salt Rotator** to:

   * View your current salts
   * Rotate them manually
   * View last rotation time/status

---

### 🕒 Automatic Rotation

* Runs automatically every **30 days** by default.
* Uses the WordPress Cron system to trigger rotations.

A cron schedule named `every_30_days` is registered, running this task:

```php
define('WP_CRON_LOCK_TIMEOUT', 60);
wp_schedule_event(time(), 'every_30_days', 'wpr_salts_rotate_event');
```

---

### 🧰 Manual Rotation

You can rotate the salts instantly from
**Settings → Salt Rotator → Rotate now (manual)**

All users will be logged out immediately after rotation.

---

### 🧾 Backups

Before updating, the plugin automatically creates a timestamped backup file:

```
wp-config.php.wprbak.YYYYMMDD-HHMMSS
```

You can restore any backup manually if necessary.

---

### ⚠️ Important Notes

* **All logged-in users will be logged out** after salts are rotated (this is a security feature).
* The plugin **requires write access** to your `wp-config.php`.
* Works with both standard and one-level-up `wp-config.php` locations.
* The plugin uses **PHP cURL** to fetch new salts — ensure cURL is enabled on your server.

---

### 🧠 How It Works

1. The plugin uses **cURL** to fetch new salts:

   ```
   https://api.wordpress.org/secret-key/1.1/salt/
   ```
2. It parses the 8 `define()` statements returned.
3. It replaces or inserts them inside `wp-config.php`.
4. A backup is created before any change.
5. A log of the last rotation is saved in WordPress options.

---

### 🩶 Example Screenshot (Admin Page)

```
------------------------------------------------------
| WP Salts Rotator                                   |
------------------------------------------------------
| Current Keys & Salts                               |
| AUTH_KEY         define('AUTH_KEY', '****...');    |
| ...                                                 |
------------------------------------------------------
| [Rotate now (manual)]                              |
| Last rotation: 7th November 2025 - success         |
------------------------------------------------------
```

---

### 💬 Contributing

Feel free to submit pull requests or open issues on GitHub for:

* Feature requests
* Bug reports
* Security improvements

---

### 🧾 License

This plugin is released under the **GPLv2 or later** license.
See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html) for details.

---

### 🛡️ Security Notice

Rotating salts **invalidates all user sessions** (forcing re-login).
This is normal and ensures old cookies cannot be reused by attackers.
