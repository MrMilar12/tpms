<?php
$pageTitle = 'Updates & Version';
require_once __DIR__ . '/includes/header.php';

$appVersion = defined('APP_VERSION') ? (string)APP_VERSION : 'v1.9.1';

$updateHighlights = [
    'Added Team Chat with direct messaging and group conversations so users can message specific people or create chat groups.',
    'Redesigned the Teachers page with a cleaner toolbar, simpler search and filter layout, and better spacing for the action buttons.',
    'Upgraded the System Activity Logs page with summary reports, narrative activity notes, filters, and chart visuals.',
    'Refined the login page with cleaner loading behavior, improved logo animation, and a more polished form design.',
    'Enhanced the Updates page and report-style screens with improved aesthetics, cards, and structured content blocks.',
    'Fixed onboarding workflow - Users are now redirected directly to the 4-step setup wizard (first-login-setup) instead of a separate welcome page.',
    'Implemented district-level filtering for schools - PSDS and SDC accounts now see only schools and data from their assigned districts.',
    'Added selected district display - The user\'s assigned district now appears in both the sidebar and top navigation bar for quick reference.',
    'New Appearance settings with customizable themes, readability options, and animated background controls.',
    'Added Frosted Glass UI across the system with improved transparency, blur effects, and readability safeguards.',
    'Introduced Animated Color Flow, floating glass orbs, and immersive background effects for a modern dashboard experience.',
    'Enhanced profile and login pages with improved layout, visual consistency, and smoother user interactions.',
    'Added support for profile display photos (avatars) to personalize user accounts.',
    'Improved overall system styling consistency across all pages and interface components.',
    'Strengthened system security with enhanced authentication, data protection, and session management.',
    'Introduced Teacher Planning Parameters, including teacher workload, class assignments, student-to-teacher ratios, and staffing requirements.',
    'Added Multi-Window App View, allowing users to open and manage multiple system modules simultaneously for improved multitasking.',
    'Introduced an Age Retirement Watch feature to monitor employees approaching mandatory retirement age.',
    'Improved system count accuracy for schools, personnel, teachers, students, and planning statistics.',
    'Enhanced dashboard calculations to provide more accurate planning, reporting, and decision-making data.',
    'Optimized database queries and backend processes for faster loading and improved performance.',
    'Improved navigation responsiveness and overall user experience throughout the system.',
    'Added smoother animations and transitions to create a more fluid and polished interface.',
    'Refined tables, forms, cards, dialogs, and navigation components for a cleaner and more consistent design.',
    'Improved accessibility and text contrast to ensure readability across all supported themes.',
    'Fixed various UI inconsistencies, calculation errors, and minor bugs to improve system stability and reliability.',
];


$usageGuide = [
    [
        'title' => '1. Start With Dashboard',
        'items' => [
            'Open the Dashboard to view total teachers, schools, districts, charts, and school-type counts.',
            'Use the dashboard cards and charts to quickly check staffing, coverage, and distribution data.'
        ]
    ],
    [
        'title' => '2. Manage Teachers',
        'items' => [
            'Go to Teachers to add, edit, search, filter, and view teacher records.',
            'Open a teacher profile to review details such as position, school, district, and specialization.'
        ]
    ],
    [
        'title' => '3. Manage Schools',
        'items' => [
            'Go to Schools to add or update school details, school type, district, learner count, and school head.',
            'Use school filters to check Elementary, JHS, SHS, ALS, Untagged, or schools without teachers.'
        ]
    ],
    [
        'title' => '4. Use Team Chat',
        'items' => [
            'Open Team Chat to send a message directly to a specific user or start a group conversation.',
            'Use chat notifications to stay updated when someone sends you a new message.'
        ]
    ],
    [
        'title' => '5. Use Reports And Exports',
        'items' => [
            'Open Reports for printable summaries and export-ready data views.',
            'Use export actions when you need CSV or Excel copies of filtered records.'
        ]
    ],
    [
        'title' => '6. Review Activity Logs',
        'items' => [
            'Open Logs to see filtered activity summaries, narrative reports, charts, and detailed system events.',
            'Use the date and user filters to review activity for a specific day or account.'
        ]
    ],
    [
        'title' => '7. Customize The System',
        'items' => [
            'Open Appearance to change theme, layout density, background color, readability strength, accent color, and teacher card colors.',
            'Your appearance settings are saved and applied across the whole system.'
        ]
    ],
    [
        'title' => '8. Use Tala AI',
        'items' => [
            'Open Tala AI and type requests such as "show school type counts", "list schools in district west", or "open reports".',
            'The chatbot can help with teacher queries, school summaries, navigation, and settings guidance.'
        ]
    ],
];

$changelog = isset($changelog) && is_array($changelog) ? $changelog : [];


?>

<style>
    .updates-shell {
        display: grid;
        gap: 18px;
        max-width: none;
        width: min(100%, 1480px);
        margin: 0 auto;
    }
    .updates-hero {
        border-radius: 20px;
        border: 1px solid var(--glass-border);
        background: var(--glass-bg);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        padding: 22px;
        box-shadow: 0 14px 30px rgba(2, 6, 23, .14);
    }
    .updates-title {
        margin: 0;
        color: var(--text);
        font-size: 1.55rem;
        letter-spacing: .01em;
    }
    .updates-sub {
        margin: 8px 0 0;
        color: var(--text-muted);
    }
    .version-pill {
        margin-top: 14px;
        display: inline-flex;
        align-items: baseline;
        gap: 10px;
        border-radius: 999px;
        border: 1px solid var(--glass-border);
        background: rgba(99, 102, 241, .12);
        padding: 8px 14px;
    }
    .version-pill span {
        color: var(--text-muted);
        font-size: .76rem;
        text-transform: uppercase;
        letter-spacing: .08em;
        font-weight: 700;
    }
    .version-pill strong {
        color: var(--text);
        font-size: 1rem;
        letter-spacing: .02em;
    }
    .updates-card {
        border-radius: 18px;
        border: 1px solid var(--glass-border);
        background: var(--glass-bg);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        padding: 18px 18px 16px;
        box-shadow: 0 10px 24px rgba(2, 6, 23, .12);
        transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
    }
    .updates-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 16px 30px rgba(2, 6, 23, .16);
        border-color: rgba(99, 102, 241, .28);
    }
    .updates-date {
        margin: 0 0 10px;
        color: var(--text);
        font-size: 1rem;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .updates-date i {
        color: var(--user-accent, #6366f1);
    }
    .updates-list {
        margin: 0;
        padding-left: 18px;
        color: var(--text-muted);
        line-height: 1.6;
    }
    .updates-list li + li {
        margin-top: 7px;
    }
    .guide-grid {
        display: grid;
        gap: 14px;
    }
    .updates-two-col {
        display: grid;
        grid-template-columns: minmax(0, 1.25fr) minmax(360px, .75fr);
        gap: 16px;
        align-items: start;
    }
    .updates-card.updates-spotlight {
        min-height: calc(100vh - 220px);
        display: grid;
        grid-template-rows: auto auto 1fr;
        padding: 22px 24px;
    }
    .updates-card.updates-spotlight .updates-list {
        columns: 2;
        column-gap: 30px;
        margin-top: 14px !important;
        align-content: start;
    }
    .updates-card.updates-spotlight .updates-list li {
        break-inside: avoid;
    }
    .updates-card-title {
        margin: 0;
        color: var(--text);
        font-size: 1.08rem;
        letter-spacing: .01em;
    }
    .updates-card-sub {
        margin: 6px 0 0;
        color: var(--text-muted);
        font-size: .9rem;
    }
    .guide-title {
        margin: 0 0 8px;
        color: var(--text);
        font-size: 1rem;
    }
    .section-label {
        margin: 0;
        color: var(--text-muted);
        font-size: .84rem;
        text-transform: uppercase;
        letter-spacing: .08em;
        font-weight: 700;
    }
    @media (max-width: 1200px) {
        .updates-card.updates-spotlight {
            min-height: auto;
        }
        .updates-card.updates-spotlight .updates-list {
            columns: 1;
        }
    }
    @media (max-width: 980px) {
        .updates-two-col {
            grid-template-columns: 1fr;
        }
    }
</style>

<main class="page-content">
    <section class="updates-shell">
        <div class="updates-hero">
            <h1 class="updates-title"><i class="fas fa-code-branch"></i> Updates & Version</h1>
            <p class="updates-sub">Latest technical changes plus a quick guide for using TPMS.</p>
            <div class="version-pill">
                <span>Current Version</span>
                <strong><?= clean($appVersion) ?></strong>
            </div>
        </div>

        <div class="updates-two-col">
            <article class="updates-card updates-spotlight">
                <p class="section-label">What Is New</p>
                <h2 class="updates-card-title">Recent Improvements</h2>
                <ul class="updates-list" style="margin-top:12px;">
                    <?php foreach ($updateHighlights as $item): ?>
                        <li><?= clean($item) ?></li>
                    <?php endforeach; ?>
                </ul>
            </article>

            <article class="updates-card">
                <p class="section-label">How To Use The System</p>
                <h2 class="updates-card-title">Quick User Guide</h2>
                <p class="updates-card-sub">Core workflows for daily navigation and data management.</p>
                <div class="guide-grid" style="margin-top:12px;">
                    <?php foreach ($usageGuide as $guide): ?>
                        <div>
                            <h3 class="guide-title"><?= clean($guide['title']) ?></h3>
                            <ul class="updates-list">
                                <?php foreach ($guide['items'] as $item): ?>
                                    <li><?= clean($item) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>
        </div>

        <?php if (!empty($changelog)): ?>
            <?php foreach ($changelog as $entry): ?>
                <article class="updates-card">
                    <h2 class="updates-date">
                        <i class="fas fa-calendar-day"></i>
                        <?= clean(date('F j, Y', strtotime((string)$entry['date']))) ?>
                    </h2>
                    <ul class="updates-list">
                        <?php foreach ($entry['items'] as $item): ?>
                            <li><?= clean($item) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
