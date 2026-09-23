# Implementation Plan — Memorizer System Bugs & Non-Memorizer Audio Auto-Delete

**Source of Truth:** `memorizer_system_bugs_and_initial_learner_new_feature.txt`  
**Target Codebase:** `C:\Users\YUNUS\Desktop\htdocs`  
**Status:** Reviewed, Corrected & Verified (Ready for Execution)

---

## 1. Audit Findings & Fixes Made to the Original Plan

During deep exploration of the codebase, several critical flaws, incorrect assumptions, and missing edge cases were identified in the preliminary plan. This revised plan fixes all of them:

1. **CRITICAL: Hidden Video Player Bug in `admin/teaching.php` (Section F)**  
   - *Original Plan Assumption:* "Video already renders via `media_player_html()` (picks `<video>` by extension)."  
   - *Reality in Codebase:* Line 512 of `admin/teaching.php` hardcodes:  
     `if ($mq['session_type'] === 'audio' && !empty($mq['audio_file']))`.  
     If a student submits a `video` session, line 512 evaluates to `false` and **renders nothing at all** — the teacher would not see or be able to play the video!  
   - *Fix:* Update line 512 to check `in_array($mq['session_type'], ['audio', 'video'], true) && !empty($mq['audio_file'])`.

2. **CRITICAL: Database Schema Join Mismatch for Completed Learning Cycles**  
   - *Original Plan Assumption:* The plan assumed `lessons` has a plan ID foreign key linking directly to `student_learning`.  
   - *Reality in Codebase:* `lessons` does **not** have a `plan_id` or `student_learning_id` column. Instead, `lessons` links to `student_learning` via `lessons.student_id = sl.student_id AND lessons.surah_id = sl.surah_id`. Furthermore, `student_recitation` and `admin_audio` reference `lessons.id` via their column named `learning_plan_id`.  
   - *Fix:* The cleanup helper explicitly executes the correct relational joins:  
     `student_learning (student_id, surah_id)` → `lessons (id WHERE student_id = ? AND surah_id = ?)` → `student_recitation (learning_plan_id = lessons.id)` and `admin_audio (learning_plan_id = lessons.id)`.

3. **CRITICAL: Row Deletion Contradiction & Loss of Student Academic Records**  
   - *Original Plan Contradiction:* Section 6 stated "delete student_recitation rows + files", while Section 8 stated "sweeps keep DB rows but remove the file + file references".  
   - *Danger:* If `student_recitation` rows are deleted, all historical grades, ratings, teacher feedback, and completed surah progress counts are permanently wiped out from `student/feedback.php` and `completed_surahs.php`.  
   - *Fix:* Strictly enforce file-level deletion with column NULLing:  
     Unlink the physical audio files from `uploads/student_audio/` and `uploads/admin_feedback/`, and set `audio_file = ''` and `admin_audio_feedback = NULL` in `student_recitation`. `admin_audio` rows (which only represent uploaded recitation files) are deleted along with their files in `uploads/admin_audio/`. The student recitation history, rating, and feedback text remain 100% intact.

4. **Missing Indexing & Infinite Sweep Loop Prevention (`audio_cleaned` flag)**  
   - *Problem:* Once a plan has `status = 'completed'` and `completed_at <= NOW() - INTERVAL 24 HOUR`, every subsequent page load would re-scan and re-query the same completed lessons and recitations forever.  
   - *Fix:* Add `audio_cleaned TINYINT(1) NOT NULL DEFAULT 0` to `student_learning` in `db_migrate17.php`. The sweep selects `WHERE status = 'completed' AND audio_cleaned = 0 AND completed_at <= NOW() - INTERVAL 24 HOUR LIMIT 50` and marks `audio_cleaned = 1`. Subsequent runs are instant zero-cost no-ops.

5. **Opportunistic Cleanup Performance & Throttling**  
   - *Original Plan Assumption:* "Each run is two cheap queries; no lock/state needed" called on every page load across 6 pages.  
   - *Danger:* Unthrottled runs cause constant disk I/O checks (`is_file`, `unlink`) on shared hosting (InfinityFree) on every single student click.  
   - *Fix:* Adopt the existing codebase throttle pattern used in `index.php` (`setting($conn, 'last_audio_video_cleanup', '')`). Cleanup runs at most once every 15–30 minutes, safely wrapped in a `try-catch` block.

6. **Incomplete Recitation Assistance Workflow (Bug #1)**  
   - *Original Plan Flaw:* `mem_assistance_requests` only had a note field with a "Mark Fulfilled" button, with no mechanism for the teacher to provide audio recitation, no deep link to student WhatsApp, and no student-side state display.  
   - *Fix:* Add `admin_audio VARCHAR(255) NULL` and `admin_notes TEXT NULL` to `mem_assistance_requests`. Provide teacher audio upload/recorder in Section G, a WhatsApp link using `users.phone` and `normalize_phone_to_intl()`, and show pending/completed assistance status and teacher audio directly on the student's memorization page. Also update `teaching_pending_count()` so the admin sidebar badge alerts the teacher to pending assistance requests.

7. **Qur'an Grid CSS & Inline Style Overrides (Bug #3)**  
   - *Original Plan Misconception:* "classes have no CSS anywhere (verified by grep)".  
   - *Reality in Codebase:* `.page-cell` was actually defined in `student/hafiz_revision.php` within a `<style>` block, but missing from `quran_memorization.php` and `components.css`. Furthermore, both files had inline container styles `style="display:flex;flex-wrap:wrap;gap:3px;padding:10px 0;"` that would override `.mem-grid`'s `display: grid`.  
   - *Fix:* Unify `.mem-grid`, `.page-cell`, and all status color variants in `assets/CSS/components.css`. Strip the conflicting inline flex styles from both `student/quran_memorization.php` and `student/hafiz_revision.php`.

8. **Feedback Page Display & Deletion Bugs (Bug #4)**  
   - *Original Plan Flaw:* Only added a UNION query to `student/feedback.php` without adapting the card template, status badges (`'passed'` vs `'accepted'`), or audio playback.  
   - *Fix:* Align the UNION column signature with a unified timestamp `sort_date ORDER BY sort_date DESC`. Update the card to handle `status IN ('passed','accepted')` as green, format `Pages X–Y` (instead of `Verses ...`), play audio feedback via `media_player_html()` (safe for webm/m4a/mp3), and restrict the `delete_feedback.php` form to pure audio recitations to prevent foreign key errors. Also add a persistent feedback card directly inside `student/quran_memorization.php`.

9. **Missing `completed_at` Triggers on Plan Completion**  
   - *Original Plan Flaw:* Only updated `student/my_learning.php`.  
   - *Reality:* Plans can also be completed when the teacher accepts the final verse in `review_recitation.php` via `maybe_auto_request_next_lesson()`, or when an admin checks surahs in `admin/update_completed_surahs.php`.  
   - *Fix:* Update `maybe_auto_request_next_lesson()`, `my_learning.php`, and `update_completed_surahs.php` to set `completed_at = COALESCE(completed_at, NOW())`.

---

## 2. Database Schema — `admin/db_migrate17.php`

Idempotent migration following the strict pattern of `admin/db_migrate16.php` (checks existence before altering):

```sql
-- 1. Extend session_type ENUM in quran_murajaah_sessions
ALTER TABLE quran_murajaah_sessions 
    MODIFY COLUMN session_type ENUM('live','audio','video','inperson') NOT NULL DEFAULT 'video';

-- 2. Add index for 36h Muraja'ah video auto-deletion
ALTER TABLE quran_murajaah_sessions 
    ADD INDEX idx_audio_submitted (audio_file, submitted_at);

-- 3. Create mem_assistance_requests table
CREATE TABLE IF NOT EXISTS mem_assistance_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    task_day INT NOT NULL,
    start_page INT NOT NULL,
    end_page INT NOT NULL,
    note TEXT NULL,
    admin_audio VARCHAR(255) NULL,
    admin_notes TEXT NULL,
    status ENUM('pending','done') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    resolved_by INT NULL,
    INDEX idx_status (status),
    INDEX idx_student (student_id),
    INDEX idx_student_day (student_id, task_day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Add completed_at and audio_cleaned to student_learning
ALTER TABLE student_learning 
    ADD COLUMN completed_at DATETIME NULL AFTER status,
    ADD COLUMN audio_cleaned TINYINT(1) NOT NULL DEFAULT 0 AFTER completed_at,
    ADD INDEX idx_cleanup (status, audio_cleaned, completed_at);

-- 5. Backfill existing completed plans
UPDATE student_learning 
SET completed_at = NOW() 
WHERE status = 'completed' AND completed_at IS NULL;
```

---

## 3. Bug #1 — Student Recitation Assistance Request

**Issue Statement:** Students who need today's memorization page recited to them by the admin/teacher need an assistance button that sends a recitation request to the admin. Students who are proficient can simply mark the page as memorized and continue.

### 3a. Student Interface (`student/quran_memorization.php`)
- In the **Memorization day card** (when `gate.state === 'memorization'`):
  - Check for an existing assistance request for today's page via `mem_assistance_current($conn, $student_id, (int)$task['start_page'])`.
  - **State A (No request):** Render primary button `"Mark as Memorized"` and secondary button `"Need Teacher to Recite This Page? Request Assistance"`.
    - Clicking opens a clean modal with an optional note field ("Any specific verses or tajweed questions?").
    - Submits via POST to `submit_memorization.php` with `action=assist&page=N`.
  - **State B (Request Pending):** Show an info notice:  
    `[Clock Icon] Assistance Requested — Awaiting Teacher Recitation. Your teacher has been notified.`  
    The "Mark as Memorized" button remains enabled so proficient students can proceed whenever ready.
  - **State C (Request Fulfilled):** Show a success panel:  
    `[Check Icon] Teacher Recitation Available for Page N`.  
    - If `admin_audio` was provided, render audio player via `media_player_html($request['admin_audio'], '../uploads/admin_feedback/')`.
    - If `admin_notes` was provided, show notes: `Teacher notes: "..."`.

### 3b. Submission Handler (`student/submit_memorization.php`)
- Handle `action === 'assist'`:
  - Verify active memorizer state and that today is a memorization day.
  - Check for duplicate pending requests for this student and page. If already pending, return friendly notice.
  - Insert into `mem_assistance_requests` (`student_id`, `task_day`, `start_page`, `end_page`, `note`, `status='pending'`).
  - Redirect with `?assist_requested=1` or return `OK` if AJAX.

### 3c. Admin Queue & Action (`admin/teaching.php` & `admin/handle_mem_assistance.php`)
- **`admin/teaching.php` — Section G: Memorization Recitation Assistance Requests:**
  - Query pending requests via `mem_assistance_pending($conn)`.
  - Display card: Student Name, Email, Page to Recite (`Page X`), Task Day, Student Note, Time Requested.
  - **Teacher Actions:**
    1. **WhatsApp Link:** Deep link to student's phone using `normalize_phone_to_intl($r['phone'])` with pre-filled message:  
       `"Assalamu alaikum [Student], regarding your recitation assistance request for Page [X] of the Qur'an..."`
    2. **Fulfillment Form:**
       - Optional upload/record audio recitation (`admin_audio`) saved to `uploads/admin_feedback/` via `audio_save_upload()`.
       - Optional advice notes (`admin_notes`).
       - Submit button: `"Mark Fulfilled & Send Recitation"`.
- **`admin/handle_mem_assistance.php` (NEW):**
  - Requires admin role + CSRF verify.
  - Updates `mem_assistance_requests`: `status='done'`, `admin_audio=$filename`, `admin_notes=$notes`, `resolved_at=NOW()`, `resolved_by=$admin_id`.
  - Redirects back to `teaching.php` with success banner.

### 3d. Admin Sidebar Badge & Counters
- Update `teaching_pending_count($conn)` in `config/security/helpers.php` to include:
  ```php
  if (db_table_exists($conn, 'mem_assistance_requests')) {
      $assistance = (int)$conn->query(
          "SELECT COUNT(*) c FROM mem_assistance_requests WHERE status = 'pending'"
      )->fetch_assoc()['c'];
      $total += $assistance;
  }
  ```
- Update `admin/dashboard.php` pending counter cards accordingly.

---

## 4. Bug #2 — Muraja'ah: Video-Only Upload, In-Person Recitation, 36h Auto-Delete

**Issue Statement:** Pure audio cannot be accepted for Muraja'ah due to cheating risks; only video recorded from a distance (surroundings and face visible, no selfie) is accepted. Students who can recite directly to the teacher in person need a way for the teacher to mark Muraja'ah complete. All uploaded Muraja'ah videos must automatically delete from system and DB after 36 hours.

### 4a. Student Interface (`student/quran_memorization.php`)
- **Remove Audio Recording:** Remove the *Record Audio* button, the `audioPanel` DOM, recorder JS bindings, and the `/assets/js/recorder.js` script tag from this page.
- **Three Muraja'ah Modes:**
  1. **Upload Video:**
     - File input: `accept="video/*,.mp4,.mov,.m4v,.3gp,.3gpp"`
     - Clear instructions: *"Record yourself sitting at a distance where your surroundings and face are fully visible. Audio-only and selfie recordings are strictly rejected."*
     - Preview: `<video id="uploadPreview" controls playsinline style="display:none;width:100%;max-width:400px;border-radius:8px;background:#000;"></video>`.
     - Client-side check: Disallow audio MIME types (`file.type.startsWith('audio/')`) immediately with an informative alert.
     - `submitUpload()` sends `action=murajaah&session_type=video`.
  2. **Live via WhatsApp:** Keep existing WhatsApp scheduling flow (`session_type=live`).
  3. **Recited In Person:**
     - Modal tab / button: *"I recited / will recite to my teacher in person"*.
     - Submits `action=murajaah&session_type=inperson` (no media file required).
     - State becomes pending confirmation in teacher's queue.

### 4b. Video Validation in Submission Handler (`student/submit_memorization.php`)
- When `session_type === 'video'`:
  - Validate file upload error is `UPLOAD_ERR_OK`.
  - Read header bytes using `file_get_contents($tmpPath, false, null, 0, 65536)`.
  - Validate container: `audio_sniff_type()` must detect `mp4` (or video format), and `audio_detect_video_track()` must confirm an actual video track (rejecting disguised audio files like renamed MP3/M4A).
  - Save via `audio_save_upload()` with prefix `mem_vid_`.
  - Call `mem_submit_murajaah($conn, $student_id, 'video', $res['file'])`.
- When `session_type === 'inperson'`:
  - Call `mem_submit_murajaah($conn, $student_id, 'inperson', '')`.

### 4c. Engine Updates (`config/security/helpers.php`)
- **`mem_submit_murajaah()`**:
  - Accept `session_type` in `['video', 'live', 'inperson', 'audio']` (keep `'audio'` for backward compatibility with existing rows).
- **`mem_cleanup_expired_murajaah($conn)`**:
  - Query:
    ```sql
    SELECT id, audio_file FROM quran_murajaah_sessions
    WHERE audio_file IS NOT NULL 
      AND audio_file != '' 
      AND submitted_at <= NOW() - INTERVAL 36 HOUR
    ```
  - For each row:
    - Target file: `dirname(__DIR__, 2) . '/uploads/student_audio/' . basename($row['audio_file'])`.
    - If `is_file($path)`, `@unlink($path)`.
    - Update database: `UPDATE quran_murajaah_sessions SET audio_file = NULL WHERE id = ?`.
  - Result: Video is completely deleted from the file system and DB reference is cleared to save disk space, while the session history and teacher grades are safely preserved.

### 4d. Admin Teaching Queue (`admin/teaching.php`)
- **Fix Critical Bug at Line 512:** Change condition from checking only `session_type === 'audio'` to:
  ```php
  <?php if (in_array($mq['session_type'], ['audio', 'video'], true) && !empty($mq['audio_file'])): ?>
      <div style="margin:10px 0;"><?= media_player_html($mq['audio_file'], '../uploads/student_audio/') ?></div>
  <?php endif; ?>
  ```
- Badges:
  - `session_type === 'video'`: Purple badge `Video Submission`
  - `session_type === 'inperson'`: Teal badge `In Person Recitation`
  - `session_type === 'live'`: Amber badge `Live via WhatsApp`

### 4e. Teacher Direct In-Person Completion
- In `admin/memorization_students.php` (Manage Modal):
  - When today's task is `murajaah` (regardless of whether the student submitted online):
    Display button: `[Check Icon] Mark Muraja'ah Complete (Recited in Person)`.
  - Submits to `admin/memorization_action.php` with `action=complete_murajaah&student_id=ID`.
- In `admin/memorization_action.php`:
  - Handle `action === 'complete_murajaah'`:
    1. Verify today is indeed a `murajaah` task for the active student.
    2. Check if a pending session already exists for today (`mem_current_murajaah()`).
       - If pending: pass it directly via `mem_mark_murajaah($conn, $existing['id'], 'passed', 'Recited in person to teacher', null, $admin_id)`.
       - If no session exists: insert a session row with `session_type='inperson'`, `status='pending'`, then immediately pass it with `mem_mark_murajaah()`.
    3. The engine automatically handles split-day transitions (advances morning to evening, or completes the day and moves to the next slot).

---

## 5. Bug #3 — 604-Page Qur'an Grid Styling

**Issue Statement:** In Qur'an Memorization, page numbers are not well presented in small, suitable boxes with respective colors. They appear unstyled and unattractive.

### 5a. Styling in `assets/CSS/components.css`
Add a dedicated, modern grid system with clear visual hierarchy:

```css
/* =========================================================
   604-Page Qur'an Grid & Page Cells
   ========================================================= */
.mem-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(26px, 1fr));
    gap: 4px;
    padding: 12px 0;
}

.page-cell {
    width: 26px;
    height: 26px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.68rem;
    font-weight: 600;
    border-radius: 5px;
    border: 1px solid var(--border, #e5e7eb);
    background: var(--panel-bg, #f9fafb);
    color: var(--text-muted, #6b7280);
    text-decoration: none;
    transition: all 0.15s ease;
    user-select: none;
}

.page-cell:hover {
    transform: scale(1.15);
    z-index: 2;
    box-shadow: 0 2px 6px rgba(0,0,0,0.12);
}

/* Status variants */
.page-done {
    background: #d1fae5;
    color: #065f46;
    border-color: #6ee7b7;
}

.mem-revised {
    background: #ccfbf1;
    color: #0f766e;
    border-color: #5eead4;
}

.mem-under {
    background: #ede9fe;
    color: #5b21b6;
    border-color: #c4b5fd;
}

.page-current {
    background: var(--gold-light, #fef3c7);
    color: var(--gold-deep, #b45309);
    border-color: var(--gold-300, #fbbf24);
    font-weight: 800;
    animation: pulse 2s infinite;
}

.page-locked {
    background: #f3f4f6;
    color: #9ca3af;
    border-color: #e5e7eb;
}

.page-failed {
    background: #fee2e2;
    color: #b91c1c;
    border-color: #fca5a5;
}

.page-pending {
    background: #fef9c3;
    color: #a16207;
    border-color: #fde047;
}
```

### 5b. HTML Cleanup in Views
- In `student/quran_memorization.php`:
  - Replace `<div class="mem-grid" style="display:flex;flex-wrap:wrap;gap:3px;padding:10px 0;">` with `<div class="mem-grid">`.
  - Remove duplicate inline styles in the legend below the grid.
- In `student/hafiz_revision.php`:
  - Replace `<div style="display:flex;flex-wrap:wrap;gap:3px;padding:10px 0;">` with `<div class="mem-grid">`.
  - Strip redundant `<style>` declarations that conflict with `components.css`.

---

## 6. Bug #4 — Memorizer Feedback Display

**Issue Statement:** When an admin sends feedback to a memorizer, the feedback is nowhere to be found.

### 6a. Main Feedback Page (`student/feedback.php`)
- **Query Union:** Add a third query branch for `quran_murajaah_sessions`:
  ```php
  (
      SELECT   
          sr.id,
          sr.rating,
          sr.feedback,
          sr.admin_audio_feedback,
          sr.status,
          l.from_verse,
          l.to_verse,
          s.name_en AS surah_name,
          'audio' AS type,
          sr.submitted_at AS sort_date
      FROM student_recitation sr
      JOIN lessons l ON l.id = sr.learning_plan_id
      JOIN surahs s ON s.id = l.surah_id
      WHERE sr.student_id = $student_id
        AND sr.student_deleted = 0
  )
  UNION ALL
  (
      SELECT
          lr.id,
          NULL AS rating,
          CONCAT('Live recitation scheduled for ', lr.preferred_date, ' at ', lr.preferred_time) AS feedback,
          NULL AS admin_audio_feedback,
          lr.status,
          l.from_verse,
          l.to_verse,
          s.name_en AS surah_name,
          'live' AS type,
          lr.created_at AS sort_date
      FROM live_recitation_requests lr
      JOIN lessons l ON l.id = lr.lesson_id
      JOIN surahs s ON s.id = l.surah_id
      WHERE lr.student_id = $student_id
        AND lr.status IN ('accepted','rejected')
  )
  UNION ALL
  (
      SELECT
          qms.id,
          CONCAT('Day ', qms.task_day) AS rating,
          qms.feedback,
          qms.admin_audio_feedback,
          qms.status,
          qms.start_page AS from_verse,
          qms.end_page AS to_verse,
          'Qur\'an Muraja\'ah' AS surah_name,
          'murajaah' AS type,
          COALESCE(qms.reviewed_at, qms.submitted_at) AS sort_date
      FROM quran_murajaah_sessions qms
      WHERE qms.student_id = $student_id
        AND qms.status IN ('passed','failed')
  )
  ORDER BY sort_date DESC
  ```
- **Card Template Rendering:**
  - Type badge: If `type === 'murajaah'`, render `<span class="type-badge" style="background:#7c3aed;color:#fff;">MURAJA'AH ASSESSMENT</span>`.
  - Status badge: Check `in_array($row['status'], ['accepted','passed'], true) ? 'status-accepted' : 'status-rejected'`.
  - Heading: For muraja'ah, show `Qur'an Muraja'ah` with `Pages <?= $row['from_verse'] ?>–<?= $row['to_verse'] ?> · <?= $row['rating'] ?>`.
  - Media player: Use `media_player_html($row['admin_audio_feedback'], '../uploads/admin_feedback/')` which correctly handles webm, m4a, and mp3 formats.
  - Delete feedback form: Guard with `if ($row['type'] === 'audio')` so foreign keys in `delete_feedback.php` are never called on muraja'ah IDs.

### 6b. Memorization Dashboard (`student/quran_memorization.php`)
- Add a **"Recent Muraja'ah Reviews & Teacher Feedback"** card:
  - Query the student's 5 most recent reviewed muraja'ah sessions (`status IN ('passed', 'failed')`).
  - Render each with: Day & Page Range, Pass/Fail badge, Review Date, Written Teacher Notes, and Teacher Audio Feedback Player.
  - This ensures feedback remains visible even after the student passes and advances to the next task.

---

## 7. Feature #5 — Non-Hafiz / Non-Memorizer 24h Audio Auto-Deletion

**Issue Statement:** For the non-Hafiz and non-Memorizer learning system, all completed learning cycle audios must automatically delete from system and DB after 24 hours. While a learning cycle is yet to complete, audios must always remain available.

### 7a. Learning Cycle Definition in Codebase
A learning cycle is tracked in `student_learning` for a specific student and surah.  
- While `status = 'active'`, the cycle is in progress. All audio files (`admin_audio`, `student_recitation`, `admin_audio_feedback`) must remain untouched.
- When all verses of the surah are completed, the cycle completes (`status = 'completed'`).
- 24 hours after completion (`completed_at <= NOW() - INTERVAL 24 HOUR`), all associated audio files are deleted from the disk and DB fields are cleared.

### 7b. Ensuring `completed_at` Is Set on All Completion Triggers
1. **`student/my_learning.php`:**  
   When `completed_requests >= total_requests`:
   ```php
   UPDATE student_learning 
   SET status='completed', completed_at = COALESCE(completed_at, NOW()), completed_requests=? 
   WHERE id=?
   ```
2. **`config/security/helpers.php` (`maybe_auto_request_next_lesson()`):**  
   When the teacher accepts the final verse of a surah (`$from_verse > $total_verses`), immediately mark the plan completed so the 24h timer starts without waiting for the student to visit `my_learning.php`:
   ```php
   if ($from_verse > $total_verses) {
       $upd = $conn->prepare("
           UPDATE student_learning 
           SET status='completed', completed_at = COALESCE(completed_at, NOW()) 
           WHERE student_id = ? AND surah_id = ? AND status = 'active'
       ");
       $upd->bind_param("ii", $student_id, $surah_id);
       $upd->execute();
       return 'skipped_surah_complete';
   }
   ```
3. **`admin/update_completed_surahs.php`:**  
   When an admin checks surahs as completed:
   ```php
   INSERT INTO student_learning (student_id, surah_id, verses_per_request, completed_requests, status, completed_at, audio_cleaned)
   VALUES (?, ?, 0, 0, 'completed', NOW(), 0)
   ON DUPLICATE KEY UPDATE status='completed', completed_at = COALESCE(completed_at, NOW())
   ```
   If unchecked to `'pending'`, set `completed_at = NULL, audio_cleaned = 0`.

### 7c. Deletion Helper (`config/security/helpers.php`)
Implement `cleanup_completed_cycle_audios($conn)`:
1. Fetch completed, uncleaned plans older than 24 hours:
   ```sql
   SELECT id, student_id, surah_id 
   FROM student_learning 
   WHERE status = 'completed' 
     AND audio_cleaned = 0 
     AND completed_at IS NOT NULL 
     AND completed_at <= NOW() - INTERVAL 24 HOUR
   LIMIT 50
   ```
2. For each plan:
   - Find all related lesson IDs:
     ```sql
     SELECT id FROM lessons WHERE student_id = ? AND surah_id = ?
     ```
   - For these lessons:
     - **Admin lesson audio (`admin_audio`):**  
       Select `audio_file` from `admin_audio WHERE learning_plan_id IN (...)`.  
       Unlink files from `uploads/admin_audio/`.  
       Delete rows: `DELETE FROM admin_audio WHERE learning_plan_id IN (...)`.
     - **Student recitations & teacher feedback (`student_recitation`):**  
       Select `audio_file, admin_audio_feedback` from `student_recitation WHERE learning_plan_id IN (...)`.  
       Unlink `audio_file` from `uploads/student_audio/`.  
       Unlink `admin_audio_feedback` from `uploads/admin_feedback/`.  
       Update rows in DB:  
       `UPDATE student_recitation SET audio_file = '', admin_audio_feedback = NULL WHERE learning_plan_id IN (...)`.  
       *(Note: Rows are NOT deleted — scores, ratings, dates, and written feedback remain intact).*
   - Mark plan cleaned:
     ```sql
     UPDATE student_learning SET audio_cleaned = 1 WHERE id = ?
     ```

---

## 8. Opportunistic Cleanup Engine & Throttling

To ensure reliable execution on shared hosting without cron, cleanup runs opportunistically with a 15-minute throttle:

```php
if (!function_exists('run_opportunistic_cleanup')) {
    /**
     * Run periodic media cleanups:
     * 1. Muraja'ah video submissions older than 36 hours.
     * 2. Completed learning cycle audios older than 24 hours.
     * Throttled to run at most once every 15 minutes.
     */
    function run_opportunistic_cleanup($conn) {
        try {
            $last_run = (string)setting($conn, 'last_audio_video_cleanup', '');
            if ($last_run !== '' && strtotime($last_run) > time() - 900) {
                return; // throttled (ran less than 15 mins ago)
            }

            // Update timestamp first to prevent concurrent execution
            try {
                $conn->query("
                    INSERT INTO app_settings (setting_key, setting_value) 
                    VALUES ('last_audio_video_cleanup', NOW())
                    ON DUPLICATE KEY UPDATE setting_value = NOW()
                ");
            } catch (Throwable $e) { /* ignore */ }

            // Execute sweeps
            if (function_exists('mem_cleanup_expired_murajaah')) {
                mem_cleanup_expired_murajaah($conn);
            }
            if (function_exists('cleanup_completed_cycle_audios')) {
                cleanup_completed_cycle_audios($conn);
            }
        } catch (Throwable $e) {
            // Failsafe: never break page rendering
            error_log('Opportunistic cleanup error: ' . $e->getMessage());
        }
    }
}
```

**Placement:** Call `run_opportunistic_cleanup($conn)` right after DB connect in:
- `student/dashboard.php`
- `student/my_learning.php`
- `student/quran_memorization.php`
- `admin/dashboard.php`
- `admin/teaching.php`
- `admin/memorization_students.php`

---

## 9. Complete Files Touched Summary

| File | Type | Changes |
|------|------|---------|
| `admin/db_migrate17.php` | **NEW** | Idempotent migration: `quran_murajaah_sessions.session_type`, `mem_assistance_requests` table, `student_learning.completed_at` & `audio_cleaned` columns + indexes. |
| `admin/handle_mem_assistance.php` | **NEW** | Admin action handler: saves teacher audio recitation & notes, marks assistance request fulfilled. |
| `student/quran_memorization.php` | Modify | Add recitation assistance request button + modal + status notices; restrict upload to video with `<video>` preview; add in-person option; remove audio recorder; use `.mem-grid`; add persistent feedback card. |
| `student/submit_memorization.php` | Modify | Handle `action=assist`; validate video uploads (sniffing container + video track detection); handle `action=murajaah&session_type=inperson`. |
| `admin/teaching.php` | Modify | **Fix critical bug at line 512** to render videos for `video` sessions; add Section G for assistance requests with WhatsApp deep links and audio upload; add video/in-person badges in Section F. |
| `admin/memorization_students.php` | Modify | Add `"Mark Muraja'ah Complete (Recited in Person)"` button in Manage modal when today is a muraja'ah day. |
| `admin/memorization_action.php` | Modify | Add `action=complete_murajaah` to pass or create+pass in-person muraja'ah sessions directly. |
| `student/feedback.php` | Modify | Add `quran_murajaah_sessions` to UNION query; fix status badge for `'passed'`; render page range; play audio via `media_player_html()`; guard delete form. |
| `student/my_learning.php` | Modify | Set `completed_at = COALESCE(completed_at, NOW())` on auto-completion. |
| `admin/update_completed_surahs.php` | Modify | Set `completed_at = NOW(), audio_cleaned = 0` when surah checked; reset to `NULL` when unchecked. |
| `config/security/helpers.php` | Modify | Add `mem_assistance_pending()`, `mem_assistance_current()`, `mem_cleanup_expired_murajaah()`, `cleanup_completed_cycle_audios()`, `run_opportunistic_cleanup()`; update `teaching_pending_count()` to include assistance requests; update `maybe_auto_request_next_lesson()` to mark plan completed on final verse. |
| `assets/CSS/components.css` | Modify | Unify `.mem-grid`, `.page-cell` (26px square), and all color variants (`page-done`, `mem-revised`, `mem-under`, `page-current`, `page-locked`, `page-failed`, `page-pending`). |
| `student/hafiz_revision.php` | Modify | Replace inline `display:flex` with `.mem-grid` to benefit from unified grid styles. |
| `student/dashboard.php`, `admin/dashboard.php` | Modify | Call `run_opportunistic_cleanup()`; update admin dashboard pending cards. |

---

## 10. Verification & Validation Checklist

1. **Database Migration:**  
   - Run `admin/db_migrate17.php` in browser. Verify green success indicators for all steps.  
   - Re-run immediately to verify idempotency (zero errors, reports "already present").
2. **Bug #1 (Recitation Assistance):**  
   - As a student on a memorization day: verify `"Need Teacher to Recite This Page?"` button appears.  
   - Submit a request with a note: verify confirmation and notice that assistance is pending.  
   - As admin in `teaching.php`: verify Section G shows the request, student name, and page number.  
   - Test WhatsApp deep link.  
   - Upload audio recitation / notes and click `"Mark Fulfilled"`.  
   - Reload student page: verify teacher recitation audio player and notes are prominently displayed.  
   - Click `"Mark as Memorized"`: verify student advances to the next task.
3. **Bug #2 (Video Only & In-Person Muraja'ah):**  
   - On a Muraja'ah day: verify audio recorder is gone.  
   - Try uploading an audio file (`.mp3`): verify rejected by both client-side check and server-side track detection.  
   - Upload a genuine video (`.mp4`): verify uploaded and displayed in admin Section F with `<video>` player.  
   - Test student `"Recited In Person"` button: verify pending row in Section F.  
   - As admin in `memorization_students.php`: open Manage modal for a student on Muraja'ah day, click `"Mark Muraja'ah Complete (Recited in Person)"`. Verify student advances immediately.
4. **Bug #3 (604-Page Grid):**  
   - View `student/quran_memorization.php` and `student/hafiz_revision.php`.  
   - Verify page numbers are neatly contained inside beautiful, small (~26px) boxes with rounded corners, clear numbers, distinct colors, and a pulsing current page.
5. **Bug #4 (Feedback Visibility):**  
   - Submit Muraja'ah review with teacher notes and audio.  
   - As student, visit `student/feedback.php`: verify card appears under `MURAJA'AH ASSESSMENT` with Pass/Fail badge, page range, notes, and playable audio.  
   - Check `student/quran_memorization.php`: verify recent Muraja'ah feedback card displays the latest review.
6. **Feature #1 & 36h Auto-Cleanup:**  
   - Run a test script to backdate a `submitted_at` on a Muraja'ah video (>36h) and a `completed_at` on a completed learning cycle (>24h).  
   - Trigger `run_opportunistic_cleanup($conn)`.  
   - Verify physical files are unlinked from `uploads/student_audio/`, `uploads/admin_audio/`, and `uploads/admin_feedback/`.  
   - Verify `quran_murajaah_sessions.audio_file` is set to NULL, `student_learning.audio_cleaned` is set to 1, and student recitation feedback records remain intact.