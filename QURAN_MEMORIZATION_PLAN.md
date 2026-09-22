# Plan: Qur'an Memorization & Muraja'ah Feature — Lisanun Mubeen Academy

## Overview

A state-based, 604-page **Qur'an Memorization Planner** that digitizes the methodology in `Quran Memorization feature.txt` (from the original curriculum specification). The progression hierarchy is preserved with mathematical and architectural exactness:

```
1 PAGE → 6-PAGE REVISION → 25-PAGE REVISION → 100-PAGE REVISION → 304-PAGE HALF-QUR'AN MILESTONE → 300-PAGE FINAL REVISION → COMPLETE QUR'AN (الحمد لله)
```

The feature integrates cleanly into the **existing** Lisanun Mubeen Academy PHP/mysqli codebase. It preserves existing student accounts, authentication, admin queues, and database conventions without rebuilding existing subsystems.

---

## Audit Findings & Critical Issues Fixed in This Plan

A comprehensive audit of the codebase against the original plan revealed several critical architectural and mathematical issues that had to be corrected before safe implementation:

### 1. The 304-Page Half-Milestone & Segment Arithmetic Blunder [FIXED]
- **Previous Flaw**: The previous plan misconstrued the 7-day milestone revision described in the PDF for Section 3 (pages 201–304) as pages `1..104`, added $104 + 50 + 54 = 208$, and claimed the PDF had an arithmetic error because $304 - 208 = 96$. It then artificially split the first half into 12 blocks of 25 pages, a 100-page revision at page 300, a bizarre 4-page tail (pages 301–304) as "block 13", and a separate revision.
- **The Correction**:
  - The first half of the Qur'an consists of **12 monthly blocks**:
    - **Blocks 1–11**: 25 pages each ($11 \times 25 = 275$ pages).
    - **Block 12**: **29 pages** (pages 276–304, reaching the exact end of Surah Al-Kahf at page 304).
  - Section 3 (pages 201–304) is exactly **104 pages**. Its 7-day milestone revision matches the PDF's schedule perfectly:
    - **Day 1**: Month 9 (pages 201–225, 25 pages)
    - **Day 2**: Month 10 (pages 226–250, 25 pages)
    - **Day 3**: Month 11 (pages 251–275, 25 pages)
    - **Day 4**: Month 12 (pages 276–304, **29 pages**)
    - **Day 5**: Morning: Month 9 (25p) + Evening: Month 10 (25p) = 50 pages
    - **Day 6**: Morning: Month 11 (25p) + Evening: Month 12 (29p) = **54 pages**
    - **Day 7**: Full Section 3 (pages 201–304, **104 pages**) + distributed review across the first half.
  - Days 5 and 6 are cumulative pairings of the 4 blocks, and Day 7 is the full 104-page section review. The arithmetic is 100% consistent with the PDF.
  - **Total-days reconciliation (confirmed: 7 phases / 775 days)**:
    - 604 memorization days (default 1 page/day).
    - 24 blocks $\times$ 5 intra-block revision days = 120 revision days.
    - **7 milestone revisions** (1–100, 101–200, 201–304 Section 3, 305–404, 405–504, 505–604, final 300) $\times$ 7 days = 49 milestone days.
    - $120 + 49 = 169$ revision days + **2 celebration days** (half milestone, full completion) = **775 total days**.
    - The 2 celebration days are real non-task states inserted after the Section-3 revision and after the final 300-page revision.

### 2. Split-Day Session Tracking Gap (`morning` / `evening`) [FIXED]
- **Previous Flaw**: 50-page, 54-page, and 100-page revision days require separate Morning and Evening recitation sessions. The previous plan had no `current_session` in `quran_memorization` and omitted `day_session` from `quran_murajaah_sessions`. When a student completed the morning session, the system had no way to advance to the evening session without skipping the day or getting stuck.
- **The Correction**:
  - Add `current_session ENUM('full','morning','evening') NOT NULL DEFAULT 'full'` to `quran_memorization`.
  - Add `day_session ENUM('full','morning','evening') NOT NULL DEFAULT 'full'` to `quran_murajaah_sessions`.
  - On split days, the engine surfaces the Morning task first. Once passed by the teacher, `current_session` advances to `evening`. Once the evening task is passed, `current_session` resets and `segment_day` advances to the next day.

### 3. Account Auto-Deletion Vulnerability (`purge_graduated_accounts`) [FIXED]
- **Previous Flaw**: In `config/security/helpers.php:2640-2730`, the academy runs `student_has_graduated()`, `mark_graduated_if_due()`, and `purge_graduated_accounts()`. If a student who previously completed surahs is designated as a Memorizer, or if a memorizer reaches 100%, `mark_graduated_if_due()` stamps `graduated_at = NOW()`. After 7 days, `purge_graduated_accounts()` hard-deletes the student account and all audio files because it only checked `AND hafiz = 0`!
- **The Correction**:
  - In `config/security/helpers.php`, guard `student_has_graduated()` and `mark_graduated_if_due()` with `if (student_is_memorizing($conn, $student_id)) return false;`.
  - In `purge_graduated_accounts()`, add `AND (memorizing = 0 OR memorizing IS NULL)` so memorizers are strictly protected from auto-deletion.
  - In `purge_graduated_accounts()`, add `quran_memorization`, `quran_memorization_log`, and `quran_murajaah_sessions` to the student purge cascade so orphaned records are never left behind when non-memorizer students are legitimately purged.

### 4. Admin Daily Queue Integration (`admin/teaching.php`) [FIXED]
- **Previous Flaw**: The previous plan created a siloed `admin/review_murajaah.php` but omitted integration into `admin/teaching.php` and `teaching_pending_count($conn)`. In Lisanun Mubeen Academy, teachers review all daily student audio submissions in `admin/teaching.php` and monitor the red sidebar badge driven by `teaching_pending_count()`. Without this, memorizers' Muraja'ah submissions would never appear in the daily review queue.
- **The Correction**:
  - Update `teaching_pending_count($conn)` in `helpers.php` to include pending submissions from `quran_murajaah_sessions`.
  - Add a dedicated review section in `admin/teaching.php` ("Section F: Qur'an Memorization Muraja'ah Sessions") with inline audio player, WhatsApp live links, teacher feedback input, teacher audio feedback upload, and Pass/Fail actions.

### 5. Teacher Audio Feedback Missing (`admin_audio_feedback`) [FIXED]
- **Previous Flaw**: The previous schema for `quran_murajaah_sessions` lacked an `admin_audio_feedback` column. Teachers frequently provide spoken tajweed corrections for recitation assessments.
- **The Correction**: Add `admin_audio_feedback VARCHAR(255) NULL` and `reviewed_by INT NULL` to `quran_murajaah_sessions`, fully matching `hafiz_sessions` and `student_recitation`.

### 6. Mutual Exclusivity Oversight in `admin/set_hafiz.php` [FIXED]
- **Previous Flaw**: When an admin designates a student as Hafiz via `admin/set_hafiz.php`, `memorizing` was not cleared, leaving the student in an ambiguous state with both flags set.
- **The Correction**: Update `admin/set_hafiz.php` to set `memorizing = 0` and pause any active `quran_memorization` row. Similarly, `admin/set_memorizing.php` sets `hafiz = 0` and pauses any active `hafiz_revision` row.

### 7. Legacy Student Route Protection & Complete File Inventory [FIXED]
- **Previous Flaw**: The previous plan's file inventory omitted the 7 legacy student learning pages (`my_learning.php`, `recite.php`, `new_lesson.php`, `request_lesson.php`, `start_learning.php`, `live_recitation.php`, `submit_recitation.php`). Memorizers navigating to these pages would see standard surah learning instead of being redirected.
- **The Correction**: Explicitly include all 7 files plus `hafiz_revision.php` in the modification inventory, adding `if (student_is_memorizing($conn, $student_id)) { redirect('quran_memorization.php'); }`.

### 8. Refined Gating States: Awaiting Review vs Rejected [FIXED]
- **Previous Flaw**: The previous plan conflated `pending` and `failed`, stating that until passed, the student sees "fix this range and retake".
- **The Correction**:
  - `pending`: Student has submitted audio or scheduled live via WhatsApp. Student sees an informational badge: *"Recitation submitted — awaiting teacher review"* and cannot advance until marked.
  - `failed`: Teacher marked Fail with feedback. Student sees an alert banner with teacher notes and audio feedback: *"Revision not accepted — please review feedback, practice Pages X–Y, and re-submit"*, unlocking the submission modal for a retake.

---

## Methodology & Schedule Architecture (Single Source of Truth)

- **Standard 604-page Madani Mushaf**: Page 1 $\rightarrow$ Page 604. Default pace: **1 page per memorization day**.
- **6/1 Rhythm**: 6 memorization days followed by 1 Muraja'ah day covering those 6 pages.
- **25-Page Monthly Cycle (30 days)**:
  - Days 1–6: Memorize pages $S \dots S+5$
  - Day 7: Muraja'ah pages $S \dots S+5$
  - Days 8–13: Memorize pages $S+6 \dots S+11$
  - Day 14: Muraja'ah pages $S+6 \dots S+11$
  - Days 15–20: Memorize pages $S+12 \dots S+17$
  - Day 21: Muraja'ah pages $S+12 \dots S+17$
  - Days 22–27: Memorize pages $S+18 \dots S+23$
  - Day 28: Muraja'ah pages $S+18 \dots S+23$
  - Day 29: Memorize page $S+24$
  - Day 30: Muraja'ah entire block pages $S \dots S+24$
  - **Summary**: 25 new pages + 5 revision days = **30-day block**.
- **Block 12 (Special 29-page block to reach Surah Al-Kahf at Page 304)**:
  - 4 cycles of $6+1$ (pages 276–299) = 24 memo days + 4 rev days = 28 days.
  - Days 29–33: Memorize pages 300, 301, 302, 303, 304 (5 pages).
  - Day 34: Muraja'ah entire block (pages 276–304).
- **100-Page Milestone Revisions (7 Days Each)**:
  - Day 1: Month 1 (pages 1–25)
  - Day 2: Month 2 (pages 26–50)
  - Day 3: Month 3 (pages 51–75)
  - Day 4: Month 4 (pages 76–100)
  - Day 5: Morning: Month 1 (25p), Evening: Month 2 (25p) = 50 pages
  - Day 6: Morning: Month 3 (25p), Evening: Month 4 (25p) = 50 pages
  - Day 7: Full 100 pages (pages 1–100).
- **Section 3 Milestone Revision (Pages 201–304, 104 Pages — 7 Days)**:
  - Day 1: Month 9 (pages 201–225, 25p)
  - Day 2: Month 10 (pages 226–250, 25p)
  - Day 3: Month 11 (pages 251–275, 25p)
  - Day 4: Month 12 (pages 276–304, 29p)
  - Day 5: Morning: Month 9 (25p), Evening: Month 10 (25p) = 50p
  - Day 6: Morning: Month 11 (25p), Evening: Month 12 (29p) = 54p
  - Day 7: Full 104 pages (pages 201–304) + cumulative review.
  - **Milestone Celebration Day 1**: Half-Qur'an completion celebration (Al-Fatihah through Al-Kahf).
- **Pages 305–604 (Second Half — 12 Blocks of 25 Pages)**:
  - Blocks 13–16 (pages 305–404) $\rightarrow$ 7-day 100-page revision.
  - Blocks 17–20 (pages 405–504) $\rightarrow$ 7-day 100-page revision.
  - Blocks 21–24 (pages 505–604) $\rightarrow$ 7-day 100-page revision.
- **Final 300-Page Muraja'ah (Pages 305–604 — 7 Days)**:
  - Day 1: Pages 305–354 (50p)
  - Day 2: Pages 355–404 (50p)
  - Day 3: Pages 405–454 (50p)
  - Day 4: Pages 455–504 (50p)
  - Day 5: Pages 505–554 (50p)
  - Day 6: Pages 555–604 (50p)
  - Day 7: Full 300 pages (Maryam through An-Nas).
  - **Milestone Celebration Day 2**: Full-Qur'an completion celebration (الحمد لله ... أنا الآن أحفظ القرآن الكريم كله).

---

## Data Model — New Migration `admin/db_migrate16.php`

Idempotent migration following the established pattern (`db_table_exists`, `db_column_exists`, CSRF token check, guarded statements in `try/catch`).

```sql
-- 1. Designation flag on users
ALTER TABLE users ADD COLUMN memorizing TINYINT(1) NOT NULL DEFAULT 0 AFTER hafiz;

-- 2. Main student memorization state
CREATE TABLE IF NOT EXISTS quran_memorization (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    pace INT NOT NULL DEFAULT 1,
    current_page INT NOT NULL DEFAULT 1,
    pages_memorized INT NOT NULL DEFAULT 0,
    day_count INT NOT NULL DEFAULT 0,
    segment_type ENUM('normal25','rev100','revhalf','revfinal','done') NOT NULL DEFAULT 'normal25',
    segment_day INT NOT NULL DEFAULT 1,
    current_session ENUM('full','morning','evening') NOT NULL DEFAULT 'full',
    task_type ENUM('memorization','murajaah') NOT NULL DEFAULT 'memorization',
    current_month INT NOT NULL DEFAULT 1,
    current_100_block INT NOT NULL DEFAULT 1,
    milestone VARCHAR(30) NOT NULL DEFAULT 'none',
    status ENUM('active','paused','completed') NOT NULL DEFAULT 'active',
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    completed_at DATETIME NULL,
    UNIQUE KEY uq_student (student_id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Permanent completion history log
CREATE TABLE IF NOT EXISTS quran_memorization_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    task_day INT NOT NULL,
    task_type ENUM('memorization','murajaah') NOT NULL,
    start_page INT NOT NULL,
    end_page INT NOT NULL,
    day_session ENUM('full','morning','evening') NOT NULL DEFAULT 'full',
    result ENUM('completed','passed','failed','skipped','adjusted') NOT NULL DEFAULT 'completed',
    completed_by ENUM('student','admin') NOT NULL DEFAULT 'student',
    notes TEXT NULL,
    assigned_date DATETIME NULL,
    completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_student_day (student_id, task_day),
    KEY idx_student_created (student_id, completed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Muraja'ah assessment submissions
CREATE TABLE IF NOT EXISTS quran_murajaah_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    task_day INT NOT NULL,
    start_page INT NOT NULL,
    end_page INT NOT NULL,
    day_session ENUM('full','morning','evening') NOT NULL DEFAULT 'full',
    session_type ENUM('live','audio') NOT NULL DEFAULT 'audio',
    audio_file VARCHAR(255) NULL,
    status ENUM('pending','passed','failed') NOT NULL DEFAULT 'pending',
    feedback TEXT NULL,
    admin_audio_feedback VARCHAR(255) NULL,
    reviewed_by INT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    KEY idx_student_status (student_id, status),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## Progression Engine Specification (`config/security/helpers.php`)

All functions wrapped in `if (!function_exists())` with defensive table and column existence checks:

- `student_is_memorizing($conn, $sid)`: Returns `bool`, safe if column is absent.
- `mem_get_state($conn, $sid)`: Retrieves or lazily creates initial state row.
- `mem_compute_task($conn, $state)`: Deterministically calculates today's task from state:
  - Returns `task_type` (`memorization` | `murajaah`), `start_page`, `end_page`, `day_session` (`full` | `morning` | `evening`), `day_number`, `milestone_trigger`, `surah_label`.
- `mem_gate($conn, $sid)`: Master gate checking:
  - Active record exists? Not paused? Not completed?
  - Any pending Muraja'ah session? If yes, returns `['ok' => false, 'state' => 'pending', 'session' => $session]`.
  - Any failed Muraja'ah session requiring retake? If yes, returns `['ok' => false, 'state' => 'failed', 'session' => $session]`.
  - Holiday active? If holiday mode is enabled and not whitelisted, blocks reciting.
- `mem_record_memorization($conn, $sid, $page)`:
  - Validates that today's assigned task is memorization of `$page`.
  - Inserts record into `quran_memorization_log` with `result = 'completed'`.
  - Increments `pages_memorized` and advances state to next scheduled day via `mem_advance()`.
- `mem_submit_murajaah($conn, $sid, $session_type, $audio_file = null)`:
  - Validates current pending Muraja'ah task.
  - Inserts `pending` row in `quran_murajaah_sessions`. Does NOT advance progress yet.
- `mem_mark_murajaah($conn, $session_id, $verdict, $feedback = '', $admin_audio = null, $admin_id = null)`:
  - If `$verdict === 'passed'`:
    - Updates session status to `passed`.
    - Logs to `quran_memorization_log` with `result = 'passed'`.
    - Advances session: if on `morning` session of a split day, sets `current_session = 'evening'`; if on `evening` or `full`, advances `segment_day` / `day_count` and resets session to `full` (or `morning` if next day is split).
  - If `$verdict === 'failed'`:
    - Updates session status to `failed`, stores feedback text and audio.
    - Leaves gate closed; student must practice and re-submit.
- `mem_self_heal($conn, $sid)`:
  - Cross-checks state row against `quran_memorization_log` and `quran_murajaah_sessions` to heal drifted pointers, orphaned statuses, or incorrect page numbers.
- `mem_page_statuses($conn, $sid)`:
  - Returns array of length 604 where each page has status `NEW`, `MEMORIZED`, `UNDER_REVISION`, or `REVISED` for color-coding the 604-cell grid.
- `mem_progress_summary($conn, $sid)`:
  - Authoritative stats: Pages Memorized ($X/604$), Percentage, Pages Remaining, Current Month, Current 100-Block, Milestone, Days Active.

---

## Student Interface (`student/quran_memorization.php`)

Architecturally mirrors `student/hafiz_revision.php`:
1. **Header & Navigation**: Responsive navbar, page title with Arabic subtitle (*برنامج حفظ القرآن الكريم والمراجعة*).
2. **Today's Task Hero Card**: Extreme visual distinction between Memorization and Muraja'ah:
   - **📖 حفظ (Memorization)**: Gold badge, single page number, Surah & Verse span label (from `hafiz_page_verse_span()`), "Memorize today's page" instructions, and prominent **"Mark as Memorized"** action button.
   - **🔄 مراجعة (Muraja'ah)**: Purple/blue badge, page range (e.g., *Pages 31–36 · 6 pages*), split session indicator (*Morning Session: Pages 1–25* or *Evening Session: Pages 26–50*).
   - **Session States**:
     - *Ready to Recite*: Actions for **Live via WhatsApp** and **Record Audio** (reusing `recorder.js`).
     - *Awaiting Review*: Yellow badge showing submission timestamp and embedded audio player.
     - *Revision Not Accepted (Fail)*: Red alert banner with teacher comments, teacher audio feedback player, and **"Retake Assessment"** button.
3. **Progress & Milestone Banners**:
   - 100-Page Milestone Banner, Half-Qur'an Milestone Banner (Page 304), Qur'an Completion Banner (Page 604).
   - Progress bar with percentage and stat cards (Memorized, Remaining, Month, 100-Block).
4. **Interactive 604-Cell Qur'an Grid**:
   - Color-coded cells for all 604 pages (`.page-done` for memorized, `.page-revised` for revised, `.page-active` for today's page, `.page-locked` for unmemorized).
5. **Timeline History**:
   - Chronological completion log of past tasks with dates, page ranges, and results.

---

## Teacher / Admin Interface

1. **`admin/teaching.php` Integration**:
   - Add Section F: **Qur'an Memorization - Muraja'ah Sessions Awaiting Review**.
   - Lists student name, assigned page range, session type (Audio vs Live), audio player for student recording, feedback textarea, audio recorder/uploader for teacher feedback, and **Pass** / **Fail** buttons.
2. **`admin/review_murajaah.php`**:
   - POST endpoint processing Pass/Fail, uploading teacher audio feedback, invoking `mem_mark_murajaah()`, and redirecting back to `teaching.php` with toast notification.
3. **`admin/memorization_students.php`**:
   - Dedicated management dashboard for all memorizing students.
   - Table showing Student Name, Current Page, Today's Task, Overall %, Last Completed Date, and Status (`Active` / `Paused`).
   - Modal controls to: mark task complete on behalf of student, adjust current page forward/backward, pause/resume student, or run self-heal.
4. **`admin/memorization_action.php`**:
   - POST handler for admin overrides and adjustments (CSRF-protected).
5. **`admin/students.php`**:
   - Add "Memorizer" badge to student cards and add "Memorizer" to the filter buttons (All / Memorizer / Hafiz / Regular).
6. **`admin/student_detail.php`**:
   - 3-way toggle between Standard Learner, Memorizer, and Hafiz.
   - Embed Memorization Progress Overview panel when student is a memorizer.
7. **`admin/add_student.php`, `save_student.php`, `applications.php`**:
   - Update Student Type dropdown to support:
     1. Non-Hafiz (Standard Surah Learner)
     2. Qur'an Memorizer (Memorization & Muraja'ah Planner)
     3. Hafiz (Full Qur'an Revision Mode)

---

## Complete Files Inventory

### Files to Create
1. `admin/db_migrate16.php` — Database migration for `users.memorizing`, `quran_memorization`, `quran_memorization_log`, `quran_murajaah_sessions`.
2. `admin/set_memorizing.php` — Designation toggle handler (clears Hafiz flag, initializes/activates state).
3. `admin/memorization_students.php` — Memorizers roster with progress tracking and adjustment modal.
4. `admin/review_murajaah.php` — Admin Muraja'ah review POST handler (Pass/Fail + audio feedback).
5. `admin/memorization_action.php` — Admin adjustment POST handler (advance, correct page, pause/resume).
6. `student/quran_memorization.php` — Student memorization hub (today's task, 604-grid, progress, history).
7. `student/submit_memorization.php` — Student POST handler (mark memorized, upload audio, WhatsApp live schedule).
8. `config/mem_engine_self_test.php` — Admin automated validation test verifying 604 memo days, 174 revision days, 2 celebration days, 304 half-milestone, and state transitions.

### Files to Modify
1. `config/security/helpers.php`:
   - Add memorization helper suite (`student_is_memorizing`, `mem_get_state`, `mem_compute_task`, `mem_gate`, `mem_record_memorization`, `mem_submit_murajaah`, `mem_mark_murajaah`, `mem_advance`, `mem_self_heal`, `mem_page_statuses`, `mem_progress_summary`).
   - Protect memorizers in `student_has_graduated()`, `mark_graduated_if_due()`, and `purge_graduated_accounts()`.
   - Update `teaching_pending_count()` to include pending Muraja'ah submissions.
2. `config/security/ui.php`:
   - Student sidebar: Add "Qur'an Memorization" nav link when `student_is_memorizing()` is true.
3. `admin/teaching.php`:
   - Add Section F for reviewing pending Qur'an Memorization Muraja'ah sessions.
4. `admin/set_hafiz.php`:
   - Ensure setting Hafiz status unsets `memorizing = 0` and pauses any active memorization state.
5. `admin/students.php`:
   - Add Memorizer badge and Memorizer filter button.
6. `admin/student_detail.php`:
   - Add 3-way designation toggle and Memorization Progress panel.
7. `admin/add_student.php`:
   - Add Memorizer option to student creation form.
8. `admin/save_student.php`:
   - Save `memorizing` flag on insert and initialize state if selected.
9. `admin/applications.php`:
   - Add Memorizer option to application approval modal.
10. `admin/dashboard.php`:
    - Add quick action card linking to `memorization_students.php`.
11. `student/dashboard.php`:
    - Display dedicated Memorization card (today's task, pages memorized, CTA to `quran_memorization.php`).
12. Legacy Student Learning Pages (Redirect Guards):
    - `student/my_learning.php`
    - `student/recite.php`
    - `student/new_lesson.php`
    - `student/request_lesson.php`
    - `student/start_learning.php`
    - `student/live_recitation.php`
    - `student/submit_recitation.php`
    - `student/hafiz_revision.php`
    (All redirect memorizers to `quran_memorization.php`).

---

## Verification & Acceptance Plan

1. **Automated Engine Self-Test (`config/mem_engine_self_test.php`)**:
   - Asserts simulation over the full journey produces **exactly 604 memorization tasks**.
   - Asserts **exactly 169 revision days** and **2 celebration days** (775 total days).
   - Asserts the half-Qur'an milestone occurs at **page 304** (never 300).
   - Asserts Section 3 (pages 201–304) executes the exact 7-day schedule (25p, 25p, 25p, 29p, 50p, 54p, 104p).
   - Asserts split days properly transition from `morning` $\rightarrow$ `evening` $\rightarrow$ next day.
   - Asserts recomputing tasks from state is 100% deterministic and idempotent.
2. **Account Safety Verification**:
   - Verify `mark_graduated_if_due()` and `purge_graduated_accounts()` never touch or delete a memorizing student.
3. **Manual Flow Testing**:
   - Admin designates student as Memorizer $\rightarrow$ Hafiz flag is unset.
   - Student dashboard displays Memorization card $\rightarrow$ clicks through to `quran_memorization.php`.
   - Complete 6 memorization days $\rightarrow$ Day 7 presents Muraja'ah for Pages 1–6.
   - Submit audio recitation $\rightarrow$ task card shows *Awaiting Teacher Review*.
   - Teacher marks Fail with audio feedback $\rightarrow$ student is blocked with feedback visible and retake available.
   - Teacher marks Pass $\rightarrow$ student advances to Page 7.