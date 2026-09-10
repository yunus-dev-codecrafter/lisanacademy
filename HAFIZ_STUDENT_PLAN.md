# Plan: Adding a "Hafiz" Student Category for Qur'an Revision (Page-Based)

## Overview

**Lisanun Mubeen Academy** now supports a **Hafiz** student category with page-based revision tracking. A Hafiz (someone who has already memorized the entire Qur'an) revises from memory instead of learning new material.

## Key Rules

| Rule | Detail |
|------|--------|
| **Page unit** | 604 pages per cycle (standard Medina Mushaf) |
| **Weekly target** | 20 pages/week, hard minimum |
| **Weekly max** | 21 pages/week |
| **Week boundary** | Resets every **Friday** |
| **Missed week** | Without admin approval → ALL progress resets to page 1 |
| **Order** | Sequential (page 1 → 2 → ... → 604) |
| **Cycles** | Repeatable — after 604 pages, start a new Daurah |
| **Session** | Student selects 1 page per recitation session |
| **Delivery** | Live (WhatsApp scheduling) or Audio upload |
| **Review** | Teacher accepts/rejects; accept advances the page |

## Implementation Status: COMPLETE

### New Files Created
| File | Purpose |
|------|---------|
| `admin/db_migrate10.php` | Migration: `users.hafiz` + `hafiz_revision`, `hafiz_sessions`, `hafiz_weekly_log` tables |
| `admin/set_hafiz.php` | Toggle a student's Hafiz status (CSRF, admin) |
| `admin/review_hafiz_session.php` | Accept/reject a Hafiz page session (advances progress) |
| `admin/approve_hafiz_skip.php` | Approve/reject a student's weekly skip request |
| `student/hafiz_revision.php` | Student revision hub — page grid, recite button, progress |
| `student/submit_hafiz_session.php` | POST handler for recording/uploading page recitation |

### Modified Files
| File | Change |
|------|--------|
| `config/security/helpers.php` | Added 12+ Hafiz helper functions; gated exam/graduation for Hafiz |
| `admin/add_student.php` | Added Hafiz/Non-Hafiz dropdown |
| `admin/save_student.php` | Persists `hafiz` flag on insert + creates first revision cycle |
| `admin/applications.php` | Added Hafiz toggle to activation modal |
| `admin/students.php` | Added "Hafiz" badge + filter toggle (All/Hafiz/Non-Hafiz) |
| `admin/student_detail.php` | Added designation toggle + revision progress view + skip approval |
| `admin/teaching.php` | Added Section D: pending Hafiz recitation sessions |
| `student/dashboard.php` | Branched UI: "Qur'an Revision" card for Hafiz |
| `student/my_learning.php` | Redirect Hafiz to `hafiz_revision.php` |
| `student/start_learning.php` | Redirect Hafiz |
| `student/new_lesson.php` | Redirect Hafiz |
| `student/recite.php` | Redirect Hafiz |
| `student/submit_recitation.php` | Redirect Hafiz |
| `student/live_recitation.php` | Redirect Hafiz |
| `student/request_lesson.php` | Redirect Hafiz |

---

## 2. Data Model Changes

### 2a. New flag on `users`
- `hafiz` `TINYINT(1) NOT NULL DEFAULT 0` — `1` = Hafiz (revision mode), `0` = Non-Hafiz (current learning mode).

This keeps a single role (`student`) and simply re-routes behavior based on the flag. All existing students default to `0` (Non-Hafiz), so nothing changes for them.

### 2b. New table `hafiz_revision` (per-student revision progress)
The key realization: **a Hafiz may operate across many Juz at once or sequentially.** We model each connected revision session/cycle.

| Column | Type | Notes |
|---|---|---|
| `id` | INT PK AI | |
| `student_id` | INT | -> `users.id` |
| `cycle_no` | INT | 1st, 2nd, 3rd... Daurah of the entire Qur'an |
| `current_juz` | INT | Juz currently being revised (1-30) |
| `juz_verses_per_session` | INT | How many verses the Hafiz recites off-head per session (their chosen session portion) |
| `status` | ENUM('active','completed') | Like `student_learning.status` |
| `started_at` | DATETIME | |
| `completed_at` | DATETIME NULL | Set when all 30 Juz revised |

### 2c. New table `hafiz_revision_juz` (per-Juz tracking)
Records which Juz have been revised within a cycle, mirroring how `student_learning` tracks per-surah progress.

| Column | Type | Notes |
|---|---|---|
| `id` | INT PK AI | |
| `revision_id` | INT | -> `hafiz_revision.id` |
| `student_id` | INT | |
| `juz_no` | INT (1-30) | |
| `completed_verses` | INT | cumulative verses recited & accepted for this juz |
| `completed_sessions` | INT | number of accepted off-head recitation sessions |
| `status` | ENUM('active','completed') | |
| `unique(revision_id, juz_no)` | | one row per juz per cycle |

### 2d. New table `hafiz_sessions` (off-head recitation submissions from memory)
The Hafiz equivalent of `student_recitation`. Because the Hafiz already knows the material, they don't request a portion — they simply **declare what they recited off-head** (surah/juz + verse range) and the teacher reviews it **live or via audio**.

| Column | Type | Notes |
|---|---|---|
| `id` | INT PK AI | |
| `student_id` | INT | |
| `revision_id` | INT | -> `hafiz_revision.id` |
| `juz_no` | INT | |
| `from_verse` / `to_verse` | INT | quoted by the student (off-head) |
| `surah_id` | INT NULL | optional, for display |
| `audio_file` | VARCHAR | optional recording (if the student chooses to record rather than go 100% live) |
| `session_type` | ENUM('live','audio') | how the recitation was delivered |
| `status` | ENUM('pending','accepted','rejected') | |
| `rating` | VARCHAR | |
| `feedback` | TEXT | |
| `submitted_at` | DATETIME | |

> **Design note:** We reuse the **existing acceptance math** — on accept, increment `completed_verses`/`completed_sessions` on `hafiz_revision_juz`; when all 30 Juz reach `completed`, mark `hafiz_revision.status='completed'` and allow a new cycle.

### 2e. Migration
Create `admin/db_migrate10.php` following the exact idempotent pattern of `db_migrate07.php` (`db_column_exists` / `db_table_exists` guards, status table, "Run Migration" button) to add the `users.hafiz` column + the three new tables.

---

## 3. Helpers (`config/security/helpers.php`)

Add a set of guard/utility functions so every page can branch on Hafiz-vs-Non-Hafiz consistently (matching the existing `student_in_exam`, `student_exam_locked`, etc. style):

- `student_is_hafiz($conn, $student_id)` — read the `users.hafiz` flag (safe if column missing -> false).
- `hafiz_revision_active($conn, $student_id)` — does the student have an `active` `hafiz_revision` cycle?
- `hafiz_current_cycle($conn, $student_id)` — the active cycle row.
- `hafiz_juz_progress($conn, $student_id, $revision_id)` — per-juz progress for display.
- `hafiz_revision_complete($conn, $student_id, $revision_id)` — all 30 juz completed?
- `hafiz_next_juz($conn, $student_id)` — the next un-revised juz in the active cycle (for auto-advance).
- `maybe_auto_request_next_juz($conn, $student_id)` — analogous to `maybe_auto_request_next_lesson`: after an accepted session, mark current juz done and advance to the next juz within the active cycle, or complete the cycle at juz 30.

---

## 4. Registration & Admin Designation

### 4a. Admin creates a student (`admin/add_student.php` + `admin/save_student.php`)
Add a **"Hafiz / Non-Hafiz"** toggle (dropdown: Non-Hafiz default, Hafiz) to the quick-add form and to the **application activation modal** (`admin/applications.php`). Persist the choice into `users.hafiz`.
- If created as Hafiz, optionally pre-seed their first `hafiz_revision` cycle (`cycle_no=1`, `current_juz=1`, `status='active'`) so they land directly in revision mode.

### 4b. Convert existing students (`admin/student_detail.php`)
Add a **designation section** in the student detail modal: a "Make Hafiz / Make Non-Hafiz" action (new small handler, e.g., `admin/set_hafiz.php` with CSRF, matching `suspend_student.php` pattern).
- On **convert to Hafiz**: create cycle 1 if none exists (keep any existing `student_learning` rows intact for historical data).
- On **convert to Non-Hafiz**: keep history but disable/clear revision UI.

### 4c. Display a badge
- `admin/students.php`: add a **"Hafiz"** badge on each card; add a filter toggle (All / Hafiz / Non-Hafiz).
- `admin/teaching.php`: prefix hafiz sections so the teacher sees them separately (see section 6).

---

## 5. Student-Side: Hafiz Revision UI

### 5a. New page `student/hafiz_revision.php` (Hafiz "My Revision" hub)
Analogous to `my_learning.php`, but for revision:
- Shows the current cycle (e.g., "Daurah #1"), overall progress (x/30 juz revised), and a per-juz progress display.
- **"Record Off-Head Recitation"** form: the student declares the juz + verse range they recited from memory (or just the juz), selects session type (`Live` or `Audio upload`), and submits.
  - For **Live**: like existing `live_recitation.php`, they pick day/time and are redirected to WhatsApp to schedule with the teacher.
  - For **Audio**: they record/upload a clip of themselves reciting off-head.
- Shows status of the current session (pending/accepted/rejected) and blocks a new session while one is pending (mirroring `request_lesson` guards).
- Auto-advance: after acceptance, the next juz unlocks.
- Show a **revision completion** state ("Masha'Allah, your Daurah is complete — start a new cycle") instead of the graduation/auto-delete path.

### 5b. `student/dashboard.php` branching
- If Hafiz: show a **"Qur'an Revision"** hero/card pointing to `hafiz_revision.php` + revision progress stat instead of (or alongside) the surah-based "Surahs Completed" stat.
- Hide the "Surahs Completed / /114" percentage for Hafiz and instead show "Juz Revised x/30" + cycle number.

### 5c. `my_learning.php`, `start_learning.php`, `new_lesson.php`, `recite.php`, `submit_recitation.php`, `live_recitation.php`, `request_lesson.php`
For Hafiz students, these pages redirect to `hafiz_revision.php` (with a friendly notice "You are a Hafiz — you revise the Qur'an from memory instead of requesting lessons"). This reuses the existing `header("Location: ...")` redirect pattern seen in the exam guards.

---

## 6. Admin/Teacher Side: Reviewing Hafiz Recitations

### 6a. `admin/teaching.php`
Add a dedicated **Section D — Hafiz Revision Sessions**, showing pending `hafiz_sessions` (both live and audio) with:
- Student name, juz, verse range, audio player (if audio), session type, submitted time.
- **Accept/Reject** form posting to a new handler `admin/review_hafiz_session.php`.
- On **accept**: increment `hafiz_revision_juz.completed_verses/sessions`; if that juz is now fully revised, mark it `completed`; if all 30 juz complete -> mark `hafiz_revision.status='completed'`; else auto-advance `current_juz` to the next (via `maybe_auto_request_next_juz`).
- On **reject**: record feedback; student re-recites the same juz/range.

### 6b. `admin/student_detail.php`
Add a **revision progress view**: current cycle, juz completed, history of `hafiz_sessions`. Add a "View Hafiz Revision" link similar to the Certificate link.

### 6c. `admin/recitations_list.php` / `admin/submitted_recitations.php`
Note: these query `student_recitation` only. Hafiz sessions live in `hafiz_sessions`, so either extend these lists to include hafiz sessions with a type label, or leave them as the Non-Hafiz-only history and add a separate `admin/hafiz_sessions_list.php`. **Recommendation:** leave existing lists untouched; add a small separate history view for revision sessions to avoid cross-table complexity.

---

## 7. Exam & Academic-Lifecycle Exemptions for Hafiz

These are essential to prevent Hafiz from being wrongly flagged/locked:

- **`student_in_exam()` & `student_exam_locked()` & `can_take_exam()`** (helpers.php): return `false` for Hafiz. This automatically excludes them from exam-mode recitation pauses and the "missed exam / owe N500" defaulting lock.
- **`finalize_exam_term()`** (helpers.php): exclude Hafiz from the defaulter-marking `UPDATE users SET exam_defaulted=1...` query (add `AND hafiz=0`).
- **`student_has_graduated()` / `mark_graduated_if_due()` / `purge_graduated_accounts()`**: skip Hafiz (add `AND hafiz=0`), so a Hafiz never triggers the 7-day auto-delete. Their "completion" is the revision milestone instead.
- **`exam_selected` / `exam_selectable` logic**: exclude Hafiz from being marked `exam_selected`.

---

## 8. Files to Create / Modify — Full Inventory

### New files
| File | Purpose |
|---|---|
| `admin/db_migrate10.php` | Migration: add `users.hafiz` + `hafiz_revision`, `hafiz_revision_juz`, `hafiz_sessions` tables |
| `admin/set_hafiz.php` | Toggle a student's Hafiz status (CSRF, admin) |
| `admin/review_hafiz_session.php` | Accept/reject a hafiz session (advances progress) |
| `admin/hafiz_sessions_list.php` *(optional)* | Read-only history of revision sessions |
| `student/hafiz_revision.php` | Hafiz revision hub (record off-head, session status) |
| `student/submit_hafiz_session.php` | POST handler for recording an off-head session (audio/live) |

### Modified files
| File | Change |
|---|---|
| `config/security/helpers.php` | Add Hafiz helper functions; gate exam/graduation logic for Hafiz |
| `admin/add_student.php`, `admin/save_student.php` | Add Hafiz/Non-Hafiz toggle |
| `admin/applications.php` | Add Hafiz toggle to activation modal + persist it |
| `admin/students.php` | Hafiz badge + filter |
| `admin/student_detail.php` | Designation toggle + revision progress view |
| `admin/teaching.php` | Section D for Hafiz revision sessions |
| `admin/exams.php` / `admin/exam_settings.php` | Exclude Hafiz from exam selection/defaulting |
| `student/dashboard.php` | Branch UI for Hafiz (revision hub, hafiz stats) |
| `student/my_learning.php`, `start_learning.php`, `new_lesson.php`, `recite.php`, `submit_recitation.php`, `live_recitation.php`, `request_lesson.php` | Redirect Hafiz to `hafiz_revision.php` |

---

## 9. Recommended Best-Practice Behaviors (Key Design Points)

1. **One flag, two clear flows** — `users.hafiz` cleanly separates modes without touching the existing `role`/learning system, so Non-Hafiz behavior is 100% unchanged.
2. **No audio-lesson dependency for Hafiz** — because a Hafiz recites from memory, the `lessons`/`admin_audio` model recitation step is bypassed entirely in the revision flow; the teacher just hears the off-head recitation (live or audio).
3. **Sequential Juz + auto-advance** — matches how a Hafiz traditionally revises (Daurah 1->30), reducing admin overhead and preventing the student getting "stuck" or skipping.
4. **Explicit exam & lifecycle exemption** — prevents false "defaulter/pay N500" locks and prevents unwanted auto-deletion for a student who is actively revising.
5. **Graceful migration & fallbacks** — every new DB object is created idempotently and every helper degrades gracefully if the column is missing (the exact defensive pattern already used across `helpers.php`), so nothing breaks on an un-migrated database.
6. **Reuse existing review UX** — accept/reject + rating + feedback mirrors `review_recitation.php` so teachers have a consistent workflow.

---

## 10. Suggested Implementation Order

1. **Migration** (`db_migrate10.php`) — schema.
2. **Helpers** — add hafiz functions + exam/lifecycle gating.
3. **Admin designation** — add_student, save_student, applications, set_hafiz, student_detail, students list.
4. **Student revision flow** — hafiz_revision.php, submit_hafiz_session.php, dashboard branch, redirects from old learning pages.
5. **Teacher review** — review_hafiz_session.php, teaching.php Section D.
6. **Polish** — badges, filters, empty states, WhatsApp live scheduling for Hafiz.
