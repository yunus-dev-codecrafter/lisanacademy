<?php
// htdocs/index.php â€” Lisanun Mubeen Academy public homepage.
// The academy's primary marketing / conversion page.

require_once __DIR__ . '/config/security/session.php';
require_once __DIR__ . '/config/security/helpers.php';
require_once __DIR__ . '/config/db.php';

/* Auto-cleanup: hard-delete graduated accounts 7 days after graduation.
   Runs at most once per hour (no cron on free hosting). */
try {
    $last_purge = (string)setting($conn, 'last_graduation_purge', '');
    if ($last_purge === '' || strtotime($last_purge) <= time() - 3600) {
        purge_graduated_accounts($conn);
        try {
            $conn->query("INSERT INTO app_settings (setting_key, setting_value) VALUES ('last_graduation_purge', NOW())
                          ON DUPLICATE KEY UPDATE setting_value = NOW()");
        } catch (Throwable $e) { /* ignore */ }
    }
} catch (Throwable $e) { /* ignore */ }

/* Brand / contact settings */
$whatsapp_number = setting($conn, 'whatsapp_number', '2348029979040');
$contact_wa      = 'https://wa.me/' . preg_replace('/[^0-9]/', '', $whatsapp_number);
$registered      = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);

/* Real, legitimate platform statistics (guarded so a partial schema never crashes the page). */
$stat_students        = 0;
$stat_surahs_complete = 0;
$stat_recitations     = 0;
$stat_certificates    = 0;
$stat_graduates       = 0;
$graduates            = [];
try {
    $stat_students = (int)$conn->query("SELECT COUNT(*) c FROM users WHERE role = 'student'")->fetch_assoc()['c'];
} catch (Throwable $e) { /* ignore */ }
try {
    $stat_surahs_complete = (int)$conn->query("SELECT COUNT(DISTINCT surah_id) c FROM student_learning WHERE status = 'completed'")->fetch_assoc()['c'];
} catch (Throwable $e) { /* ignore */ }
try {
    $stat_recitations = (int)$conn->query("SELECT COUNT(*) c FROM student_recitation WHERE status = 'accepted'")->fetch_assoc()['c'];
} catch (Throwable $e) { /* ignore */ }
try {
    $stat_certificates = (int)$conn->query("
        SELECT COUNT(DISTINCT c.student_id) c
        FROM certificates c
        WHERE c.student_id IN (
            SELECT u.id FROM users u
            WHERE u.role = 'student'
              AND (SELECT COUNT(DISTINCT sl.surah_id) FROM student_learning sl
                   WHERE sl.student_id = u.id AND sl.status = 'completed')
                  = (SELECT COUNT(*) FROM surahs)
        )
    ")->fetch_assoc()['c'];
} catch (Throwable $e) { /* ignore */ }
try {
    $res = $conn->query("
        SELECT u.name, u.email
        FROM users u
        WHERE u.role = 'student'
          AND (SELECT COUNT(DISTINCT sl.surah_id) FROM student_learning sl
               WHERE sl.student_id = u.id AND sl.status = 'completed')
              = (SELECT COUNT(*) FROM surahs)
        ORDER BY u.name ASC
    ");
    while ($r = $res->fetch_assoc()) $graduates[] = $r;
    $stat_graduates = count($graduates);
} catch (Throwable $e) { /* ignore */ }

$dashboard_url = ($_SESSION['role'] ?? '') === 'admin' ? '/admin/dashboard.php' : '/student/dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lisanun Mubeen Academy | Online Qur'an Learning Academy</title>
<meta name="description" content="Learn, recite and progress in your Qur'an journey with Lisanun Mubeen Academy through structured online learning, live recitation, progress tracking and certificates.">
<meta property="og:title" content="Lisanun Mubeen Academy | Online Qur'an Learning Academy">
<meta property="og:description" content="Learn, recite and progress in your Qur'an journey with Lisanun Mubeen Academy through structured online learning, live recitation, progress tracking and certificates.">
<meta property="og:type" content="website">
<meta property="og:url" content="/">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>ðŸ“–</text></svg>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/CSS/homepage.css">
</head>
<body>

<!-- ============ NAVBAR ============ -->
<nav class="site-nav" id="siteNav">
    <div class="container">
        <a class="brand" href="#home">
            <span class="brand-mark"><?= ui_icon('book-open', 20) ?></span>
            <span class="brand-name">Lisanun Mubeen<small>Academy</small></span>
        </a>
        <div class="nav-links">
            <a href="#home">Home</a>
            <a href="#features">Features</a>
            <a href="#how">How It Works</a>
            <a href="#pricing">Pricing</a>
            <a href="#faq">FAQ</a>
            <a href="#contact">Contact</a>
        </div>
        <div class="nav-cta">
            <?php if ($registered): ?>
                <a class="btn btn-sm btn-gold" href="<?= $dashboard_url ?>"><?= ui_icon('grid', 15) ?> Go to Dashboard</a>
            <?php else: ?>
                <a class="btn btn-sm btn-outline" href="/auth/login.php">Login</a>
                <a class="btn btn-sm btn-gold" href="/register.php">Register Now</a>
            <?php endif; ?>
        </div>
        <button class="nav-toggle" id="navToggle" aria-label="Open menu" aria-expanded="false"><?= ui_icon('menu', 22) ?></button>
    </div>
    <div class="mobile-menu" id="mobileMenu">
        <a href="#home">Home</a>
        <a href="#features">Features</a>
        <a href="#how">How It Works</a>
        <a href="#pricing">Pricing</a>
        <a href="#faq">FAQ</a>
        <a href="#contact">Contact</a>
        <?php if ($registered): ?>
            <a class="btn btn-gold" href="<?= $dashboard_url ?>"><?= ui_icon('grid', 15) ?> Go to Dashboard</a>
        <?php else: ?>
            <a class="btn btn-gold" href="/register.php">Register Now</a>
            <a class="btn btn-outline" href="/auth/login.php" style="border-color:rgba(255,255,255,.3);color:#fff;">Login</a>
        <?php endif; ?>
    </div>
</nav>

<!-- ============ HERO ============ -->
<header class="hero" id="home">
    <div class="container">
        <div>
            <span class="hero-eyebrow"><?= ui_icon('book-open', 14) ?> Online Qur'an Academy</span>
            <h1>Come learn and let the Qur'an be your <span class="gold-text">life companion</span>.</h1>
            <p class="hero-tagline">"Come learn and let the Qur'an be your life companion."</p>
            <p class="hero-sub">
                Learn the Qur'an through a structured online learning experience â€” guided live recitation with your
                teacher, clear progress tracking, termly examinations and certificates for every Surah you complete.
            </p>
            <div class="hero-cta">
                <a class="btn btn-gold btn-lg" href="/register.php"><?= ui_icon('user', 18) ?> Register Now</a>
                <a class="btn btn-outline" href="/auth/login.php"><?= ui_icon('logout', 18) ?> Login</a>
            </div>
            <p class="hero-note">
                <span class="dot"></span> Join today for <strong>â‚¦3,000 per term</strong> &nbsp;Â·&nbsp;
                <span class="dot"></span> No hidden charges &nbsp;Â·&nbsp;
                <span class="dot"></span> Your journey starts on WhatsApp
            </p>
        </div>

        <div class="hero-visual">
            <div class="mockup">
                <div class="mockup-bar"><span></span><span></span><span></span></div>
                <div class="mockup-screen">
                    <div class="mockup-row">
                        <span class="m-ico g"><?= ui_icon('book', 16) ?></span>
                        <div style="flex:1;">
                            <p>Surah Al-Fatihah</p>
                            <span>Lesson 3 of 7</span>
                        </div>
                        <span class="mockup-bar-prog" style="width:70px;"><i style="width:55%"></i></span>
                    </div>
                    <div class="mockup-row">
                        <span class="m-ico y"><?= ui_icon('gem', 16) ?></span>
                        <div style="flex:1;">
                            <p>Certificate Earned</p>
                            <span>Surah An-Nas completed</span>
                        </div>
                    </div>
                    <div class="mockup-row">
                        <span class="m-ico b"><?= ui_icon('mic', 16) ?></span>
                        <div style="flex:1;">
                            <p>Live Recitation</p>
                            <span>Feedback from your teacher</span>
                        </div>
                    </div>
                    <div class="mockup-row">
                        <span class="m-ico g"><?= ui_icon('trophy', 16) ?></span>
                        <div style="flex:1;">
                            <p>Overall Progress</p>
                            <span>Keep going â€” every verse counts</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mockup-float a">
                <span class="fi g"><?= ui_icon('check-circle', 16) ?></span>
                <div><b>Lessons unlocked</b><span>Ready to recite</span></div>
            </div>
            <div class="mockup-float b">
                <span class="fi y"><?= ui_icon('star', 16) ?></span>
                <div><b>New feedback</b><span>From your teacher</span></div>
            </div>
        </div>
    </div>
</header>

<!-- ============ DIGITAL ISLAMIYYA ADVERTISEMENT ============ -->
<?php
$ad_image = 'assets/images/digital_islamiyya.png';
$ad_target = $registered ? ($dashboard_url) : '/register.php';
$ad_cta = $registered ? 'Explore Digital Islamiyya' : 'Start with the Academy';
if (function_exists('islamiyya_books')) {
    $islamiyya_live_any = false;
    foreach (islamiyya_books($conn) as $ab) {
        if (islamiyya_book_is_live($conn, $ab)) { $islamiyya_live_any = true; break; }
    }
    $ad_cta = $islamiyya_live_any ? 'Start Learning now' : ($registered ? 'Explore Digital Islamiyya' : 'Start with the Academy');
    $ad_target = $islamiyya_live_any ? '/student/islamiyya.php' : ($registered ? $dashboard_url : '/register.php');
} else {
    $ad_target = '/student/islamiyya.php';
}
?>
<section class="ad-section" id="islamiyya-ad" aria-label="Digital Islamiyya advertisement">
    <div class="container">
        <div class="ad-band animate-rise">
            <div class="ad-media">
                <img src="<?= htmlspecialchars($ad_image) ?>" alt="Digital Islamiyya â€” classical Islamic books program" loading="lazy">
            </div>
            <div class="ad-body">
                <span class="ad-eyebrow"><?= ui_icon('book-open', 14) ?> New Program</span>
                <h2>Digital Islamiyya</h2>
                <p>Learn the classical Islamic texts with your own teacher - audio and video lessons, graded quizzes, and weekly-pdf handouts. Your path through Tauhid, Fiqh, Hadith, Seerah and Arabic, unlock lesson by lesson.</p>
                <div class="ad-cta">
                    <a class="btn btn-gold" href="<?= htmlspecialchars($ad_target) ?>"><?= ui_icon('arrow-right', 17) ?> <?= htmlspecialchars($ad_cta) ?></a>
                    <span class="ad-note"><?= ui_icon('clock', 14) ?> Books become live as soon as their lessons and quizzes are ready.</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============ NUMBERS / TRUST ============ -->
<section class="section numbers" style="padding:44px 0 24px;">
    <div class="container">
        <div class="numbers-grid">
            <div class="number"><div class="num"><?= number_format($stat_students) ?>+</div><div class="lbl">Active Students</div></div>
            <div class="number"><div class="num gold"><?= number_format($stat_surahs_complete) ?>+</div><div class="lbl">Surahs Completed</div></div>
            <div class="number"><div class="num"><?= number_format($stat_recitations) ?>+</div><div class="lbl">Recitations Reviewed</div></div>
            <div class="number"><div class="num gold"><?= number_format($stat_certificates) ?>+</div><div class="lbl">Certificates Issued</div></div>
        </div>

    </div>
</section>

<!-- ============ GRADUATES ============ -->
<section class="section graduates" id="graduates">
    <div class="container">
        <div class="section-head">
            <span class="kicker"><?= ui_icon('gem', 14) ?> Our Graduates</span>
            <h2>Graduates of the Glorious Qur'an</h2>
            <p>Students who have completed the entire Qur'an with us.</p>
        </div>

        <?php if ($stat_graduates > 0): ?>
            <div class="graduates-grid">
                <?php foreach ($graduates as $g): ?>
                    <div class="grad-card">
                        <span class="grad-avatar"><?= htmlspecialchars(ui_initial($g['name'])) ?></span>
                        <div class="grad-meta">
                            <b class="grad-name"><?= htmlspecialchars($g['name']) ?></b>
                            <span class="grad-sub"><?= ui_icon('gem', 12) ?> Completed the Glorious Qur'an</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="grad-empty">
                <?= ui_icon('gem', 24) ?>
                <p>No graduates yet â€” the first student to complete the entire Qur'an will appear here.</p>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- ============ FEATURES ============ -->
<section class="section section-alt" id="features">
    <div class="container">
        <div class="section-head">
            <span class="kicker">Why Lisanun Mubeen</span>
            <h2>Everything You Need for a Consistent Qur'an Journey</h2>
            <p>A complete online learning platform â€” not just a WhatsApp class.</p>
        </div>
        <div class="features-grid">
            <div class="feature-card">
                <span class="f-ico g"><?= ui_icon('video', 22) ?></span>
                <h3>Live Qur'an Recitation</h3>
                <p>Recite your lessons with your teacher through Google Meet, with personal attention every step of the way.</p>
            </div>
            <div class="feature-card">
                <span class="f-ico y"><?= ui_icon('book-open', 22) ?></span>
                <h3>Structured Learning</h3>
                <p>Follow a clear learning plan built around your level and progress, one portion at a time.</p>
            </div>
            <div class="feature-card">
                <span class="f-ico g"><?= ui_icon('trophy', 22) ?></span>
                <h3>Track Your Progress</h3>
                <p>Monitor completed Surahs and watch your Qur'an journey grow across every term.</p>
            </div>
            <div class="feature-card">
                <span class="f-ico y"><?= ui_icon('chat', 22) ?></span>
                <h3>Teacher Feedback</h3>
                <p>Receive guidance, corrections and encouragement from your teacher on every recitation.</p>
            </div>
            <div class="feature-card">
                <span class="f-ico g"><?= ui_icon('notes', 22) ?></span>
                <h3>Termly Examinations</h3>
                <p>Participate in assessments each term to measure your progress and grow with confidence.</p>
            </div>
            <div class="feature-card">
                <span class="f-ico y"><?= ui_icon('gem', 22) ?></span>
                <h3>Certificates</h3>
                <p>Receive beautiful certificates whenever you successfully complete a Surah.</p>
            </div>
            <div class="feature-card">
                <span class="f-ico g"><?= ui_icon('refresh', 22) ?></span>
                <h3>Hafiz Revision Track</h3>
                <p>Already a Hafiz? Revise the entire Qur'an from memory â€” page by page, 20 pages every week, with a short weekly test to keep you sharp.</p>
            </div>
        </div>
    </div>
</section>

<!-- ============ HOW IT WORKS ============ -->
<section class="section" id="how">
    <div class="container">
        <div class="section-head">
            <span class="kicker">Simple Process</span>
            <h2>How It Works</h2>
            <p>From registration to your first lesson in four simple steps.</p>
        </div>
        <div class="steps">
            <div class="step">
                <div class="step-num">1</div>
                <h3>Register</h3>
                <p>Fill in a short application form. Your application is received by the academy instantly.</p>
            </div>
            <div class="step">
                <div class="step-num">2</div>
                <h3>Contact &amp; Payment</h3>
                <p>We contact you through WhatsApp with your payment details â€” just â‚¦3,000 per term.</p>
            </div>
            <div class="step">
                <div class="step-num">3</div>
                <h3>Account Activation</h3>
                <p>Once payment and arrangements are confirmed, the administrator activates your account.</p>
            </div>
            <div class="step">
                <div class="step-num">4</div>
                <h3>Start Learning</h3>
                <p>Log in and begin your Qur'an learning journey with your teacher and your learning plan.</p>
            </div>
        </div>
    </div>
</section>

<!-- ============ PLATFORM PREVIEW ============ -->
<section class="section section-alt" id="platform">
    <div class="container">
        <div class="section-head">
            <span class="kicker">A Real Platform</span>
            <h2>A Proper Learning Platform, Not Just WhatsApp</h2>
            <p>Every student gets a personal dashboard with lessons, feedback, certificates and more.</p>
        </div>
        <div class="platform-grid">
            <div class="platform-shot">
                <div class="shot-top">
                    <span class="si" style="background:linear-gradient(135deg,var(--emerald-700),var(--emerald-500));"><?= ui_icon('grid', 16) ?></span>
                    <div><b>Student Dashboard</b><span>Your journey at a glance</span></div>
                </div>
                <div class="shot-body">
                    <div class="shot-line w80"></div>
                    <div class="shot-line w60"></div>
                    <span class="shot-pill g"><?= ui_icon('check', 12) ?> Progress tracked</span>
                    <div class="shot-line w40"></div>
                </div>
            </div>
            <div class="platform-shot">
                <div class="shot-top">
                    <span class="si" style="background:linear-gradient(135deg,var(--gold),var(--gold-deep));"><?= ui_icon('mic', 16) ?></span>
                    <div><b>Live Recitation</b><span>Record &amp; submit</span></div>
                </div>
                <div class="shot-body">
                    <div class="shot-line w60"></div>
                    <div class="shot-line w80"></div>
                    <span class="shot-pill y"><?= ui_icon('mic', 12) ?> Teacher listens &amp; guides</span>
                    <div class="shot-line w40"></div>
                </div>
            </div>
            <div class="platform-shot">
                <div class="shot-top">
                    <span class="si" style="background:linear-gradient(135deg,#2563eb,#60a5fa);"><?= ui_icon('gem', 16) ?></span>
                    <div><b>Certificates</b><span>Reward for every Surah</span></div>
                </div>
                <div class="shot-body">
                    <div class="shot-cert">
                        <span class="seal"><?= ui_icon('check', 16) ?></span>
                        <b>CERTIFICATE OF COMPLETION</b>
                        <span>Surah completed successfully</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============ CERTIFICATE ============ -->
<section class="section" id="certificates">
    <div class="container">
        <div class="cert-wrap">
            <div>
                <div class="cert-card">
                    <div class="top">Lisanun Mubeen Academy</div>
                    <div class="title">Certificate of Completion</div>
                    <div class="sep"></div>
                    <div class="name">This is proudly presented to our student</div>
                    <p>for successfully completing a Surah of the Noble Qur'an.</p>
                    <div class="cert-seal"><?= ui_icon('star', 26) ?></div>
                </div>
            </div>
            <div class="cert-copy">
                <span class="kicker">Measurable &amp; Rewarding</span>
                <h2>Earn a Certificate for Every Surah You Complete</h2>
                <p>Your dedication deserves recognition. Every time you successfully complete a Surah, you receive a
                    certificate that marks your progress and keeps you motivated on your Qur'an journey.</p>
                <ul>
                    <li><?= ui_icon('check-circle', 18) ?> Certificates for each completed Surah</li>
                    <li><?= ui_icon('check-circle', 18) ?> Track every milestone in your dashboard</li>
                    <li><?= ui_icon('check-circle', 18) ?> Downloadable result sheets after examinations</li>
                </ul>
            </div>
        </div>
    </div>
</section>

<!-- ============ PRICING ============ -->
<section class="section section-alt" id="pricing">
    <div class="container">
        <div class="section-head">
            <span class="kicker">Transparent &amp; Affordable</span>
            <h2>Simple Pricing</h2>
            <p>One affordable fee keeps your learning consistent all term long.</p>
        </div>
        <div class="pricing-wrap">
            <div class="price-card">
                <div class="price"><sup>â‚¦</sup>3,000</div>
                <div class="per">PER TERM</div>
                <div class="price-rows">
                    <div class="price-row">
                        <span class="pri"><?= ui_icon('calendar', 18) ?></span>
                        <div><b>4 Months</b><span>of structured online learning per term</span></div>
                    </div>
                    <div class="price-row">
                        <span class="pri"><?= ui_icon('refresh', 18) ?></span>
                        <div><b>3 Terms Per Year</b><span>consistent, year-round growth</span></div>
                    </div>
                    <div class="price-row">
                        <span class="pri"><?= ui_icon('video', 18) ?></span>
                        <div><b>Everything Included</b><span>live recitation, feedback, exams &amp; certificates</span></div>
                    </div>
                </div>
                <a class="btn btn-gold btn-lg" href="/register.php"><?= ui_icon('send', 18) ?> Start Your Qur'an Journey</a>
            </div>
            <p class="price-note">Payment arrangements are confirmed with you personally through WhatsApp.</p>
        </div>
    </div>
</section>

<!-- ============ WHO IT'S FOR ============ -->
<section class="section" id="audience">
    <div class="container">
        <div class="section-head">
            <span class="kicker">Who It's For</span>
            <h2>Built for Every Qur'an Learner</h2>
            <p>Whatever your starting point, there is a place for you here.</p>
        </div>
        <div class="audience">
            <div class="aud-item">
                <span class="ai"><?= ui_icon('sprout', 18) ?></span>
                <div><b>Beginners</b><p>Start your Qur'an journey from the very first verse, guided step by step.</p></div>
            </div>
            <div class="aud-item">
                <span class="ai"><?= ui_icon('mic', 18) ?></span>
                <div><b>Improving Recitation</b><p>Polish your tajweed and recitation with regular teacher feedback.</p></div>
            </div>
            <div class="aud-item">
                <span class="ai"><?= ui_icon('book-open', 18) ?></span>
                <div><b>Structured Learning</b><p>Follow a clear, consistent plan instead of learning without direction.</p></div>
            </div>
            <div class="aud-item">
                <span class="ai"><?= ui_icon('calendar', 18) ?></span>
                <div><b>Need Accountability</b><p>Consistent lessons, reviews and deadlines keep you on track.</p></div>
            </div>
            <div class="aud-item">
                <span class="ai"><?= ui_icon('heart', 18) ?></span>
                <div><b>Lifelong Learners</b><p>Make the Qur'an a regular, rewarding part of your everyday life.</p></div>
            </div>
            <div class="aud-item">
                <span class="ai"><?= ui_icon('users', 18) ?></span>
                <div><b>Anyone, Anywhere</b><p>Learn online from home with a supportive community around you.</p></div>
            </div>
            <div class="aud-item">
                <span class="ai"><?= ui_icon('refresh', 18) ?></span>
                <div><b>Hafiz of the Qur'an</b><p>Revise from memory with a structured page-by-page plan â€” 20 pages each week and a short weekly test to keep your memorization strong.</p></div>
            </div>
        </div>
    </div>
</section>

<!-- ============ FAQ ============ -->
<section class="section section-alt" id="faq">
    <div class="container">
        <div class="section-head">
            <span class="kicker">Questions &amp; Answers</span>
            <h2>Frequently Asked Questions</h2>
        </div>
        <div class="faq-wrap" id="faqWrap">

            <div class="faq-item">
                <button class="faq-q" type="button">I've already memorised the Qur'an â€” is this for me?<span class="chev"><?= ui_icon('chevron-down', 18) ?></span></button>
                <div class="faq-a"><div class="faq-a-inner">Yes! Hafiz of the Qur'an have a dedicated <strong>revision track</strong>: you revise the full Qur'an from memory, 20 pages every week, with a short weekly test on what you've revised. Your teacher listens to your recitation and marks each test <strong>Pass or Fail</strong>, so your memorization stays sharp.</div></div>
            </div>

            <div class="faq-item">
                <button class="faq-q" type="button">How much is the fee?<span class="chev"><?= ui_icon('chevron-down', 18) ?></span></button>
                <div class="faq-a"><div class="faq-a-inner">The fee is <strong>â‚¦3,000 per term</strong>. Each term lasts about 4 months, with 3 terms per year.</div></div>
            </div>

            <div class="faq-item">
                <button class="faq-q" type="button">How long is one term?<span class="chev"><?= ui_icon('chevron-down', 18) ?></span></button>
                <div class="faq-a"><div class="faq-a-inner">One academic term is approximately <strong>4 months</strong>. There are 3 terms every year.</div></div>
            </div>

            <div class="faq-item">
                <button class="faq-q" type="button">How do the live classes work?<span class="chev"><?= ui_icon('chevron-down', 18) ?></span></button>
                <div class="faq-a"><div class="faq-a-inner">You recite your assigned lesson with your teacher through <strong>Google Meet</strong>, and also submit recorded recitations through your dashboard for detailed feedback.</div></div>
            </div>

            <div class="faq-item">
                <button class="faq-q" type="button">How do I register?<span class="chev"><?= ui_icon('chevron-down', 18) ?></span></button>
                <div class="faq-a"><div class="faq-a-inner">Tap <a href="/register.php"><strong>Register Now</strong></a> and fill in the short application form. Your application is received by the academy, and we contact you on WhatsApp with the next steps.</div></div>
            </div>

            <div class="faq-item">
                <button class="faq-q" type="button">How do I pay?<span class="chev"><?= ui_icon('chevron-down', 18) ?></span></button>
                <div class="faq-a"><div class="faq-a-inner">Payment details are shared with you personally through <strong>WhatsApp</strong> after your application is received. The fee is â‚¦3,000 per term.</div></div>
            </div>

            <div class="faq-item">
                <button class="faq-q" type="button">When will my account be activated?<span class="chev"><?= ui_icon('chevron-down', 18) ?></span></button>
                <div class="faq-a"><div class="faq-a-inner">After your payment and arrangements are confirmed, the <strong>administrator activates your account</strong> and provides your login details privately.</div></div>
            </div>

            <div class="faq-item">
                <button class="faq-q" type="button">Can I track my Qur'an progress?<span class="chev"><?= ui_icon('chevron-down', 18) ?></span></button>
                <div class="faq-a"><div class="faq-a-inner">Yes. Your student dashboard tracks completed Surahs, your current learning plan and your overall progress throughout your journey.</div></div>
            </div>

            <div class="faq-item">
                <button class="faq-q" type="button">Do students receive certificates?<span class="chev"><?= ui_icon('chevron-down', 18) ?></span></button>
                <div class="faq-a"><div class="faq-a-inner">Yes â€” you receive a <strong>certificate of completion</strong> for every Surah you successfully complete, which you can view and download from your dashboard.</div></div>
            </div>

            <div class="faq-item">
                <button class="faq-q" type="button">How does teacher feedback work?<span class="chev"><?= ui_icon('chevron-down', 18) ?></span></button>
                <div class="faq-a"><div class="faq-a-inner">After you submit a recitation, your teacher reviews it and gives you a rating, corrections and personal guidance â€” all visible in your dashboard.</div></div>
            </div>

            <div class="faq-item">
                <button class="faq-q" type="button">How do I contact the academy?<span class="chev"><?= ui_icon('chevron-down', 18) ?></span></button>
                <div class="faq-a"><div class="faq-a-inner">You can reach us on <a href="<?= $contact_wa ?>" target="_blank" rel="noopener"><strong>WhatsApp</strong></a>, or visit the Contact section below.</div></div>
            </div>

        </div>
    </div>
</section>

<!-- ============ CTA + CONTACT ============ -->
<section class="section" id="contact">
    <div class="container">
        <div class="cta-band">
            <h2>Begin Your Qur'an Journey Today</h2>
            <p>Join Lisanun Mubeen Academy and make the Qur'an your life companion â€” starting at just â‚¦3,000 per term.</p>
            <div class="cta-actions">
                <a class="btn btn-gold btn-lg" href="/register.php"><?= ui_icon('user', 18) ?> Register Now</a>
                <a class="btn btn-outline btn-lg" href="<?= $contact_wa ?>" target="_blank" rel="noopener"><?= ui_icon('phone', 18) ?> Chat on WhatsApp</a>
            </div>
        </div>

        <div class="contact-grid" style="margin-top:44px;">
            <div class="contact-card">
                <span class="ci" style="background:linear-gradient(135deg,#25d366,#128c7e);"><?= ui_icon('phone', 20) ?></span>
                <b>WhatsApp</b>
                <p>For applications, payments and enquiries.</p>
                <a href="<?= $contact_wa ?>" target="_blank" rel="noopener">Message us on WhatsApp â†’</a>
            </div>
            <div class="contact-card">
                <span class="ci" style="background:linear-gradient(135deg,var(--emerald-700),var(--emerald-500));"><?= ui_icon('mail', 20) ?></span>
                <b>Email</b>
                <p>For formal enquiries and documentation.</p>
                <a href="mailto:lisanunmubeenacademy@gmail.com">lisanunmubeenacademy@gmail.com</a>
            </div>
            <div class="contact-card">
                <span class="ci" style="background:linear-gradient(135deg,var(--gold),var(--gold-deep));"><?= ui_icon('clock', 20) ?></span>
                <b>Learning Hours</b>
                <p>Structured learning runs throughout the academic term, with live sessions arranged with your teacher.</p>
            </div>
            <div class="contact-card">
                <span class="ci" style="background:linear-gradient(135deg,#2563eb,#60a5fa);"><?= ui_icon('book-open', 20) ?></span>
                <b>Already a Student?</b>
                <p>Return to your dashboard to continue learning.</p>
                <a href="/auth/login.php">Login to your account â†’</a>
            </div>
        </div>
    </div>
</section>

<!-- ============ FOOTER ============ -->
<footer class="site-footer">
    <div class="container">
        <div class="footer-grid">
            <div class="footer-brand">
                <a class="brand" href="#home">
                    <span class="brand-mark"><?= ui_icon('book-open', 20) ?></span>
                    <span class="brand-name">Lisanun Mubeen<small>Academy</small></span>
                </a>
                <p>Come learn and let the Qur'an be your life companion. A structured online Qur'an learning academy with live recitation, progress tracking and certificates.</p>
            </div>
            <div class="footer-col">
                <b>Explore</b>
                <a href="#features">Features</a>
                <a href="#how">How It Works</a>
                <a href="#certificates">Certificates</a>
                <a href="#pricing">Pricing</a>
            </div>
            <div class="footer-col">
                <b>Get Started</b>
                <a href="/register.php">Register Now</a>
                <a href="/auth/login.php">Login</a>
                <a href="#faq">FAQ</a>
                <a href="#contact">Contact</a>
            </div>
            <div class="footer-col">
                <b>Contact</b>
                <a href="<?= $contact_wa ?>" target="_blank" rel="noopener">WhatsApp</a>
                <a href="mailto:lisanunmubeenacademy@gmail.com">Email</a>
            </div>
        </div>
        <div class="footer-bottom">Â© <?= date('Y') ?> Lisanun Mubeen Academy. All rights reserved.</div>
    </div>
</footer>

<script>
/* Mobile nav toggle */
(function () {
    var toggle = document.getElementById('navToggle');
    var menu   = document.getElementById('mobileMenu');
    if (toggle && menu) {
        toggle.addEventListener('click', function () {
            var open = menu.classList.toggle('open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        menu.addEventListener('click', function (e) {
            if (e.target.tagName === 'A') menu.classList.remove('open');
        });
    }
    /* FAQ accordion */
    var faqWrap = document.getElementById('faqWrap');
    if (faqWrap) {
        faqWrap.addEventListener('click', function (e) {
            var q = e.target.closest('.faq-q');
            if (!q) return;
            var item = q.parentElement;
            var answer = item.querySelector('.faq-a');
            var isOpen = item.classList.contains('open');
            /* close others */
            faqWrap.querySelectorAll('.faq-item.open').forEach(function (o) {
                o.classList.remove('open');
                o.querySelector('.faq-a').style.maxHeight = null;
            });
            if (!isOpen) {
                item.classList.add('open');
                answer.style.maxHeight = answer.scrollHeight + 'px';
            }
        });
    }
    /* Sticky nav shadow */
    var nav = document.getElementById('siteNav');
    window.addEventListener('scroll', function () {
        if (nav) nav.style.boxShadow = window.scrollY > 10 ? '0 6px 24px rgba(0,0,0,.25)' : 'none';
    });
})();
</script>

</body>
</html>
