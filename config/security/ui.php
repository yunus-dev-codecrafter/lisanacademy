<?php
/* =========================================================
   config/security/ui.php
   Shared UI helpers: CSS links, sidebar, topbar, footer
   ========================================================= */

if (!function_exists('ui_css')) {
    function ui_css() {
        return '<meta charset="UTF-8">' . "\n" .
               '<link rel="stylesheet" href="/assets/CSS/base.css">' . "\n" .
               '<link rel="stylesheet" href="/assets/CSS/components.css">' . "\n" .
               '<link rel="stylesheet" href="/assets/CSS/layout.css">' . "\n" .
               '<link rel="stylesheet" href="/assets/CSS/icons.css">';
    }
}

if (!function_exists('ui_icon')) {
    /**
     * Render an inline SVG icon (stroke style, inherits currentColor).
     * $name is a key in $icons. $size sets width/height, $class is optional CSS class.
     */
    function ui_icon($name, $size = 18, $class = '') {
        $icons = [
            'grid'        => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
            'users'       => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'user'        => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'book'        => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
            'book-open'   => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
            'notes'       => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
            'clipboard'   => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/>',
            'bell'        => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
            'bulb'        => '<path d="M9 18h6"/><path d="M10 22h4"/><path d="M15.09 14c.18-.98.65-1.74 1.41-2.5A4.65 4.65 0 0 0 18 8 6 6 0 0 0 6 8c0 1 .23 2.23 1.5 3A4.61 4.61 0 0 1 8.91 14"/>',
            'chat'        => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>',
            'trophy'      => '<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>',
            'check'       => '<polyline points="20 6 9 17 4 12"/>',
            'check-circle'=> '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
            'close'       => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
            'mic'         => '<path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/>',
            'stop'        => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>',
            'trash'       => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/>',
            'send'        => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
            'upload'      => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
            'download'    => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
            'calendar'    => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
            'video'       => '<polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/>',
            'lock'        => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
            'phone'       => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>',
            'star'        => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
            'gift'        => '<polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/>',
            'alert'       => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
            'info'        => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
            'clock'       => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
            'heart'       => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>',
            'sprout'      => '<path d="M7 20h10"/><path d="M10 20c5.5-2.5.8-6.4 3-10"/><path d="M9.5 9.4c1.1.8 1.8 2.2 2.3 3.7-2 .4-3.5.4-4.8-.3-1.2-.6-2.3-1.9-3-4.2 2.8-.5 4.4 0 5.5.8z"/><path d="M14.1 6a7 7 0 0 0-1.1 4c1.9-.1 3.3-.6 4.3-1.4 1-1 1.6-2.3 1.7-4.6-2.7.1-4 1-4.9 2z"/>',
            'mail'        => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22 6 12 13 2 6"/>',
            'refresh'     => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
            'menu'        => '<line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="18" x2="20" y2="18"/>',
            'logout'      => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
            'arrow-left'  => '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
            'arrow-right' => '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>',
            'gem'         => '<polygon points="6 3 18 3 22 9 12 22 2 9"/><path d="M2 9h20"/><path d="M12 22l-4-13-2-6"/><path d="M12 22l4-13 2-6"/>',
            'headphones'  => '<path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/>',
            'voice'       => '<path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="22"/>',
            'search'      => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
            'chevron-down'=> '<polyline points="6 9 12 15 18 9"/>',
        ];

        $path = $icons[$name] ?? $icons['info'];

        $cls = $class !== '' ? ' class="' . $class . '"' : '';

        return '<svg' . $cls . ' xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
    }
}

if (!function_exists('ui_initial')) {
    function ui_initial($str) {
        $str = (string)$str;
        if ($str === '') return 'U';
        return function_exists('mb_substr') ? mb_substr($str, 0, 1) : substr($str, 0, 1);
    }
}

if (!function_exists('ui_sidebar')) {
    /**
     * Render the fixed sidebar with navigation for the given role.
     * $active should match one of the item keys.
     */
    function ui_sidebar($role, $active = '') {
        if ($role === 'admin') {
            $brandSub = 'Admin Panel';
            $pending_apps = function_exists('applications_pending_count') && isset($GLOBALS['conn'])
                ? applications_pending_count($GLOBALS['conn'])
                : 0;
            $pending_teaching = function_exists('teaching_pending_count') && isset($GLOBALS['conn'])
                ? teaching_pending_count($GLOBALS['conn'])
                : 0;
            $items = [
                'dashboard'    => ['dashboard.php',        'grid',   'Dashboard'],
                'islamiyya'    => ['islamiyya.php',        'book-open', 'Digital Islamiyya'],
                'students'     => ['students.php',         'users',  'Students'],
                'applications' => ['applications.php',     'clipboard', 'Applications' . ($pending_apps > 0 ? ' <span class="nav-badge">' . $pending_apps . '</span>' : '')],
                'teaching'     => ['teaching.php',         'book',   'Teaching' . ($pending_teaching > 0 ? ' <span class="nav-badge">' . $pending_teaching . '</span>' : '')],
                'recitations'  => ['recitations_list.php', 'notes',  'Recitations'],
                'fixplans'     => ['fix_plan_start_verse.php', 'book-open', 'Fix Plans'],
                'exams'        => ['exams.php',            'clipboard', 'Exams'],
                'holiday'      => ['holiday_settings.php', 'clock',   'Holiday'],
                'announcements'=> ['announcements.php',    'bell',   'Announcements'],
                'suggestions'  => ['suggestions.php',      'bulb',   'Suggestions'],
                'survey'       => ['survey_key.php',       'lock',   'Survey Key'],
            ];
    } else {
        $brandSub = 'Student';
        $is_hafiz = (isset($GLOBALS['conn']) && isset($_SESSION['user_id']))
            ? student_is_hafiz($GLOBALS['conn'], (int)$_SESSION['user_id'])
            : false;

        if ($is_hafiz) {
            $items = [
                'dashboard'    => ['dashboard.php',        'grid',   'Dashboard'],
                'islamiyya'    => ['islamiyya.php',        'book-open', 'Digital Islamiyya'],
                'revision'     => ['hafiz_revision.php',   'book',   'Qur\'an Revision'],
                'test'         => ['hafiz_test.php',       'calendar-check', 'Weekly Test'],
                'announcements'=> ['announcements.php',    'bell',   'Announcements'],
                'feedback'     => ['feedback.php',         'chat',   'Feedback'],
                'ranking'      => ['ranking.php',          'trophy', 'Ranking'],
                'profile'      => ['profile.php',          'user',   'Profile'],
            ];
        } else {
            $items = [
                'dashboard'    => ['dashboard.php',        'grid',   'Dashboard'],
                'islamiyya'    => ['islamiyya.php',        'book-open', 'Digital Islamiyya'],
                'learning'     => ['my_learning.php',      'book',   'My Learning'],
                'feedback'     => ['feedback.php',         'chat',   'Feedback'],
                'certificate'  => ['certificate.php',      'gem',    'Certificate'],
                'exam'         => ['exam.php',             'notes',  'Exam'],
                'ranking'      => ['ranking.php',          'trophy', 'Ranking'],
                'holiday'      => ['holiday.php',           'clock',  'Holiday'],
                'announcements'=> ['announcements.php',    'bell',   'Announcements'],
                'suggestions'  => ['suggestions.php',      'bulb',   'Suggestions'],
                'profile'      => ['profile.php',          'user',   'Profile'],
            ];
        }
    }

        $links = '';
        $i = 0;
        foreach ($items as $key => $item) {
            $cls = $key === $active ? 'nav-link active' : 'nav-link';
            $delay = ++$i * 0.03;
            $links .= '<a class="' . $cls . '" href="' . $item[0] . '" style="animation:riseIn .35s ease ' . $delay . 's both;">'
                    . '<span class="nav-ico">' . ui_icon($item[1], 19) . '</span><span>' . $item[2] . '</span></a>';
        }

        return '
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-mark">' . ui_icon('book', 22) . '</div>
        <div class="brand-name">Lisanun Mubeen<small>' . $brandSub . '</small></div>
        <button type="button" class="sidebar-close" onclick="toggleSidebar()" aria-label="Close menu">' . ui_icon('close', 20) . '</button>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-section">Menu</div>
        ' . $links . '
    </nav>
    <div class="sidebar-footer">&copy; Lisanun Mubeen Academy 2026</div>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay"></div>';
    }
}

if (!function_exists('ui_topbar')) {
    /**
     * Render the sticky top bar. $title is the page heading shown on the left.
     */
    function ui_topbar($title, $subtitle = '') {
        $name = $_SESSION['name'] ?? '';
        $avatar = $_SESSION['profile_image'] ?? '';
        $letter = ui_initial($name);

        $avatar_html = '';
        if ($avatar !== '' && $avatar !== 'default.png') {
            $avatar_html = '<img class="avatar" src="/uploads/profile_pics/' . htmlspecialchars($avatar) . '" alt="Avatar">';
        } else {
            $avatar_html = '<div class="avatar letter">' . htmlspecialchars($letter) . '</div>';
        }

        $logoutUrl = '/auth/logout.php';

        /* Survey-mode banner: shown only while an admin is impersonating a
           student, so the mode is always obvious and easy to leave. */
        $banner = '';
        if (function_exists('is_impersonating') && is_impersonating()) {
            $who = htmlspecialchars($_SESSION['name'] ?? 'Student');
            $banner = '
    <div class="impersonation-banner" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:8px 18px;background:linear-gradient(135deg,#b45309,#92400e);color:#fff;font-size:.85rem;box-shadow:0 2px 8px rgba(0,0,0,.15);">
        <span class="imp-text" style="display:inline-flex;align-items:center;gap:8px;">' . ui_icon('eye', 16) . ' Survey mode — you are viewing the site as <strong style="margin-left:2px;">' . $who . '</strong></span>
        <form method="POST" action="/admin/exit_impersonation.php" class="imp-form" style="margin:0;">
            ' . (function_exists('csrf_field') ? csrf_field() : '') . '
            <button class="btn btn-sm btn-ghost" type="submit" style="color:#fff;border-color:rgba(255,255,255,.6);">' . ui_icon('logout', 15) . ' Exit Survey</button>
        </form>
    </div>';
        }

        return $banner . '
<header class="topbar">
    <button type="button" class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Open menu">' . ui_icon('menu', 22) . '</button>
    <div class="topbar-title">' . ($subtitle !== '' ? '<span class="crumb">' . htmlspecialchars($subtitle) . '</span>' : '') . htmlspecialchars($title) . '</div>
    <div class="topbar-spacer"></div>
    <div class="topbar-user">
        ' . $avatar_html . '
        <span class="uname">' . htmlspecialchars($name) . '</span>
    </div>
    <a class="btn btn-ghost btn-sm" href="' . $logoutUrl . '">' . ui_icon('logout', 16) . ' Logout</a>
</header>';
    }
}

if (!function_exists('ui_footer')) {
    function ui_footer() {
        return '<footer class="footer">© Lisanun Mubeen Academy 2026</footer>';
    }
}

if (!function_exists('ui_page_start')) {
    /** Open body: sidebar + main-area + topbar. Call before rendering content. */
    function ui_page_start($role, $active, $title, $subtitle = '') {
        echo '<body>' . "\n";
        echo ui_sidebar($role, $active) . "\n";
        echo '<div class="main-area">' . "\n";
        echo ui_topbar($title, $subtitle) . "\n";
        echo '<main class="main-content">' . "\n";
    }
}

if (!function_exists('ui_page_end')) {
    /** Close content, footer, main-area, sidebar script. Page must close </body></html> itself. */
    function ui_page_end() {
        echo '</main>' . "\n";
        echo ui_footer() . "\n";
        echo '</div>' . "\n";
        echo '<script src="/assets/js/sidebar.js"></script>' . "\n";
        echo '<script src="/assets/js/audio_player.js"></script>' . "\n";
    }
}

if (!function_exists('ui_message_page')) {
    /**
     * Render a fully-styled standalone confirmation/notice page and exit.
     * $type is one of: success | danger | info | warning.
     * $message may contain HTML; $title is escaped automatically.
     */
    function ui_message_page($type, $title, $message, $back_url = '', $back_label = 'Go Back', $icon = '') {
        $types = ['success', 'danger', 'info', 'warning'];
        if (!in_array($type, $types, true)) $type = 'info';

        $icons = [
            'success' => 'check-circle',
            'danger'  => 'alert',
            'info'    => 'info',
            'warning' => 'alert',
        ];
        if ($icon === '') $icon = $icons[$type];

        $borders = [
            'success' => 'border-top:5px solid var(--success);',
            'danger'  => 'border-top:5px solid var(--danger);',
            'info'    => 'border-top:5px solid var(--info);',
            'warning' => 'border-top:5px solid var(--warning);',
        ];
        $border = $borders[$type];

        $back = '';
        if ($back_url !== '') {
            $back = '<a class="btn btn-block mt-2" href="' . htmlspecialchars($back_url) . '">' . htmlspecialchars($back_label) . '</a>';
        }

        echo '<!DOCTYPE html>' . "\n";
        echo '<html lang="en">' . "\n";
        echo '<head>' . "\n";
        echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">' . "\n";
        echo '<title>' . htmlspecialchars($title) . '</title>' . "\n";
        echo ui_css() . "\n";
        echo '</head>' . "\n";
        echo '<body>' . "\n";
        echo '<div class="center-screen">' . "\n";
        echo '<div class="card animate-rise" style="max-width:460px;width:100%;text-align:center;padding:34px;' . $border . '">' . "\n";
        echo '<div style="font-size:2.4rem;margin-bottom:10px;">' . ui_icon($icon, 34) . '</div>' . "\n";
        echo '<h3>' . htmlspecialchars($title) . '</h3>' . "\n";
        echo '<p class="small text-muted" style="word-break:break-word;">' . $message . '</p>' . "\n";
        echo $back . "\n";
        echo '</div>' . "\n";
        echo '</div>' . "\n";
        echo '</body>' . "\n";
        echo '</html>' . "\n";
        exit;
    }
}
