<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$userId = (int) $_SESSION["user_id"];
$userName = $_SESSION["user_name"] ?? "Learner";
$userEmail = $_SESSION["user_email"] ?? "";

function e($value): string {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

$totalQuestions = 0;
$practicedCount = 0;
$averageScore = 0;
$bestScore = 0;
$topicCounts = [];
$recentActivity = [];

// Total questions
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total FROM questions WHERE user_id = ?"
);
if ($stmt) {
    $stmt->bind_param("i", $userId);
    if ($stmt->execute()) {
        $row = $stmt->get_result()->fetch_assoc();
        $totalQuestions = (int)($row["total"] ?? 0);
    }
    $stmt->close();
}

// Practice statistics
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS attempts,
            COALESCE(AVG(is_correct) * 100, 0) AS average_score,
            COALESCE(MAX(is_correct) * 100, 0) AS best_score
     FROM practice_history
     WHERE user_id = ?"
);
if ($stmt) {
    $stmt->bind_param("i", $userId);
    if ($stmt->execute()) {
        $row = $stmt->get_result()->fetch_assoc();
        $practicedCount = (int)($row["attempts"] ?? 0);
        $averageScore = (int)round((float)($row["average_score"] ?? 0));
        $bestScore = (int)round((float)($row["best_score"] ?? 0));
    }
    $stmt->close();
}

// Questions by topic
$stmt = $conn->prepare(
    "SELECT COALESCE(NULLIF(topic, ''), 'General') AS topic_name,
            COUNT(*) AS total
     FROM questions
     WHERE user_id = ?
     GROUP BY COALESCE(NULLIF(topic, ''), 'General')
     ORDER BY total DESC
     LIMIT 6"
);
if ($stmt) {
    $stmt->bind_param("i", $userId);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $topicCounts[] = $row;
        }
    }
    $stmt->close();
}

// Recent activity
$stmt = $conn->prepare(
    "SELECT ph.is_correct, ph.attempted_at, q.question, q.topic
     FROM practice_history ph
     LEFT JOIN questions q ON q.id = ph.question_id
     WHERE ph.user_id = ?
     ORDER BY ph.attempted_at DESC
     LIMIT 4"
);
if ($stmt) {
    $stmt->bind_param("i", $userId);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $recentActivity[] = $row;
        }
    }
    $stmt->close();
}

$greeting = "Hi";

$topicIcons = [
    "DSA" => "⌘",
    "Development" => "💻",
    "OOP" => "◇",
    "DBMS" => "▤",
    "Operating System" => "⚙",
    "Others" => "•••",
    "General" => "✦"
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard | QuestionBox</title>

<style>
:root {
    --bg: #050b19;
    --panel: #091326;
    --card: #0b172c;
    --border: #20304d;
    --muted: #9baecc;
    --white: #f6f8ff;
    --pink: #ff3296;
    --orange: #ff963c;
    --green: #00d6a0;
    --purple: #a855f7;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    min-height: 100vh;
    background: var(--bg);
    color: var(--white);
    font-family: Arial, Helvetica, sans-serif;
}

a { color: inherit; text-decoration: none; }

.topbar {
    min-height: 78px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    padding: 14px 28px;
    background: #071022;
    border-bottom: 1px solid #182642;
}

.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 27px;
    font-weight: 900;
    letter-spacing: -.8px;
    background: linear-gradient(90deg, var(--pink), var(--orange));
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
}

.brand-icon {
    width: 39px;
    height: 39px;
    display: grid;
    place-items: center;
    border-radius: 12px;
    background: linear-gradient(135deg, var(--pink), var(--orange));
    color: white;
    font-size: 24px;
    -webkit-text-fill-color: white;
}

.user-area {
    display: flex;
    align-items: center;
    gap: 13px;
    font-size: 14px;
}

.avatar {
    width: 42px;
    height: 42px;
    flex-shrink: 0;
    border-radius: 50%;
    display: grid;
    place-items: center;
    background: linear-gradient(135deg, #a855f7, var(--pink));
    font-size: 20px;
    font-weight: bold;
}

.user-email {
    color: var(--muted);
    font-size: 12px;
    margin-top: 4px;
}

.layout {
    display: grid;
    grid-template-columns: 235px minmax(0, 1fr);
    min-height: calc(100vh - 78px);
}

.sidebar {
    padding: 25px 16px;
    border-right: 1px solid #182642;
    display: flex;
    flex-direction: column;
    gap: 9px;
}

.nav-link {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 15px 16px;
    border-radius: 13px;
    color: #c4d0e8;
    font-size: 14px;
    transition: .2s;
}

.nav-icon {
    width: 25px;
    text-align: center;
    font-size: 20px;
}

.nav-link:hover,
.nav-link.active {
    background: linear-gradient(100deg, var(--pink), var(--orange));
    color: white;
}

.sidebar-note {
    margin-top: auto;
    padding: 20px;
    border: 1px solid var(--border);
    border-radius: 17px;
    background: linear-gradient(145deg, #0b1429, #101027);
    color: var(--muted);
    line-height: 1.7;
    font-size: 13px;
}

.sidebar-note strong {
    display: block;
    margin-bottom: 8px;
    color: #ff78b5;
    font-size: 17px;
}

.main {
    min-width: 0;
    padding: 25px;
}

.content-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 340px;
    gap: 22px;
}

.primary-column, .right-column { min-width: 0; }

.welcome {
    min-height: 190px;
    padding: 30px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    border-radius: 19px;
    background:
        radial-gradient(ellipse at 90% 10%, rgba(255,90,125,.18), transparent 45%),
        linear-gradient(120deg, #10162f, #15102b);
    border: 1px solid #282142;
    margin-bottom: 22px;
}

.welcome h1 {
    margin: 0 0 10px;
    font-size: clamp(25px, 2.5vw, 37px);
    line-height: 1.25;
}

.gradient-text {
    display: block;
    margin-top: 5px;
    background: linear-gradient(90deg, var(--pink), var(--orange));
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
}

.welcome p {
    margin: 13px 0 0;
    color: #b7c5e0;
    line-height: 1.7;
}

.welcome-art {
    min-width: 90px;
    text-align: center;
    font-size: 60px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 13px;
    margin-bottom: 22px;
}

.stat-card {
    min-width: 0;
    padding: 17px 14px;
    border: 1px solid var(--border);
    border-radius: 17px;
    background: linear-gradient(145deg, #0d1830, #091224);
}

.stat-top {
    display: flex;
    align-items: center;
    gap: 9px;
    color: #c7d3e9;
    font-size: 12px;
    line-height: 1.4;
}

.stat-icon {
    width: 38px;
    height: 38px;
    flex: 0 0 38px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    font-size: 20px;
}

.pink { background: #e92d88; }
.green { background: #00bd91; }
.yellow { background: #c58b00; }
.purple { background: #963cf0; }

.stat-number {
    margin: 13px 0 4px;
    font-size: 28px;
    font-weight: 800;
}

.stat-foot {
    color: var(--muted);
    font-size: 11px;
    line-height: 1.5;
}

.panel {
    margin-bottom: 22px;
    padding: 21px;
    border: 1px solid var(--border);
    border-radius: 18px;
    background: linear-gradient(145deg, #091326, #071020);
}

.panel-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 18px;
}

.panel-heading h2 {
    margin: 0;
    font-size: 19px;
}

.panel-heading p {
    margin: 7px 0 0;
    color: var(--muted);
    font-size: 13px;
    line-height: 1.5;
}

.view-all {
    color: #00d9d0;
    font-size: 12px;
    white-space: nowrap;
}

.topics-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
}

.topic-card {
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 13px;
    border: 1px solid #263858;
    border-radius: 15px;
    background: #0b172c;
    transition: transform .2s, border-color .2s;
}

.topic-card:hover {
    transform: translateY(-2px);
    border-color: var(--pink);
}

.topic-icon {
    width: 40px;
    height: 40px;
    flex: 0 0 40px;
    display: grid;
    place-items: center;
    border-radius: 13px;
    background: #292052;
    color: #ff78b5;
    font-size: 21px;
}

.topic-name {
    display: block;
    font-weight: 700;
    font-size: 12px;
    overflow-wrap: anywhere;
}

.topic-count {
    display: block;
    margin-top: 7px;
    color: var(--muted);
    font-size: 11px;
}

.empty-state {
    color: var(--muted);
    padding: 12px 0;
    font-size: 13px;
    line-height: 1.7;
}

.motivation {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 19px;
    border: 1px solid #302444;
    border-radius: 17px;
    background: linear-gradient(100deg, #151025, #0b1529);
}

.trophy { font-size: 32px; }

.motivation-text {
    font-weight: 800;
    line-height: 1.7;
}

.pink-text { color: var(--pink); }
.orange-text { color: var(--orange); }


/* Quick actions */
.quick-action {
    display: flex;
    align-items: center;
    gap: 11px;
    width: 100%;
    padding: 12px;
    margin-top: 10px;
    border: 1px solid #302b3e;
    border-radius: 15px;
    background: #11111b;
    color: #fff;
    text-align: left;
    transition: transform .2s, border-color .2s;
}
.quick-action:hover { transform: translateY(-2px); border-color: var(--pink); }
.quick-action.featured { background: linear-gradient(105deg, var(--pink), var(--orange)); border-color: transparent; }
.quick-action-icon {
    width: 38px; height: 38px; flex: 0 0 38px;
    display: grid; place-items: center; border-radius: 50%;
    background: rgba(255,255,255,.12); font-size: 20px;
}
.quick-action-copy { min-width: 0; }
.quick-action-title { display: block; font-size: 12px; font-weight: 700; }
.quick-action-description { display: block; margin-top: 5px; color: #c5c8e5; font-size: 10px; line-height: 1.5; }
.quick-action.featured .quick-action-description { color: #fff2f7; }
.custom-test-action { min-height: 92px; padding: 16px; }
.custom-test-logo {
    width: 48px;
    height: 48px;
    flex-basis: 48px;
    border-radius: 14px;
    background: rgba(0, 0, 0, .18);
    font-size: 27px;
    box-shadow: inset 0 0 0 1px rgba(255,255,255,.2);
}
.quick-arrow { margin-left: auto; font-size: 22px; }

/* Recent activity */
.activity-item {
    display: flex;
    gap: 12px;
    padding: 14px 0;
    border-bottom: 1px solid #1b2941;
}

.activity-item:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.activity-dot {
    width: 10px;
    height: 10px;
    flex: 0 0 10px;
    margin-top: 5px;
    border-radius: 50%;
}

.activity-title {
    font-size: 13px;
    line-height: 1.5;
    overflow-wrap: anywhere;
}

.activity-sub {
    margin-top: 5px;
    color: var(--muted);
    font-size: 11px;
    line-height: 1.5;
}

@media (max-width: 1250px) {
    .content-grid { grid-template-columns: minmax(0, 1fr); }
    .right-column {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 20px;
        align-items: start;
    }
    .right-column .panel { margin-bottom: 0; }
    .stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 850px) {
    .layout { grid-template-columns: 1fr; }
    .sidebar {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        padding: 12px;
        border-right: none;
        border-bottom: 1px solid #182642;
    }
    .nav-link {
        justify-content: center;
        gap: 7px;
        padding: 12px 6px;
        font-size: 12px;
    }
    .nav-icon { font-size: 17px; width: auto; }
    .sidebar-note { display: none; }
    .main { padding: 18px; }
}

@media (max-width: 560px) {
    .topbar { padding: 12px 15px; min-height: 68px; }
    .brand { font-size: 21px; }
    .brand-icon { width: 33px; height: 33px; }
    .user-area { gap: 8px; }
    .user-email { display: none; }
    .avatar { width: 36px; height: 36px; }
    .sidebar { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .main { padding: 13px; }
    .welcome { padding: 23px 20px; min-height: 0; }
    .welcome-art { display: none; }
    .welcome h1 { font-size: 26px; }
    .stats-grid { gap: 10px; }
    .stat-card { padding: 14px 11px; }
    .stat-top { align-items: flex-start; flex-direction: column; }
    .stat-icon { width: 35px; height: 35px; flex-basis: 35px; }
    .stat-number { font-size: 25px; }
    .topics-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .topic-card { padding: 10px 8px; gap: 8px; }
    .topic-icon { width: 34px; height: 34px; flex-basis: 34px; }
    .right-column { grid-template-columns: 1fr; }
    .panel { padding: 16px; }
    .motivation { padding: 15px; }
}
</style>
</head>

<body>

<header class="topbar">
    <a href="dashboard.php" class="brand">
        <span class="brand-icon">✦</span>
        QuestionBox
    </a>

    <div class="user-area">
        <div class="avatar">
            <?= e(strtoupper(substr($userName, 0, 1))) ?>
        </div>
        <div>
            <div><?= e($userName) ?></div>
            <div class="user-email"><?= e($userEmail) ?></div>
        </div>
    </div>
</header>

<div class="layout">
    <aside class="sidebar">
        <a class="nav-link active" href="dashboard.php">
            <span class="nav-icon">⌂</span> Dashboard
        </a>
        <a class="nav-link" href="library.php">
            <span class="nav-icon">▤</span> My Library
        </a>
        <a class="nav-link" href="practice.php">
            <span class="nav-icon">▷</span> Practice
        </a>
        <a class="nav-link" href="history.php">
            <span class="nav-icon">◷</span> History
        </a>
        <a class="nav-link" href="create_question.php">
            <span class="nav-icon">＋</span> Add Question
        </a>
        <a class="nav-link" href="logout.php">
            <span class="nav-icon">⇥</span> Logout
        </a>

        <div class="sidebar-note">
            <strong>✦ Keep going!</strong>
            Small steps every day lead to big results. Your progress starts with one question.
        </div>
    </aside>

    <main class="main">
        <div class="content-grid">

            <section class="primary-column">
                <div class="welcome">
                    <div>
                        <h1>
                            <?= e($greeting) ?>,
                            <span class="gradient-text"><?= e($userName) ?>!</span>
                        </h1>
                        <p>Your learning journey continues. Let's make today count!</p>
                    </div>
                    <div class="welcome-art" aria-hidden="true">📚</div>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-top">
                            <span class="stat-icon pink">▤</span>
                            Total Questions
                        </div>
                        <div class="stat-number"><?= $totalQuestions ?></div>
                        <div class="stat-foot">In your library</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-top">
                            <span class="stat-icon green">▷</span>
                            Questions Practiced
                        </div>
                        <div class="stat-number"><?= $practicedCount ?></div>
                        <div class="stat-foot">Saved answer attempts</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-top">
                            <span class="stat-icon yellow">◷</span>
                            Accuracy
                        </div>
                        <div class="stat-number"><?= $averageScore ?>%</div>
                        <div class="stat-foot">Correct answers overall</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-top">
                            <span class="stat-icon purple">🏆</span>
                            Correct Attempts
                        </div>
                        <div class="stat-number">
                            <?= (int)round($practicedCount * $averageScore / 100) ?>
                        </div>
                        <div class="stat-foot">Estimated from saved accuracy</div>
                    </div>
                </div>

                <section class="panel" id="practice-topics">
                    <div class="panel-heading">
                        <div>
                            <h2>🎯 Practice by Topic</h2>
                            <p>Choose a topic from your saved question collection.</p>
                        </div>
                        <a class="view-all" href="library.php">My Library →</a>
                    </div>

                    <div class="topics-grid">
                        <?php if (count($topicCounts) > 0): ?>
                            <?php foreach ($topicCounts as $topic): ?>
                                <a class="topic-card" href="practice.php">
                                    <span class="topic-icon">
                                        <?= e($topicIcons[$topic["topic_name"]] ?? "✦") ?>
                                    </span>
                                    <span>
                                        <span class="topic-name">
                                            <?= e($topic["topic_name"]) ?>
                                        </span>
                                        <span class="topic-count">
                                            <?= (int)$topic["total"] ?>
                                            <?= (int)$topic["total"] === 1 ? "question" : "questions" ?>
                                        </span>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                Your topics will appear here when you add questions.
                                <br><br>
                                <a class="view-all" href="create_question.php">
                                    + Create your first question
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <div class="motivation">
                    <div class="trophy">🏆</div>
                    <div class="motivation-text">
                        <span class="pink-text">Better Questions</span>
                        →
                        <span class="orange-text">Better Learning</span>
                        <br>
                        Small progress is still progress!
                    </div>
                </div>
            </section>

            <aside class="right-column">

                <section class="panel custom-test-panel">
                    <div class="panel-heading">
                        <div>
                            <h2>🧩 Create Custom Test</h2>
                            <p>Build a test using the saved questions you choose.</p>
                        </div>
                    </div>

                    <a class="quick-action featured custom-test-action" href="custom_test.php">
                        <span class="quick-action-icon custom-test-logo" aria-hidden="true">✦</span>
                        <span class="quick-action-copy">
                            <span class="quick-action-title">Create Custom Test</span>
                            <span class="quick-action-description">Pick your questions, set the timer, and test yourself.</span>
                        </span>
                        <span class="quick-arrow">›</span>
                    </a>
                </section>

                <section class="panel">
                    <div class="panel-heading">
                        <h2>◷ Recent Activity</h2>
                        <a class="view-all" href="history.php">View All →</a>
                    </div>

                    <?php if (count($recentActivity) > 0): ?>
                        <?php foreach ($recentActivity as $activity): ?>
                            <div class="activity-item">
                                <span
                                    class="activity-dot"
                                    style="background: <?= (int)$activity["is_correct"] === 1 ? "#00d6a0" : "#ff3296" ?>;">
                                </span>
                                <div>
                                    <div class="activity-title">
                                        <?= (int)$activity["is_correct"] === 1
                                            ? "Correct answer"
                                            : "Incorrect answer" ?>
                                    </div>
                                    <div class="activity-sub">
                                        <?= e($activity["topic"] ?: "General") ?>
                                        <?php if (!empty($activity["attempted_at"])): ?>
                                            · <?= e(date(
                                                "d M, H:i",
                                                strtotime($activity["attempted_at"])
                                            )) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            Your recent practice activity will appear here after you answer questions.
                        </div>
                    <?php endif; ?>
                </section>

            </aside>
        </div>
    </main>
</div>



</body>
</html>
