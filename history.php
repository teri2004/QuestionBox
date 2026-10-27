
<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$userId = (int) $_SESSION["user_id"];

function e($value) {
    return htmlspecialchars(
        (string)($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

/* Load practice statistics */
$statsSql = "
    SELECT
        COUNT(*) AS total_attempts,
        COALESCE(SUM(is_correct = 1), 0) AS correct_answers,
        COALESCE(SUM(is_correct = 0), 0) AS incorrect_answers
    FROM practice_history
    WHERE user_id = ?
";

$statsStmt = $conn->prepare($statsSql);

if (!$statsStmt) {
    die("Unable to load practice statistics.");
}

$statsStmt->bind_param("i", $userId);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();
$statsStmt->close();

$totalAttempts = (int)($stats["total_attempts"] ?? 0);
$correctAnswers = (int)($stats["correct_answers"] ?? 0);
$incorrectAnswers = (int)($stats["incorrect_answers"] ?? 0);

$accuracy = $totalAttempts > 0
    ? (int)round(($correctAnswers / $totalAttempts) * 100)
    : 0;

/* Check whether dynamic options are available */
$optionTableExists = false;
$optionColumns = [];

$tableResult = $conn->query("SHOW TABLES LIKE 'question_options'");

if ($tableResult && $tableResult->num_rows > 0) {
    $optionTableExists = true;

    $columnResult = $conn->query("SHOW COLUMNS FROM question_options");

    if ($columnResult) {
        while ($column = $columnResult->fetch_assoc()) {
            $optionColumns[] = $column["Field"];
        }
    }
}

$hasDynamicOptions = $optionTableExists
    && in_array("question_id", $optionColumns, true)
    && in_array("option_label", $optionColumns, true)
    && in_array("option_text", $optionColumns, true);

/* Load attempts */
$historySql = "
    SELECT
        ph.id,
        ph.question_id,
        ph.selected_answer,
        ph.is_correct,
        ph.attempted_at,
        q.question,
        q.option_a,
        q.option_b,
        q.option_c,
        q.option_d,
        q.correct_answer,
        q.explanation
    FROM practice_history ph
    INNER JOIN questions q ON ph.question_id = q.id
    WHERE ph.user_id = ?
    ORDER BY ph.attempted_at DESC, ph.id DESC
";

$stmt = $conn->prepare($historySql);

if (!$stmt) {
    die("Unable to load practice history.");
}

$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

/* Load dynamic options for each question when supported */
$dynamicOptions = [];

if ($hasDynamicOptions) {
    $optionsSql = "
        SELECT question_id, option_label, option_text
        FROM question_options
        ORDER BY question_id, option_label
    ";

    $optionsResult = $conn->query($optionsSql);

    if ($optionsResult) {
        while ($option = $optionsResult->fetch_assoc()) {
            $qid = (int)$option["question_id"];
            $label = strtoupper(trim($option["option_label"]));

            $dynamicOptions[$qid][$label] = $option["option_text"];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Practice History | QuestionBox</title>

<style>
* {
    box-sizing: border-box;
}

:root {
    --bg: #08090d;
    --card: #15151d;
    --panel: #20202a;
    --pink: #ff4fa3;
    --orange: #ff963c;
    --yellow: #ffd166;
    --text: #f8fafc;
    --muted: #a1a1b5;
    --border: #383844;
}

body {
    margin: 0;
    padding: 24px;
    background: var(--bg);
    color: var(--text);
    font-family: Arial, sans-serif;
    line-height: 1.6;
}

.container {
    width: 100%;
    max-width: 1000px;
    margin: 30px auto;
}

.brand {
    font-size: 30px;
    margin-bottom: 5px;
    background: linear-gradient(
        90deg,
        var(--pink),
        var(--orange),
        var(--yellow)
    );
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
}

.subtitle,
.muted {
    color: var(--muted);
}

.navigation {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    margin: 22px 0;
}

.navigation a {
    color: var(--yellow);
    text-decoration: none;
}

.navigation a:hover {
    color: var(--pink);
}

.card {
    background: var(--card);
    border: 1px solid var(--border);
    padding: 26px;
    border-radius: 18px;
    margin: 20px 0;
    box-shadow: 0 12px 35px #0005;
}

h2 {
    color: var(--orange);
}

h3 {
    color: var(--yellow);
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
    margin: 20px 0;
}

.stat {
    background: #0d0d14;
    border: 1px solid var(--border);
    padding: 18px 10px;
    border-radius: 12px;
    text-align: center;
    min-width: 0;
}

.stat h2 {
    margin: 0 0 8px;
    font-size: 28px;
    color: var(--yellow);
    overflow-wrap: anywhere;
}

.stat p {
    margin: 0;
    color: var(--muted);
    font-size: 14px;
}

.correct {
    color: #86efac !important;
}

.incorrect {
    color: #fda4af !important;
}

.progress-track {
    height: 9px;
    margin-top: 12px;
    background: #33333e;
    border-radius: 10px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(
        90deg,
        var(--pink),
        var(--orange),
        var(--yellow)
    );
    border-radius: 10px;
    transition: width 0.3s ease;
}

.attempt-heading {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.status {
    display: inline-block;
    padding: 6px 13px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: bold;
}

.status.correct {
    background: #163d30;
    border: 1px solid #276749;
}

.status.incorrect {
    background: #481b2b;
    border: 1px solid #8b3450;
}

.question-text,
.answer-line {
    overflow-wrap: anywhere;
}

.question-text {
    white-space: pre-wrap;
    line-height: 1.8;
}

.answer-box,
.explanation {
    margin-top: 16px;
    padding: 16px;
    background: #0d0d14;
    border: 1px solid var(--border);
    border-radius: 10px;
}

.answer-box p,
.explanation p {
    overflow-wrap: anywhere;
}

.button {
    display: inline-block;
    padding: 12px 19px;
    margin: 8px 8px 0 0;
    background: var(--pink);
    color: #1a0711;
    border-radius: 9px;
    font-weight: bold;
    text-decoration: none;
    transition: background 0.2s ease;
}

.button:hover {
    background: var(--orange);
    text-decoration: none;
}

.empty-state {
    text-align: center;
    padding: 30px 15px;
}

@media (max-width: 650px) {
    body {
        padding: 12px;
    }

    .container {
        margin: 12px auto;
    }

    .card {
        padding: 18px;
    }

    .summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .stat h2 {
        font-size: 24px;
    }

    .brand {
        font-size: 25px;
    }
}
</style>
</head>

<body>
<div class="container">

    <h1 class="brand">QuestionBox</h1>
    <p class="subtitle">Practice. Improve. Succeed.</p>

    <nav class="navigation">
        <a href="dashboard.php">Dashboard</a>
        <a href="create_question.php">Create Question</a>
        <a href="library.php">My Library</a>
        <a href="practice.php">Practice Quiz</a>
        <a href="history.php">Practice History</a>
        <a href="logout.php">Log Out</a>
    </nav>

    <section class="card">
        <h2>Your Practice Summary</h2>

        <p class="muted">
            Track your previous answers and monitor your progress.
        </p>

        <div class="summary-grid">
            <div class="stat">
                <h2><?= $totalAttempts ?></h2>
                <p>Total Attempts</p>
            </div>

            <div class="stat">
                <h2 class="correct"><?= $correctAnswers ?></h2>
                <p>Correct Answers</p>
            </div>

            <div class="stat">
                <h2 class="incorrect"><?= $incorrectAnswers ?></h2>
                <p>Incorrect Answers</p>
            </div>

            <div class="stat">
                <h2><?= $accuracy ?>%</h2>
                <p>Accuracy</p>
            </div>
        </div>

        <p>
            <strong>Overall Accuracy: <?= $accuracy ?>%</strong>
        </p>

        <div
            class="progress-track"
            role="progressbar"
            aria-label="Overall accuracy"
            aria-valuenow="<?= $accuracy ?>"
            aria-valuemin="0"
            aria-valuemax="100"
        >
            <div
                class="progress-fill"
                style="width: <?= $accuracy ?>%;"
            ></div>
        </div>
    </section>

    <h2>Individual Attempts</h2>

    <?php if ($result->num_rows === 0): ?>

        <section class="card empty-state">
            <h2>No Practice History Yet</h2>

            <p class="muted">
                Your attempts will appear here after you answer
                questions in Practice Quiz.
            </p>

            <a class="button" href="practice.php">Start Practising</a>
            <a class="button" href="library.php">My Library</a>
        </section>

    <?php else: ?>

        <?php $attempt = 1; ?>

        <?php while ($row = $result->fetch_assoc()): ?>

            <?php
            $questionId = (int)$row["question_id"];

            $selectedAnswer = strtoupper(
                trim((string)($row["selected_answer"] ?? ""))
            );

            $correctAnswer = strtoupper(
                trim((string)($row["correct_answer"] ?? ""))
            );

            $optionMap = [];

            if (isset($dynamicOptions[$questionId])) {
                $optionMap = $dynamicOptions[$questionId];
            } else {
                $optionMap = [
                    "A" => $row["option_a"] ?? "",
                    "B" => $row["option_b"] ?? "",
                    "C" => $row["option_c"] ?? "",
                    "D" => $row["option_d"] ?? ""
                ];
            }

            $selectedText = $optionMap[$selectedAnswer] ?? "";
            $correctText = $optionMap[$correctAnswer] ?? "";

            $isCorrect = (int)$row["is_correct"] === 1;
            ?>

            <section class="card">

                <div class="attempt-heading">
                    <h2>Attempt <?= $attempt++ ?></h2>

                    <span class="status <?= $isCorrect
                        ? 'correct'
                        : 'incorrect' ?>">
                        <?= $isCorrect ? "Correct" : "Incorrect" ?>
                    </span>
                </div>

                <p class="question-text">
                    <strong>Question:</strong><br>
                    <?= nl2br(e($row["question"])) ?>
                </p>

                <div class="answer-box">
                    <p class="answer-line">
                        <strong>Your Answer:</strong><br>

                        <?php if (
                            $selectedAnswer !== "" &&
                            array_key_exists($selectedAnswer, $optionMap)
                        ): ?>
                            <?= e($selectedAnswer) ?> —
                            <?= e($selectedText) ?>
                        <?php else: ?>
                            <span class="muted">No answer recorded</span>
                        <?php endif; ?>
                    </p>

                    <p class="answer-line">
                        <strong>Correct Answer:</strong><br>

                        <?php if (
                            $correctAnswer !== "" &&
                            array_key_exists($correctAnswer, $optionMap)
                        ): ?>
                            <?= e($correctAnswer) ?> —
                            <?= e($correctText) ?>
                        <?php else: ?>
                            <?= e($correctAnswer ?: "Not available") ?>
                        <?php endif; ?>
                    </p>
                </div>

                <p class="<?= $isCorrect ? 'correct' : 'incorrect' ?>">
                    <strong>
                        <?= $isCorrect
                            ? "Well done! Your answer is correct."
                            : "Keep practising. Review the correct answer." ?>
                    </strong>
                </p>

                <?php if (!empty($row["explanation"])): ?>
                    <div class="explanation">
                        <h3>Explanation</h3>
                        <p><?= nl2br(e($row["explanation"])) ?></p>
                    </div>
                <?php endif; ?>

                <p class="muted">
                    Attempted on: <?= e($row["attempted_at"]) ?>
                </p>

            </section>

        <?php endwhile; ?>

    <?php endif; ?>

</div>
</body>
</html>

<?php
$stmt->close();
?>


