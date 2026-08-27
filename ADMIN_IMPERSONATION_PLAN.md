# Admin "Login as Student" (Impersonation) — Implementation Plan

> Goal: Let the admin survey any student's account to find bugs and understand the
> student experience, **without** ever storing or exposing student passwords.

## Why impersonation (not showing passwords)
- `auth/login_process.php:30` verifies logins against a **bcrypt hash** via `password_verify()`.
- Plaintext passwords are **not recoverable** from the database.
- Storing passwords in cleartext would be a serious security flaw.
- **Impersonation** achieves the real objective (the admin experiences the exact student
  UI to find bugs / understand their journey) and does it safely.

### Decisions (delegated to recommendation)
| Question | Chosen |
| --- | --- |
| Access mechanism | Impersonation gated by a **special survey key** (separate password) |
| Suspended / blocked / holiday students | Admin **bypasses** those restrictions while surveying |
| Audit log | **Yes** — record every impersonation session |

---

## 1. Session model
Keep the original admin identity separate from the active (student) identity.

- **On start** (`start_impersonation`):
  - Store original admin id in `$_SESSION['impersonator_id']`
  - Set `$_SESSION['impersonating'] = true`
  - Overwrite `user_id / email / role / name / profile_image / last_activity`
    with the **student's** data (so every existing student page works unchanged).
  - Insert an `admin_impersonation_log` row; store its `id` in
    `$_SESSION['impersonation_log_id']`.
- **On exit** (`stop_impersonation`):
  - Restore the admin's original session values
  - Clear `impersonating`, `impersonator_id`, `impersonation_log_id`
  - Set `ended_at` on the audit row.

Result: while impersonating, `role === 'student'`, so `require_role('student')` pages
work and admin-only pages are naturally inaccessible. The only way back is the
Exit action or logout.

---

## 2. New files

### `config/security/impersonate.php`
Central helpers:
- `is_impersonating()` → bool
- `start_impersonation($conn, $student_id)` → swaps session, writes audit row
- `stop_impersonation()` → restores admin, finalizes audit row

### `admin/impersonate.php`
POST handler:
1. Verify caller is an admin **before** the role swap
   (check `$_SESSION['impersonator_id'] ?? $_SESSION['user_id']` and original role).
2. Read the submitted survey key; `password_verify()` against the hashed
   `admin_survey_key` setting. Block with a clear message if no key is set yet.
3. `start_impersonation(...)` then redirect to `/student/dashboard.php`.
4. Guarded with `csrf_verify()`.

### `admin/exit_impersonation.php`
- Does **not** require admin role (during impersonation `role === 'student'`);
  it only checks `is_impersonating()`.
- Restores admin session, redirects to `/admin/dashboard.php`.

### `admin/survey_key.php`
- Page for the admin to set / change the bcrypt-hashed `admin_survey_key` in
  `app_settings` (hashed with `password_hash`, never shown or logged in plaintext).
- Until a key is set, `admin/impersonate.php` refuses to act ("set a survey key first").

### `admin/db_migrate09.php`
Creates the audit table, following the `db_migrate08.php` pattern
(show status, run on POST with CSRF, idempotent checks).

```sql
CREATE TABLE admin_impersonation_log (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  admin_id   INT NOT NULL,
  student_id INT NOT NULL,
  started_at DATETIME NOT NULL,
  ended_at   DATETIME NULL,
  admin_ip   VARCHAR(45) NULL
);
```

---

## 3. Modified files

### `config/security/auth_check.php`
- Wrap the `suspended` / `blocked` / `holiday` auto-redirects in
  `if (!is_impersonating()) { … }` so the admin can browse freely while surveying
  (your chosen "bypass" behavior).
- If impersonating and the student account no longer exists (`!$user`),
  call `stop_impersonation()` and bounce to the admin dashboard.

### `config/security/ui.php` → `ui_topbar()`
- When `is_impersonating()`, render a prominent banner:
  *"Survey mode — viewing as <student name>. [Exit] [Exit & Logout]"*
  linked to `exit_impersonation.php`. Makes the mode obvious and always recoverable.

### `admin/student_detail.php`
- Add a "Login as this Student" card: a survey-key password field + button
  (CSRF-protected) posting to `admin/impersonate.php`.

### `admin/students.php` (optional)
- Add a quick "Login as" action next to View / Block / Suspend.

### `auth/logout.php`
- Already does `$_SESSION = []` + `session_destroy()`, which clears impersonation.
- Add: if `is_impersonating()`, finalize the audit row's `ended_at` before destroy.

---

## 4. Security guardrails
- Impersonation only initiable by `role='admin'`.
- Survey key stored **hashed**; never displayed or logged in plaintext.
- CSRF tokens on impersonate / exit / set-key forms (reuse `csrf_field()` / `csrf_verify()`).
- While impersonating, `role='student'` → admin pages inaccessible; only Exit / logout returns.
- Audit log gives accountability: who surveyed whom, when, from what IP.

---

## 5. Suggested rollout order
1. `admin/db_migrate09.php` → run it (create `admin_impersonation_log`).
2. `config/security/impersonate.php` helpers.
3. `admin/survey_key.php` → set the survey key.
4. `admin/impersonate.php` + `admin/exit_impersonation.php`.
5. Patch `auth_check.php` (bypass) + `ui.php` (banner) + `student_detail.php` (button).
6. Test: set key → enter a blocked/suspended student → confirm free browsing →
   Exit → confirm admin session restored.
