
<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$userId = (int)$_SESSION["user_id"];

/* Escape output safely. */
function h($value): string {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

/* Load one question belonging to the logged-in user. */
function loadQuestion(mysqli $conn, int $id, int $userId): ?array {
    $stmt = $conn->prepare(
        "SELECT id, question, option_a, option_b, option_c, option_d,
                correct_answer, explanation, topic
         FROM questions
         WHERE id = ? AND user_id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("ii", $id, $userId);
    $stmt->execute();
    $question = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$question) {
        return null;
    }

    $question["_options"] = [];

    // Use dynamic options when available.
    $stmt = $conn->prepare(
        "SELECT option_label, option_text
         FROM question_options
         WHERE question_id = ?
         ORDER BY id ASC"
    );

    if ($stmt) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($option = $result->fetch_assoc()) {
            $question["_options"][] = [
                "label" => strtoupper(trim((string)$option["option_label"])),
                "text" => $option["option_text"]
            ];
        }

        $stmt->close();
    }

    // Fallback for older questions saved using option_a to option_d.
    if (empty($question["_options"])) {
        foreach (["a", "b", "c", "d"] as $letter) {
            $value = trim((string)($question["option_" . $letter] ?? ""));

            if ($value !== "") {
                $question["_options"][] = [
                    "label" => strtoupper($letter),
                    "text" => $value
                ];
            }
        }
    }

    return $question;
}

/* Save an answered question when the current history schema allows it. */
function saveAttempt(
    mysqli $conn,
    int $userId,
    int $questionId,
    string $answer,
    int $correct
): bool {
    $result = $conn->query("SHOW COLUMNS FROM practice_history");

    if (!$result) {
        return false;
    }

    $columns = [];

    while ($column = $result->fetch_assoc()) {
        $columns[] = $column["Field"];
    }

    if (
        in_array("user_id", $columns, true) &&
        in_array("question_id", $columns, true) &&
        in_array("selected_answer", $columns, true) &&
        in_array("is_correct", $columns, true)
    ) {
        $stmt = $conn->prepare(
            "INSERT INTO practice_history
             (user_id, question_id, selected_answer, is_correct)
             VALUES (?, ?, ?, ?)"
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("iisi", $userId, $questionId, $answer, $correct);
    } elseif (
        in_array("user_id", $columns, true) &&
        in_array("question_id", $columns, true) &&
        in_array("is_correct", $columns, true)
    ) {
        $stmt = $conn->prepare(
            "INSERT INTO practice_history
             (user_id, question_id, is_correct)
             VALUES (?, ?, ?)"
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("iii", $userId, $questionId, $correct);
    } else {
        return false;
    }

    $saved = $stmt->execute();
    $stmt->close();

    return $saved;
}

/* Finish the test and calculate the final score. */
function finishCustomTest(mysqli $conn, int $userId, bool $timedOut): void {
    $test = $_SESSION["custom_test"] ?? null;

    if (!$test || empty($test["questions"])) {
        unset(
            $_SESSION["custom_test"],
            $_SESSION["custom_test_answers"],
            $_SESSION["custom_test_status"],
            $_SESSION["custom_test_review"],
            $_SESSION["custom_test_index"]
        );
        return;
    }

    $answers = $_SESSION["custom_test_answers"] ?? [];
    $resultQuestions = [];
    $score = 0;
    $historySaved = 0;

    foreach ($test["questions"] as $question) {
        $questionId = (int)$question["id"];
        $correctAnswer = strtoupper(
            trim((string)$question["correct_answer"])
        );
        $selectedAnswer = strtoupper(
            trim((string)($answers[$questionId] ?? ""))
        );

        $validLabels = array_column($question["_options"], "label");

        if (!in_array($selectedAnswer, $validLabels, true)) {
            $selectedAnswer = "";
        }

        $isCorrect = (
            $selectedAnswer !== "" &&
            $selectedAnswer === $correctAnswer
        );

        if ($isCorrect) {
            $score++;
        }

        if ($selectedAnswer !== "") {
            if (saveAttempt(
                $conn,
                $userId,
                $questionId,
                $selectedAnswer,
                $isCorrect ? 1 : 0
            )) {
                $historySaved++;
            }
        }

        $question["selected_answer"] = $selectedAnswer;
        $question["is_correct"] = $isCorrect;
        $resultQuestions[] = $question;
    }

    $_SESSION["custom_test_results"] = [
        "questions" => $resultQuestions,
        "score" => $score,
        "total" => count($resultQuestions),
        "timed_out" => $timedOut,
        "history_saved" => $historySaved
    ];

    unset(
        $_SESSION["custom_test"],
        $_SESSION["custom_test_answers"],
        $_SESSION["custom_test_status"],
        $_SESSION["custom_test_review"],
        $_SESSION["custom_test_index"]
    );
}

$message = "";
$mode = $_GET["mode"] ?? "select";

/* Start a test using the selected saved questions. */
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST["action"] ?? "") === "start"
) {
    $ids = array_values(array_unique(array_filter(
        array_map("intval", $_POST["question_ids"] ?? []),
        fn($id) => $id > 0
    )));

    $minutes = (int)($_POST["duration_minutes"] ?? 15);
    $minutes = max(1, min(180, $minutes));

    if (empty($ids)) {
        $message = "Please select at least one question.";
        $mode = "select";
    } else {
        $selectedQuestions = [];

        foreach ($ids as $id) {
            $question = loadQuestion($conn, $id, $userId);

            if ($question && count($question["_options"]) >= 2) {
                $selectedQuestions[] = $question;
            }
        }

        if (empty($selectedQuestions)) {
            $message = "No valid saved questions were selected.";
            $mode = "select";
        } else {
            $_SESSION["custom_test"] = [
                "questions" => $selectedQuestions,
                "duration" => $minutes * 60,
                "started_at" => time()
            ];

            $_SESSION["custom_test_answers"] = [];
            $_SESSION["custom_test_status"] = [];
            $_SESSION["custom_test_review"] = [];
            $_SESSION["custom_test_index"] = 0;

            unset($_SESSION["custom_test_results"]);

            header("Location: custom_test.php?mode=run");
            exit;
        }
    }
}

/* Handle Previous, Next, Skip, Review, Navigator and Submit actions. */
$navigationActions = [
    "previous", "next", "skip", "review", "goto", "submit"
];

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    in_array($_POST["action"] ?? "", $navigationActions, true)
) {
    $test = $_SESSION["custom_test"] ?? null;

    if (!$test || empty($test["questions"])) {
        header("Location: custom_test.php");
        exit;
    }

    $elapsed = time() - (int)$test["started_at"];
    $serverExpired = $elapsed >= (int)$test["duration"];
    $clientTimedOut = isset($_POST["timed_out"]);
    $action = (string)$_POST["action"];

    // The server enforces the overall timer.
    if ($serverExpired) {
        finishCustomTest($conn, $userId, true);
        header("Location: custom_test.php?mode=result");
        exit;
    }

    $testQuestions = $test["questions"];
    $total = count($testQuestions);

    $index = (int)($_POST["current_index"] ?? 0);
    $index = max(0, min($total - 1, $index));

    $question = $testQuestions[$index];
    $questionId = (int)$question["id"];

    $answers = $_SESSION["custom_test_answers"] ?? [];
    $statuses = $_SESSION["custom_test_status"] ?? [];
    $review = $_SESSION["custom_test_review"] ?? [];

    // Preserve the selected answer before moving away from this question.
    $postedAnswer = strtoupper(
        trim((string)($_POST["current_answer"] ?? ""))
    );
    $validLabels = array_column($question["_options"], "label");

    if (
        $postedAnswer !== "" &&
        in_array($postedAnswer, $validLabels, true)
    ) {
        $answers[$questionId] = $postedAnswer;
        $statuses[$questionId] = "answered";
    } elseif (!isset($statuses[$questionId])) {
        $statuses[$questionId] = "unanswered";
    }

    if ($action === "submit") {
        $_SESSION["custom_test_answers"] = $answers;
        $_SESSION["custom_test_status"] = $statuses;
        $_SESSION["custom_test_review"] = $review;

        finishCustomTest($conn, $userId, $clientTimedOut);
        header("Location: custom_test.php?mode=result");
        exit;
    }

    if ($action === "previous") {
        $index = max(0, $index - 1);
    } elseif ($action === "next") {
        $index = min($total - 1, $index + 1);
    } elseif ($action === "skip") {
        if (empty($answers[$questionId])) {
            $statuses[$questionId] = "skipped";
        }

        $index = min($total - 1, $index + 1);
    } elseif ($action === "review") {
        if (!empty($review[$questionId])) {
            unset($review[$questionId]);
        } else {
            $review[$questionId] = true;
        }
    } elseif ($action === "goto") {
        $target = (int)($_POST["goto_index"] ?? 0);
        $index = max(0, min($total - 1, $target));
    }

    $_SESSION["custom_test_answers"] = $answers;
    $_SESSION["custom_test_status"] = $statuses;
    $_SESSION["custom_test_review"] = $review;
    $_SESSION["custom_test_index"] = $index;

    header("Location: custom_test.php?mode=run");
    exit;
}

/* Read the current test and results. */
$test = $_SESSION["custom_test"] ?? null;
$results = $_SESSION["custom_test_results"] ?? null;

/* Catch an expired test even if the page is refreshed. */
if (
    $mode === "run" &&
    $test &&
    (time() - (int)$test["started_at"]) >= (int)$test["duration"]
) {
    finishCustomTest($conn, $userId, true);
    header("Location: custom_test.php?mode=result");
    exit;
}

/* Load the user's saved questions for the selection page. */
$questions = [];
$topics = [];

$stmt = $conn->prepare(
    "SELECT id, question, topic
     FROM questions
     WHERE user_id = ?
     ORDER BY id DESC"
);

if ($stmt) {
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $queryResult = $stmt->get_result();

    while ($row = $queryResult->fetch_assoc()) {
        $questions[] = $row;
        $topic = trim((string)($row["topic"] ?? "")) ?: "General";
        $topics[$topic] = true;
    }

    $stmt->close();
}

ksort($topics);

if ($mode === "run" && !$test) {
    $mode = "select";
}

if ($mode === "result" && !$results) {
    $mode = "select";
}

$testQuestions = $test["questions"] ?? [];
$totalTestQuestions = count($testQuestions);
$currentIndex = (int)($_SESSION["custom_test_index"] ?? 0);

if ($totalTestQuestions > 0) {
    $currentIndex = max(0, min($totalTestQuestions - 1, $currentIndex));
}

$currentQuestion = $testQuestions[$currentIndex] ?? null;
$currentQuestionId = (int)($currentQuestion["id"] ?? 0);

$answers = $_SESSION["custom_test_answers"] ?? [];
$statuses = $_SESSION["custom_test_status"] ?? [];
$review = $_SESSION["custom_test_review"] ?? [];

$currentAnswer = (string)($answers[$currentQuestionId] ?? "");
$currentStatus = (string)($statuses[$currentQuestionId] ?? "unanswered");
$isMarked = !empty($review[$currentQuestionId]);

$answeredCount = 0;
$skippedCount = 0;

foreach ($testQuestions as $q) {
    $qid = (int)$q["id"];

    if (!empty($answers[$qid])) {
        $answeredCount++;
    } elseif (($statuses[$qid] ?? "") === "skipped") {
        $skippedCount++;
    }
}

$reviewCount = count($review);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Custom Test | QuestionBox</title>

<style>
:root {
    --bg:#050505;
    --panel:#111111;
    --card:#191719;
    --border:#393039;
    --pink:#FF6FAE;
    --pink-dark:#D93683;
    --orange:#FFD0A8;
    --yellow:#FFF3A6;
    --text:#f7f4f6;
    --muted:#bdb4bc;
    --green:#91e5b0;
}
* { box-sizing:border-box; }
body {
    margin:0;
    padding:24px;
    background:
        radial-gradient(circle at 10% 5%, #25131f 0, transparent 28%),
        radial-gradient(circle at 95% 35%, #21170f 0, transparent 25%),
        var(--bg);
    color:var(--text);
    font-family:Arial,Helvetica,sans-serif;
}
.container { max-width:1050px; margin:auto; }
header {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:14px;
    flex-wrap:wrap;
    margin-bottom:24px;
}
h1 {
    margin:0;
    font-size:clamp(25px,4vw,36px);
    background:linear-gradient(90deg,var(--pink),var(--orange),var(--yellow));
    -webkit-background-clip:text;
    background-clip:text;
    color:transparent;
}
h2 { margin-top:0; }
a { color:var(--pink); text-decoration:none; }
a:hover { color:var(--yellow); }
.panel {
    background:rgba(17,17,17,.96);
    border:1px solid var(--border);
    border-radius:20px;
    padding:23px;
    margin-bottom:18px;
    box-shadow:0 12px 35px #0005;
}
.panel-title {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
}
input[type=text], input[type=number], select {
    width:100%;
    padding:12px;
    margin-top:7px;
    background:#080808;
    color:white;
    border:1px solid #51414c;
    border-radius:10px;
}
label { display:block; margin:10px 0; }
.question {
    padding:17px;
    margin:12px 0;
    background:var(--card);
    border:1px solid var(--border);
    border-radius:14px;
    overflow-wrap:anywhere;
}
.pick-question label {
    display:flex;
    align-items:flex-start;
    gap:11px;
    margin:0;
    cursor:pointer;
}
input[type=checkbox], input[type=radio] {
    accent-color:var(--pink);
    flex-shrink:0;
}
.pick-question input { margin-top:4px; }
button, .button-link {
    display:inline-block;
    padding:12px 16px;
    border:0;
    border-radius:11px;
    cursor:pointer;
    font-weight:bold;
    font-size:14px;
    margin:5px 5px 5px 0;
    text-align:center;
    text-decoration:none;
}
button:disabled {
    opacity:.4;
    cursor:not-allowed;
}
.primary {
    color:#171014;
    background:linear-gradient(100deg,var(--pink),var(--orange));
}
.primary:hover { filter:brightness(1.08); }
.secondary {
    color:var(--text);
    background:#292329;
    border:1px solid #51414c;
}
.review-button {
    color:#171014;
    background:var(--yellow);
}
.muted { color:var(--muted); line-height:1.6; }
.alert { color:var(--yellow); line-height:1.6; }
.flower { color:var(--pink); }
.timer {
    position:sticky;
    top:8px;
    z-index:5;
    padding:14px;
    border-radius:13px;
    background:#21131b;
    border:1px solid var(--pink);
    color:var(--yellow);
    font-weight:bold;
    text-align:center;
    box-shadow:0 5px 20px #0006;
}
.timer.danger {
    color:#fff;
    border-color:#ff5555;
    background:#361212;
}
.progress-track {
    height:8px;
    background:#30262e;
    border-radius:20px;
    overflow:hidden;
    margin:14px 0;
}
.progress-fill {
    height:100%;
    background:linear-gradient(90deg,var(--pink),var(--orange),var(--yellow));
    transition:width .2s;
}
.status-row {
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin:15px 0;
}
.status-pill {
    border:1px solid var(--border);
    background:#201c20;
    padding:7px 10px;
    border-radius:30px;
    font-size:12px;
    color:var(--muted);
}
.navigator {
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(43px,1fr));
    gap:8px;
    margin:15px 0;
}
.nav-item {
    width:100%;
    min-height:42px;
    margin:0;
    padding:8px;
    border:1px solid #51414c;
    color:white;
    background:#211e21;
}
.nav-item.current {
    outline:2px solid var(--yellow);
    outline-offset:1px;
}
.nav-item.answered {
    background:#17442b;
    border-color:var(--green);
}
.nav-item.skipped {
    background:#4b321b;
    border-color:var(--orange);
}
.nav-item.marked {
    box-shadow:inset 0 -4px 0 var(--pink);
}
.option {
    padding:13px;
    border:1px solid #393039;
    border-radius:11px;
    margin:9px 0;
    background:#111;
}
.option label {
    display:flex;
    gap:11px;
    align-items:flex-start;
    margin:0;
    cursor:pointer;
}
.option input { margin-top:4px; }
.option.correct { border-color:var(--green); }
.option.wrong { border-color:var(--pink); }
.question-meta {
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    align-items:center;
    margin:10px 0;
}
.tag {
    font-size:12px;
    border:1px solid #51414c;
    border-radius:20px;
    padding:6px 9px;
    color:var(--muted);
}
.tag.review {
    color:var(--pink);
    border-color:var(--pink);
}
.navigation-buttons {
    display:flex;
    flex-wrap:wrap;
    justify-content:space-between;
    gap:5px;
    margin-top:18px;
}
.navigation-buttons .left,
.navigation-buttons .right {
    display:flex;
    flex-wrap:wrap;
    gap:4px;
}
.score {
    font-size:clamp(28px,5vw,42px);
    color:var(--yellow);
    font-weight:bold;
}
[hidden] { display:none !important; }

@media(max-width:600px) {
    body { padding:12px; }
    .panel { padding:15px; }
    .navigation-buttons { display:block; }
    .navigation-buttons button { margin-bottom:7px; }
}
</style>
</head>

<body>
<div class="container">

<header>
    <h1><span class="flower">✿</span> QuestionBox Custom Test</h1>
    <nav>
        <a href="dashboard.php">Dashboard</a> ·
        <a href="library.php">My Library</a> ·
        <a href="history.php">History</a>
    </nav>
</header>

<?php if ($message !== ""): ?>
    <div class="panel alert"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($mode === "select"): ?>

<section class="panel">
    <h2>✿ 1. Choose your questions</h2>
    <p class="muted">
        Pick the saved questions you want to practise. Combine topics
        and choose your own total test duration.
    </p>

    <label for="search">Search questions</label>
    <input id="search" type="text" placeholder="Search by question text...">

    <label for="topicFilter">Filter by topic</label>
    <select id="topicFilter">
        <option value="">All topics</option>
        <?php foreach (array_keys($topics) as $topic): ?>
            <option value="<?= h(strtolower($topic)) ?>">
                <?= h($topic) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <button type="button" class="secondary" id="selectAll">
        Select All Visible
    </button>
    <button type="button" class="secondary" id="clearAll">
        Clear Selection
    </button>

    <p class="muted">
        Selected questions: <strong id="selectedCount">0</strong>
    </p>

    <form method="post" id="startForm">
        <input type="hidden" name="action" value="start">

        <?php if (!$questions): ?>
            <p class="alert">
                You have no saved questions yet. Add questions to My Library first.
            </p>
        <?php else: ?>
            <div id="questionList">
                <?php foreach ($questions as $q): ?>
                    <?php $topic = trim((string)($q["topic"] ?? "")) ?: "General"; ?>
                    <div class="question pick-question"
                         data-topic="<?= h(strtolower($topic)) ?>"
                         data-search="<?= h(strtolower($q["question"])) ?>">
                        <label>
                            <input type="checkbox"
                                   name="question_ids[]"
                                   value="<?= (int)$q["id"] ?>">
                            <span>
                                <strong><?= h($q["question"]) ?></strong><br>
                                <span class="muted">Topic: <?= h($topic) ?></span>
                            </span>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <h2>✿ 2. Set test duration</h2>
        <label for="duration">Duration in minutes (1–180)</label>
        <input type="number"
               id="duration"
               name="duration_minutes"
               min="1"
               max="180"
               value="15"
               required>

        <button class="primary" type="submit">Start Custom Test →</button>
    </form>
</section>

<script>
const searchBox = document.getElementById('search');
const topicFilter = document.getElementById('topicFilter');
const cards = [...document.querySelectorAll('.pick-question')];
const countBox = document.getElementById('selectedCount');

function filterQuestions() {
    const search = searchBox.value.toLowerCase().trim();
    const topic = topicFilter.value;

    cards.forEach(card => {
        const visible =
            card.dataset.search.includes(search) &&
            (!topic || card.dataset.topic === topic);

        card.hidden = !visible;
    });
}

function updateCount() {
    countBox.textContent =
        document.querySelectorAll('input[name="question_ids[]"]:checked').length;
}

searchBox.addEventListener('input', filterQuestions);
topicFilter.addEventListener('change', filterQuestions);

document.getElementById('selectAll').addEventListener('click', () => {
    cards.forEach(card => {
        if (!card.hidden) {
            card.querySelector('input').checked = true;
        }
    });
    updateCount();
});

document.getElementById('clearAll').addEventListener('click', () => {
    document.querySelectorAll('input[name="question_ids[]"]').forEach(box => {
        box.checked = false;
    });
    updateCount();
});

document.querySelectorAll('input[name="question_ids[]"]').forEach(box => {
    box.addEventListener('change', updateCount);
});

document.getElementById('startForm').addEventListener('submit', event => {
    if (!document.querySelector('input[name="question_ids[]"]:checked')) {
        event.preventDefault();
        alert('Please select at least one question.');
    }

    const duration = Number(document.getElementById('duration').value);

    if (!Number.isFinite(duration) || duration < 1 || duration > 180) {
        event.preventDefault();
        alert('Please enter a duration between 1 and 180 minutes.');
    }
});
</script>

<?php elseif ($mode === "run" && $test && $currentQuestion): ?>

<section class="panel">
    <div class="timer" id="timerBox">
        Time remaining: <span id="timer">--:--</span>
    </div>

    <div class="panel-title">
        <h2>Your Custom Test</h2>
        <span class="tag">
            Question <?= $currentIndex + 1 ?> of <?= $totalTestQuestions ?>
        </span>
    </div>

    <div class="progress-track">
        <div class="progress-fill"
             style="width:<?= $totalTestQuestions
                ? round(($answeredCount / $totalTestQuestions) * 100)
                : 0 ?>%">
        </div>
    </div>

    <div class="status-row">
        <span class="status-pill">Answered: <?= $answeredCount ?></span>
        <span class="status-pill">Skipped: <?= $skippedCount ?></span>
        <span class="status-pill">Marked: <?= $reviewCount ?></span>
        <span class="status-pill">Total: <?= $totalTestQuestions ?></span>
    </div>

    <h3>Question Navigator</h3>
    <p class="muted">
        Green = answered · Orange = skipped · Pink underline = marked for review
    </p>

    <div class="navigator">
        <?php foreach ($testQuestions as $i => $navQuestion): ?>
            <?php
            $navId = (int)$navQuestion["id"];
            $navStatus = (string)($statuses[$navId] ?? "unanswered");
            $navClass = "";

            if (!empty($answers[$navId])) {
                $navClass .= " answered";
            } elseif ($navStatus === "skipped") {
                $navClass .= " skipped";
            }

            if (!empty($review[$navId])) {
                $navClass .= " marked";
            }

            if ($i === $currentIndex) {
                $navClass .= " current";
            }
            ?>
            <button type="button"
                    class="nav-item<?= h($navClass) ?>"
                    data-goto="<?= $i ?>"
                    title="Go to question <?= $i + 1 ?>">
                <?= $i + 1 ?><?php if (!empty($review[$navId])): ?> ✿<?php endif; ?>
            </button>
        <?php endforeach; ?>
    </div>

    <form method="post" id="testForm">
        <input type="hidden" name="current_index" value="<?= $currentIndex ?>">

        <div class="question">
            <div class="question-meta">
                <span class="tag">
                    Topic: <?= h($currentQuestion["topic"] ?: "General") ?>
                </span>
                <span class="tag">
                    Status: <?= h(ucfirst($currentStatus)) ?>
                </span>
                <?php if ($isMarked): ?>
                    <span class="tag review">✿ Marked for review</span>
                <?php endif; ?>
            </div>

            <h2>
                <?= $currentIndex + 1 ?>.
                <?= h($currentQuestion["question"]) ?>
            </h2>

            <?php foreach ($currentQuestion["_options"] as $option): ?>
                <div class="option">
                    <label>
                        <input type="radio"
                               name="current_answer"
                               value="<?= h($option["label"]) ?>"
                               <?= $currentAnswer === $option["label"] ? "checked" : "" ?>>
                        <span>
                            <strong><?= h($option["label"]) ?>.</strong>
                            <?= h($option["text"]) ?>
                        </span>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="navigation-buttons">
            <div class="left">
                <button type="submit"
                        name="action"
                        value="previous"
                        class="secondary"
                        <?= $currentIndex === 0 ? "disabled" : "" ?>>
                    ← Previous Question
                </button>

                <button type="submit"
                        name="action"
                        value="skip"
                        class="secondary"
                        <?= $currentIndex === $totalTestQuestions - 1 ? "disabled" : "" ?>>
                    Skip Question →
                </button>

                <button type="submit"
                        name="action"
                        value="review"
                        class="review-button">
                    <?= $isMarked ? "✓ Remove Review Mark" : "✿ Mark for Review" ?>
                </button>
            </div>

            <div class="right">
                <button type="submit"
                        name="action"
                        value="next"
                        class="primary"
                        <?= $currentIndex === $totalTestQuestions - 1 ? "disabled" : "" ?>>
                    Next Question →
                </button>

                <button type="submit"
                        name="action"
                        value="submit"
                        class="primary"
                        onclick="return confirm('Submit your custom test now?');">
                    Submit Test ✓
                </button>
            </div>
        </div>
    </form>
</section>

<script>
const testForm = document.getElementById('testForm');
const timerDisplay = document.getElementById('timer');
const timerBox = document.getElementById('timerBox');

let remaining = Math.max(
    0,
    <?= (int)$test["duration"] ?> -
    (Math.floor(Date.now() / 1000) - <?= (int)$test["started_at"] ?>)
);

let submitting = false;

function submitAutomatically() {
    if (submitting) return;

    submitting = true;

    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'submit';
    testForm.appendChild(actionInput);

    const timeoutInput = document.createElement('input');
    timeoutInput.type = 'hidden';
    timeoutInput.name = 'timed_out';
    timeoutInput.value = '1';
    testForm.appendChild(timeoutInput);

    // Native form submission avoids confirmation dialogs at timeout.
    testForm.submit();
}

function updateTimer() {
    const mins = Math.floor(remaining / 60);
    const secs = remaining % 60;

    timerDisplay.textContent =
        String(mins).padStart(2, '0') + ':' +
        String(secs).padStart(2, '0');

    if (remaining <= 30) {
        timerBox.classList.add('danger');
    }

    if (remaining <= 0) {
        submitAutomatically();
        return;
    }

    remaining--;
}

updateTimer();

const timerInterval = setInterval(() => {
    if (submitting) {
        clearInterval(timerInterval);
        return;
    }

    updateTimer();
}, 1000);

// Click a numbered button to visit that question.
document.querySelectorAll('[data-goto]').forEach(button => {
    button.addEventListener('click', () => {
        const target = parseInt(button.dataset.goto, 10);

        const selected = testForm.querySelector(
            'input[name="current_answer"]:checked'
        );

        const form = document.createElement('form');
        form.method = 'post';
        form.action = 'custom_test.php?mode=run';

        const fields = {
            action: 'goto',
            current_index: <?= $currentIndex ?>,
            goto_index: target
        };

        Object.entries(fields).forEach(([name, value]) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        });

        if (selected) {
            const answerInput = document.createElement('input');
            answerInput.type = 'hidden';
            answerInput.name = 'current_answer';
            answerInput.value = selected.value;
            form.appendChild(answerInput);
        }

        document.body.appendChild(form);
        form.submit();
    });
});
</script>

<?php elseif ($mode === "result" && $results): ?>

<section class="panel">
    <h2>✿ Custom Test Results</h2>

    <p class="score">
        <?= (int)$results["score"] ?> / <?= (int)$results["total"] ?>
    </p>

    <p class="muted">
        Accuracy:
        <?= $results["total"] > 0
            ? round(($results["score"] / $results["total"]) * 100)
            : 0 ?>%
    </p>

    <?php if ($results["timed_out"]): ?>
        <p class="alert">
            Your time expired. Your test was submitted automatically.
        </p>
    <?php endif; ?>

    <p class="muted">
        Answer attempts saved to history: <?= (int)$results["history_saved"] ?>.
        Unanswered questions are not saved as answer attempts.
    </p>

    <?php foreach ($results["questions"] as $index => $q): ?>
        <div class="question">
            <h3><?= $index + 1 ?>. <?= h($q["question"]) ?></h3>

            <p class="muted">
                Topic: <?= h($q["topic"] ?: "General") ?>
            </p>

            <?php foreach ($q["_options"] as $option): ?>
                <?php
                $isAnswer = strtoupper($option["label"]) ===
                    strtoupper((string)$q["correct_answer"]);

                $isSelected = strtoupper($option["label"]) ===
                    strtoupper((string)$q["selected_answer"]);

                $class = $isAnswer
                    ? "correct"
                    : ($isSelected ? "wrong" : "");
                ?>
                <div class="option <?= h($class) ?>">
                    <strong><?= h($option["label"]) ?>.</strong>
                    <?= h($option["text"]) ?>

                    <?php if ($isAnswer): ?>
                        <strong> — Correct answer</strong>
                    <?php elseif ($isSelected): ?>
                        <strong> — Your answer</strong>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php if ($q["selected_answer"] === ""): ?>
                <p class="alert">Not answered.</p>
            <?php elseif ($q["is_correct"]): ?>
                <p style="color:var(--green)">✓ Correct!</p>
            <?php else: ?>
                <p class="alert">Incorrect answer.</p>
            <?php endif; ?>

            <p>
                <strong>Explanation:</strong><br>
                <?= trim((string)$q["explanation"]) !== ""
                    ? nl2br(h($q["explanation"]))
                    : "No explanation was provided for this question." ?>
            </p>
        </div>
    <?php endforeach; ?>

    <a href="custom_test.php" class="button-link primary">
        Create Another Test
    </a>
    <a href="history.php" class="button-link secondary">
        View History
    </a>
    <a href="dashboard.php" class="button-link secondary">
        Dashboard
    </a>
</section>

<?php endif; ?>

</div>
</body>
</html>

