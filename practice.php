

<?php
session_start();
require_once "config.php";

/* QuestionBox Practice - navigation, review, skip and timers */

if (isset($conn) && $conn instanceof mysqli) {
    $db = $conn;
} elseif (isset($mysqli) && $mysqli instanceof mysqli) {
    $db = $mysqli;
} elseif (isset($con) && $con instanceof mysqli) {
    $db = $con;
} else {
    die("Database connection not found. Check config.php.");
}

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$userId = (int) $_SESSION["user_id"];

function e($value): string {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function tableExists(mysqli $db, string $table): bool {
    $table = $db->real_escape_string($table);
    $result = $db->query("SHOW TABLES LIKE '$table'");
    return $result && $result->num_rows > 0;
}

function getColumns(mysqli $db, string $table): array {
    $columns = [];
    $result = $db->query("SHOW COLUMNS FROM `$table`");

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $columns[] = $row["Field"];
        }
    }

    return $columns;
}

function goToQuestion(int $index): void {
    header("Location: practice.php?q=" . max(0, $index));
    exit;
}

/* Keep practice progress separately for each logged-in user. */
if (!isset($_SESSION["qb_practice"]) ||
    !is_array($_SESSION["qb_practice"])) {
    $_SESSION["qb_practice"] = [];
}

if (!isset($_SESSION["qb_practice"][$userId]) ||
    !is_array($_SESSION["qb_practice"][$userId])) {
    $_SESSION["qb_practice"][$userId] = [];
}

$progress =& $_SESSION["qb_practice"][$userId];

$questionColumns = getColumns($db, "questions");

foreach (["id", "user_id", "question"] as $required) {
    if (!in_array($required, $questionColumns, true)) {
        die("The questions table is missing the required column: " . e($required));
    }
}

$hasJson = in_array("options_json", $questionColumns, true);
$hasTopic = in_array("topic", $questionColumns, true);
$hasTimerEnabled = in_array("timer_enabled", $questionColumns, true);
$hasTimerSeconds = in_array("timer_seconds", $questionColumns, true);
$hasExplanation = in_array("explanation", $questionColumns, true);
$hasCorrectAnswer = in_array("correct_answer", $questionColumns, true);

$hasQuestionOptions = tableExists($db, "question_options");
$optionColumns = $hasQuestionOptions
    ? getColumns($db, "question_options")
    : [];

$hasDynamicOptions = $hasQuestionOptions &&
    in_array("question_id", $optionColumns, true) &&
    in_array("option_label", $optionColumns, true) &&
    in_array("option_text", $optionColumns, true);

/* Load this user's questions only. */
$select = [
    "id", "question",
    in_array("option_a", $questionColumns, true) ? "option_a" : "'' AS option_a",
    in_array("option_b", $questionColumns, true) ? "option_b" : "'' AS option_b",
    in_array("option_c", $questionColumns, true) ? "option_c" : "'' AS option_c",
    in_array("option_d", $questionColumns, true) ? "option_d" : "'' AS option_d",
    $hasCorrectAnswer ? "correct_answer" : "'' AS correct_answer",
    $hasExplanation ? "explanation" : "'' AS explanation",
    $hasTopic ? "topic" : "'General' AS topic",
    $hasJson ? "options_json" : "NULL AS options_json",
    $hasTimerEnabled ? "timer_enabled" : "0 AS timer_enabled",
    $hasTimerSeconds ? "timer_seconds" : "60 AS timer_seconds"
];

$stmt = $db->prepare(
    "SELECT " . implode(", ", $select) .
    " FROM questions WHERE user_id = ? ORDER BY id DESC"
);
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

$questions = [];

while ($row = $result->fetch_assoc()) {
    $options = [];

    /* First preference: question_options table. */
    if ($hasDynamicOptions) {
        $optionStmt = $db->prepare(
            "SELECT option_label, option_text
             FROM question_options
             WHERE question_id = ?
             ORDER BY option_label ASC"
        );

        $qid = (int) $row["id"];
        $optionStmt->bind_param("i", $qid);
        $optionStmt->execute();
        $optionResult = $optionStmt->get_result();

        while ($opt = $optionResult->fetch_assoc()) {
            $label = strtoupper(trim($opt["option_label"]));
            $text = trim($opt["option_text"]);

            if (preg_match('/^[A-O]$/', $label) && $text !== "") {
                $options[$label] = $text;
            }
        }

        $optionStmt->close();
    }

    /* Second preference: options_json. */
    if (count($options) < 2 && !empty($row["options_json"])) {
        $decoded = json_decode($row["options_json"], true);

        if (is_array($decoded)) {
            $jsonOptions = [];

            foreach ($decoded as $key => $value) {
                if (is_array($value)) {
                    $label = strtoupper(trim((string)(
                        $value["option_label"] ??
                        $value["label"] ??
                        (is_string($key) ? $key : "")
                    )));

                    $text = trim((string)(
                        $value["option_text"] ??
                        $value["text"] ??
                        $value["value"] ??
                        ""
                    ));

                    if ($label === "" && is_int($key)) {
                        $label = chr(65 + $key);
                    }
                } else {
                    $label = is_string($key)
                        ? strtoupper(trim($key))
                        : chr(65 + (int) $key);
                    $text = trim((string) $value);
                }

                if (preg_match('/^[A-O]$/', $label) && $text !== "") {
                    $jsonOptions[$label] = $text;
                }
            }

            if (count($jsonOptions) >= 2) {
                $options = $jsonOptions;
            }
        }
    }

    /* Third preference: legacy A-D columns. */
    if (count($options) < 2) {
        $options = [];

        foreach (["A", "B", "C", "D"] as $letter) {
            $text = trim((string)($row["option_" . strtolower($letter)] ?? ""));

            if ($text !== "") {
                $options[$letter] = $text;
            }
        }
    }

    uksort($options, function ($a, $b) {
        return ord($a) <=> ord($b);
    });

    if (count($options) < 2) {
        continue;
    }

    $row["_options"] = $options;
    $row["timer_enabled"] = (int)$row["timer_enabled"];
    $row["timer_seconds"] = max(5, min(3600, (int)$row["timer_seconds"]));
    $row["correct_answer"] = strtoupper(trim((string)$row["correct_answer"]));

    $questions[] = $row;
}

$stmt->close();

$totalQuestions = count($questions);

/* Clear progress for deleted questions; retain progress for existing ones. */
$validIds = [];
foreach ($questions as $item) {
    $validIds[(string)$item["id"]] = true;
}

foreach (array_keys($progress) as $savedId) {
    if (!isset($validIds[(string)$savedId])) {
        unset($progress[$savedId]);
    }
}

$currentIndex = $totalQuestions > 0
    ? max(0, min($totalQuestions - 1, (int)($_GET["q"] ?? 0)))
    : 0;

$question = $totalQuestions > 0 ? $questions[$currentIndex] : null;
$feedback = null;
$selectedAnswer = "";
$expired = false;

/* Create the session state for a question on its first visit. */
if ($question !== null) {
    $qidKey = (string)$question["id"];

    if (!isset($progress[$qidKey]) || !is_array($progress[$qidKey])) {
        $progress[$qidKey] = [
            "status" => "unanswered",
            "selected" => "",
            "feedback" => null,
            "deadline" => null,
            "review" => false
        ];
    }

    $state =& $progress[$qidKey];

    if (!isset($state["review"])) {
        $state["review"] = false;
    }

    if (!isset($state["status"])) {
        $state["status"] = "unanswered";
    }

    if (!isset($state["feedback"])) {
        $state["feedback"] = null;
    }

    if (!isset($state["selected"])) {
        $state["selected"] = "";
    }

    if (!isset($state["deadline"])) {
        $state["deadline"] = null;
    }

    /*
     * Start a question's timer once. Returning to a question does not
     * reset its countdown. An expired timer cannot be restarted by refresh.
     */
    if ((int)$question["timer_enabled"] === 1 &&
        $state["status"] === "unanswered" &&
        $state["deadline"] === null) {
        $state["deadline"] = time() + (int)$question["timer_seconds"];
    }

    /* Handle navigation actions and answer submission. */
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $postedIndex = (int)($_POST["question_index"] ?? -1);
        $action = (string)($_POST["action"] ?? "");

        if ($postedIndex !== $currentIndex) {
            goToQuestion($currentIndex);
        }

        $validLabels = array_keys($question["_options"]);

        if ($action === "navigate") {
            $target = (int)($_POST["target_index"] ?? $currentIndex);
            goToQuestion(max(0, min($totalQuestions - 1, $target)));
        }

        if ($action === "review") {
            $state["review"] = !$state["review"];
            goToQuestion($currentIndex);
        }

        if ($action === "skip") {
            if ($state["status"] === "unanswered") {
                $state["status"] = "skipped";
                $state["feedback"] = null;
                $state["deadline"] = null;
            }

            $target = min($totalQuestions - 1, $currentIndex + 1);
            goToQuestion($target);
        }

        if ($action === "submit" || $action === "timeout") {
            /* Do not overwrite an answer already recorded. */
            if ($state["status"] === "answered" ||
                $state["status"] === "timed_out") {
                goToQuestion($currentIndex);
            }

            $isTimeout = $action === "timeout";

            if (!$isTimeout &&
                (int)$question["timer_enabled"] === 1 &&
                $state["deadline"] !== null &&
                time() >= (int)$state["deadline"]) {
                $isTimeout = true;
            }

            if ($isTimeout) {
                $state["status"] = "timed_out";
                $state["selected"] = "";
                $state["feedback"] = [
                    "correct" => false,
                    "message" => "Time is up!",
                    "explanation" => (string)$question["explanation"],
                    "expired" => true
                ];
                $state["deadline"] = null;
            } else {
                $answer = strtoupper(trim((string)($_POST["answer"] ?? "")));

                if (!in_array($answer, $validLabels, true)) {
                    $_SESSION["qb_practice_message"] =
                        "Please select an option before submitting.";
                    goToQuestion($currentIndex);
                }

                $isCorrect = $answer !== "" &&
                    $answer === $question["correct_answer"];

                $state["status"] = "answered";
                $state["selected"] = $answer;
                $state["deadline"] = null;
                $state["feedback"] = [
                    "correct" => $isCorrect,
                    "message" => $isCorrect
                        ? "Correct answer! Well done."
                        : "Incorrect answer. Keep practising!",
                    "explanation" => (string)$question["explanation"],
                    "expired" => false
                ];

                /* Save only actual submitted answers to practice history. */
                if (tableExists($db, "practice_history")) {
                    $historyColumns = getColumns($db, "practice_history");
                    $required = [
                        "user_id", "question_id",
                        "selected_answer", "is_correct"
                    ];

                    $ready = count(array_intersect($required, $historyColumns))
                        === count($required);

                    if ($ready) {
                        $historyStmt = $db->prepare(
                            "INSERT INTO practice_history
                             (user_id, question_id, selected_answer, is_correct)
                             VALUES (?, ?, ?, ?)"
                        );

                        $historyQuestionId = (int)$question["id"];
                        $correctValue = $isCorrect ? 1 : 0;

                        $historyStmt->bind_param(
                            "iisi",
                            $userId,
                            $historyQuestionId,
                            $answer,
                            $correctValue
                        );

                        $historyStmt->execute();
                        $historyStmt->close();
                    }
                }
            }

            goToQuestion($currentIndex);
        }
    }

    $feedback = $state["feedback"];
    $selectedAnswer = (string)$state["selected"];
    $expired = $state["status"] === "timed_out";

    unset($state);
}

/* Status counts for the navigator. */
$statusCounts = [
    "answered" => 0,
    "unanswered" => 0,
    "skipped" => 0,
    "timed_out" => 0,
    "review" => 0
];

foreach ($questions as $item) {
    $key = (string)$item["id"];
    $saved = $progress[$key] ?? [];
    $status = $saved["status"] ?? "unanswered";

    if (isset($statusCounts[$status])) {
        $statusCounts[$status]++;
    }

    if (!empty($saved["review"])) {
        $statusCounts["review"]++;
    }
}

$answeredCount = $statusCounts["answered"];
$previousIndex = max(0, $currentIndex - 1);
$nextIndex = min(max(0, $totalQuestions - 1), $currentIndex + 1);

$notice = $_SESSION["qb_practice_message"] ?? "";
unset($_SESSION["qb_practice_message"]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Practice | QuestionBox</title>

<style>
:root {
    --bg:#08090d;
    --card:#15151d;
    --panel:#20202a;
    --pink:#ff6fae;
    --orange:#ffd0a8;
    --yellow:#fff3a6;
    --white:#fff;
    --muted:#b1b1bf;
    --border:#383844;
    --green:#35d39a;
    --red:#ff6464;
}
* {box-sizing:border-box}
body {
    margin:0;background:var(--bg);color:var(--white);
    font-family:Arial,Helvetica,sans-serif;line-height:1.6
}
a {color:inherit;text-decoration:none}
.navbar {
    display:flex;justify-content:space-between;align-items:center;
    flex-wrap:wrap;gap:14px;padding:17px 5%;
    background:#111119;border-bottom:1px solid var(--border)
}
.brand {font-size:24px;font-weight:800;color:var(--pink)}
.nav-links {display:flex;flex-wrap:wrap;gap:8px}
.nav-links a {
    padding:8px 11px;color:var(--muted);border-radius:9px
}
.nav-links a:hover,.nav-links .active {
    color:var(--pink);background:var(--panel)
}
.container {width:min(1100px,94%);margin:32px auto}
.heading {margin-bottom:20px}
.heading h1 {margin:0;font-size:clamp(27px,4vw,36px)}
.heading p {margin:5px 0;color:var(--muted)}
.gradient {
    background:linear-gradient(90deg,var(--pink),var(--orange),var(--yellow));
    color:transparent;background-clip:text;-webkit-background-clip:text
}
.layout {display:grid;grid-template-columns:minmax(0,1fr) 260px;gap:20px}
.card {
    background:var(--card);border:1px solid var(--border);
    border-radius:20px;padding:clamp(18px,3vw,30px);
    min-width:0;box-shadow:0 12px 35px #0003
}
.meta {display:flex;gap:10px;justify-content:space-between;flex-wrap:wrap}
.pill {
    display:inline-block;padding:6px 11px;border:1px solid var(--border);
    background:var(--panel);border-radius:30px;font-size:13px;color:var(--yellow)
}
.timer {font-weight:bold;color:var(--orange)}
.timer.warning {color:var(--red)}
.progress {
    height:7px;background:var(--panel);border-radius:9px;
    overflow:hidden;margin:20px 0
}
.progress div {
    height:100%;background:linear-gradient(90deg,var(--pink),var(--orange),var(--yellow))
}
.question {font-size:clamp(19px,3vw,25px);overflow-wrap:anywhere;line-height:1.5}
.options {display:grid;gap:12px}
.option {
    display:flex;align-items:flex-start;gap:12px;padding:14px;
    background:var(--panel);border:1px solid var(--border);
    border-radius:13px;cursor:pointer;overflow-wrap:anywhere
}
.option:hover {border-color:var(--pink)}
.option:has(input:checked) {
    border-color:var(--pink);background:#ff6fae15
}
.option input {margin-top:6px;accent-color:var(--pink)}
.letter {
    display:inline-grid;place-items:center;min-width:30px;height:30px;
    border-radius:8px;background:#30303c;color:var(--yellow);font-weight:bold
}
.option-text {white-space:pre-wrap;flex:1}
.btn-row {display:flex;gap:10px;flex-wrap:wrap;margin-top:22px}
.btn {
    display:inline-flex;align-items:center;justify-content:center;
    min-height:43px;padding:10px 15px;border:1px solid var(--border);
    border-radius:10px;background:var(--panel);color:var(--white);
    font-weight:bold;font-size:14px;cursor:pointer
}
.btn:hover {border-color:var(--pink)}
.primary {background:linear-gradient(100deg,var(--pink),var(--orange));color:#171019;border:0}
.review-btn.on {border-color:var(--yellow);color:var(--yellow)}
.feedback {
    padding:18px;margin-top:22px;background:var(--panel);
    border:1px solid var(--border);border-radius:14px
}
.feedback.correct {border-color:var(--green)}
.feedback.incorrect {border-color:var(--red)}
.feedback h3 {margin:0 0 8px}
.feedback.correct h3 {color:var(--green)}
.feedback.incorrect h3 {color:var(--red)}
.explanation {white-space:pre-wrap;overflow-wrap:anywhere;color:var(--muted)}
.navigator h3 {margin-top:0}
.status-legend {font-size:12px;color:var(--muted);display:grid;gap:5px;margin-bottom:15px}
.legend-dot {display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:5px}
.dot-answered {background:var(--green)}
.dot-unanswered {background:#666675}
.dot-skipped {background:var(--orange)}
.dot-review {background:var(--yellow)}
.dot-timeout {background:var(--red)}
.question-grid {display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:7px}
.qnav {
    min-width:0;aspect-ratio:1;border:1px solid var(--border);
    border-radius:9px;background:var(--panel);color:white;
    font-weight:bold;cursor:pointer;position:relative
}
.qnav.current {outline:2px solid var(--pink);outline-offset:1px}
.qnav.answered {background:#174b3a;border-color:var(--green)}
.qnav.skipped {background:#543923;border-color:var(--orange)}
.qnav.timed_out {background:#512c34;border-color:var(--red)}
.qnav.review {box-shadow:inset 0 -4px var(--yellow)}
.qnav .star {position:absolute;right:2px;top:-5px;color:var(--yellow);font-size:13px}
.notice {padding:12px;border:1px solid var(--orange);border-radius:10px;color:var(--orange);margin-bottom:15px}
.empty {text-align:center;padding:35px 15px}
.empty h2 {color:var(--pink)}
.empty p {color:var(--muted)}
.footer {padding:25px;text-align:center;color:var(--muted)}
@media(max-width:780px) {
    .layout {grid-template-columns:1fr}
    .navigator {order:-1}
    .question-grid {grid-template-columns:repeat(8,minmax(0,1fr))}
}
@media(max-width:480px) {
    .navbar {padding:14px 4%}
    .nav-links {width:100%}
    .nav-links a {font-size:12px;padding:7px}
    .question-grid {grid-template-columns:repeat(6,minmax(0,1fr))}
    .btn-row .btn {flex:1 1 100%}
}
</style>
</head>

<body>
<header class="navbar">
    <a class="brand" href="dashboard.php">QuestionBox ✿</a>
    <nav class="nav-links">
        <a href="dashboard.php">Dashboard</a>
        <a href="create_question.php">Create</a>
        <a href="library.php">My Library</a>
        <a href="practice.php" class="active">Practice</a>
        <a href="history.php">History</a>
        <a href="logout.php">Logout</a>
    </nav>
</header>

<main class="container">
    <div class="heading">
        <h1>Practice <span class="gradient">Session ✿</span></h1>
        <p>Answer at your own pace, mark questions, and revisit them anytime.</p>
    </div>

    <?php if ($notice !== ""): ?>
        <div class="notice"><?= e($notice) ?></div>
    <?php endif; ?>

    <?php if ($question === null): ?>
        <section class="card empty">
            <h2>No Questions Available ❀</h2>
            <p>Add at least one question with two or more options to your library.</p>
            <div class="btn-row" style="justify-content:center">
                <a class="btn primary" href="create_question.php">+ Create Question</a>
                <a class="btn" href="library.php">Open My Library</a>
            </div>
        </section>
    <?php else: ?>
        <div class="layout">
            <section class="card">
                <div class="meta">
                    <span class="pill">
                        Question <?= $currentIndex + 1 ?> of <?= $totalQuestions ?>
                    </span>
                    <span class="pill">Topic: <?= e($question["topic"] ?: "General") ?></span>

                    <?php if ((int)$question["timer_enabled"] === 1 &&
                              $feedback === null &&
                              isset($progress[(string)$question["id"]]["deadline"]) &&
                              $progress[(string)$question["id"]]["deadline"] !== null): ?>
                        <span class="timer" id="timerDisplay"
                              data-deadline="<?= (int)$progress[(string)$question["id"]]["deadline"] ?>">
                            Timer loading...
                        </span>
                    <?php elseif ((int)$question["timer_enabled"] !== 1): ?>
                        <span class="pill">No timer</span>
                    <?php endif; ?>
                </div>

                <div class="progress">
                    <div style="width:<?= round((($currentIndex + 1) / $totalQuestions) * 100, 2) ?>%"></div>
                </div>

                <h2 class="question"><?= e($question["question"]) ?></h2>

                <?php if ($feedback === null): ?>
                    <form method="POST" id="answerForm">
                        <input type="hidden" name="question_index" value="<?= $currentIndex ?>">
                        <input type="hidden" name="action" id="actionInput" value="submit">

                        <div class="options">
                            <?php foreach ($question["_options"] as $letter => $optionText): ?>
                                <label class="option">
                                    <input type="radio" name="answer" value="<?= e($letter) ?>"
                                        <?= $selectedAnswer === $letter ? "checked" : "" ?>>
                                    <span class="letter"><?= e($letter) ?></span>
                                    <span class="option-text"><?= e($optionText) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <div class="btn-row">
                            <button class="btn primary" type="submit" id="submitButton">
                                Submit Answer
                            </button>

                            <button class="btn review-btn <?= !empty($progress[(string)$question["id"]]["review"]) ? "on" : "" ?>"
                                    type="submit" formnovalidate
                                    onclick="document.getElementById('actionInput').value='review'">
                                <?= !empty($progress[(string)$question["id"]]["review"]) ? "★ Unmark Review" : "☆ Mark for Review" ?>
                            </button>

                            <button class="btn" type="submit" formnovalidate
                                    onclick="document.getElementById('actionInput').value='skip'">
                                Skip Question →
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="feedback <?= !empty($feedback["correct"]) && !$expired ? "correct" : "incorrect" ?>">
                        <h3><?= e($feedback["message"]) ?></h3>

                        <?php if (!$expired): ?>
                            <p>Your answer: <strong><?= e($selectedAnswer) ?></strong></p>
                        <?php endif; ?>

                        <p>
                            Correct answer:
                            <strong><?= e($question["correct_answer"] ?: "Not configured") ?></strong>
                            <?php if (isset($question["_options"][$question["correct_answer"]])): ?>
                                — <?= e($question["_options"][$question["correct_answer"]]) ?>
                            <?php endif; ?>
                        </p>

                        <?php if (trim((string)$feedback["explanation"]) !== ""): ?>
                            <div class="explanation">
                                <strong>Explanation:</strong>
                                <?= e($feedback["explanation"]) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="btn-row">
                        <form method="POST">
                            <input type="hidden" name="question_index" value="<?= $currentIndex ?>">
                            <input type="hidden" name="action" value="review">
                            <button class="btn review-btn <?= !empty($progress[(string)$question["id"]]["review"]) ? "on" : "" ?>">
                                <?= !empty($progress[(string)$question["id"]]["review"]) ? "★ Unmark Review" : "☆ Mark for Review" ?>
                            </button>
                        </form>
                    </div>
                <?php endif; ?>

                <div class="btn-row">
                    <?php if ($currentIndex > 0): ?>
                        <form method="POST">
                            <input type="hidden" name="question_index" value="<?= $currentIndex ?>">
                            <input type="hidden" name="action" value="navigate">
                            <input type="hidden" name="target_index" value="<?= $previousIndex ?>">
                            <button class="btn">← Previous</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($currentIndex < $totalQuestions - 1): ?>
                        <form method="POST">
                            <input type="hidden" name="question_index" value="<?= $currentIndex ?>">
                            <input type="hidden" name="action" value="navigate">
                            <input type="hidden" name="target_index" value="<?= $nextIndex ?>">
                            <button class="btn primary">Next Question →</button>
                        </form>
                    <?php else: ?>
                        <a class="btn primary" href="practice.php?q=0">Return to Question 1</a>
                    <?php endif; ?>

                    <a class="btn" href="history.php">View History</a>
                </div>
            </section>

            <aside class="card navigator">
                <h3>Question Navigator ❀</h3>
                <p style="color:var(--muted);font-size:13px">
                    Select any number to revisit a question.
                </p>

                <div class="status-legend">
                    <span><i class="legend-dot dot-answered"></i>Answered</span>
                    <span><i class="legend-dot dot-unanswered"></i>Unanswered</span>
                    <span><i class="legend-dot dot-skipped"></i>Skipped</span>
                    <span><i class="legend-dot dot-timeout"></i>Time expired</span>
                    <span><i class="legend-dot dot-review"></i>Marked for review</span>
                </div>

                <div class="question-grid">
                    <?php foreach ($questions as $i => $item): ?>
                        <?php
                        $key = (string)$item["id"];
                        $saved = $progress[$key] ?? [];
                        $status = $saved["status"] ?? "unanswered";
                        $review = !empty($saved["review"]);
                        $classes = [$status];
                        if ($review) $classes[] = "review";
                        if ($i === $currentIndex) $classes[] = "current";
                        ?>
                        <form method="POST">
                            <input type="hidden" name="question_index" value="<?= $currentIndex ?>">
                            <input type="hidden" name="action" value="navigate">
                            <input type="hidden" name="target_index" value="<?= $i ?>">
                            <button class="qnav <?= e(implode(" ", $classes)) ?>"
                                    title="Question <?= $i + 1 ?> — <?= e($status) ?><?= $review ? ', marked for review' : '' ?>">
                                <?= $i + 1 ?>
                                <?php if ($review): ?><span class="star">★</span><?php endif; ?>
                            </button>
                        </form>
                    <?php endforeach; ?>
                </div>

                <hr style="border-color:var(--border);margin:20px 0">

                <p><strong><?= $answeredCount ?></strong> answered out of <?= $totalQuestions ?></p>
                <p><strong><?= $statusCounts["skipped"] ?></strong> skipped</p>
                <p><strong><?= $statusCounts["review"] ?></strong> marked for review</p>
                <p><strong><?= $statusCounts["unanswered"] ?></strong> unanswered</p>
                <p><strong><?= $statusCounts["timed_out"] ?></strong> timed out</p>

                <a class="btn" href="library.php" style="width:100%;margin-top:10px">
                    Back to Library
                </a>
            </aside>
        </div>
    <?php endif; ?>
</main>

<footer class="footer">QuestionBox — Learn, Practice, Improve. ✿</footer>

<script>
(function () {
    const display = document.getElementById("timerDisplay");
    const form = document.getElementById("answerForm");

    if (!display || !form) return;

    const deadline = parseInt(display.dataset.deadline || "0", 10);
    const actionInput = document.getElementById("actionInput");
    const submitButton = document.getElementById("submitButton");
    let finished = false;

    function tick() {
        if (finished) return;

        const remaining = deadline - Math.floor(Date.now() / 1000);

        if (remaining <= 0) {
            finished = true;
            display.textContent = "Time is up!";
            actionInput.value = "timeout";

            form.querySelectorAll('input[name="answer"]').forEach(function (input) {
                input.required = false;
            });

            form.submit();
            return;
        }

        const minutes = Math.floor(remaining / 60);
        const seconds = remaining % 60;
        display.textContent = "Time: " + minutes + ":" + String(seconds).padStart(2, "0");

        if (remaining <= 10) display.classList.add("warning");
    }

    tick();
    const interval = setInterval(function () {
        tick();
        if (finished) clearInterval(interval);
    }, 250);

    form.addEventListener("submit", function (event) {
        const action = actionInput.value;

        /*
         * Review and skip are navigation actions, not answer submissions.
         * They must not require a selected radio button.
         */
        if (action === "review" || action === "skip") {
            return;
        }

        if (finished) {
            event.preventDefault();
            return;
        }

        finished = true;
        clearInterval(interval);

        if (submitButton && action === "submit") {
            submitButton.disabled = true;
            submitButton.textContent = "Checking...";
        }
    });
})();
</script>
</body>
</html>
