

<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$userId = (int)$_SESSION["user_id"];
$message = "";
$messageType = "";

function e($value) {
    return htmlspecialchars(
        (string)($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

$topics = [
    "DSA",
    "Development",
    "OOP",
    "Aptitude",
    "CS Fundamentals",
    "General"
];

/* Success messages */
if (isset($_GET["saved"])) {
    $message = "Question saved successfully!";
    $messageType = "success";
} elseif (isset($_GET["updated"])) {
    $message = "Question updated successfully!";
    $messageType = "success";
} elseif (isset($_GET["deleted"])) {
    $message = "Question deleted successfully!";
    $messageType = "success";
}

/* Search and topic filter */
$search = trim($_GET["search"] ?? "");
$topicFilter = $_GET["topic"] ?? "";

if (!in_array($topicFilter, array_merge([""], $topics), true)) {
    $topicFilter = "";
}

/* Load questions belonging to the logged-in user */
$sql = "
    SELECT
        id,
        question,
        option_a,
        option_b,
        option_c,
        option_d,
        correct_answer,
        explanation,
        created_at,
        topic
    FROM questions
    WHERE user_id = ?
";

$types = "i";
$params = [$userId];

if ($search !== "") {
    $sql .= " AND question LIKE ?";
    $types .= "s";
    $params[] = "%" . $search . "%";
}

if ($topicFilter !== "") {
    $sql .= " AND topic = ?";
    $types .= "s";
    $params[] = $topicFilter;
}

$sql .= " ORDER BY id DESC";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Unable to load your question library.");
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

/* Detect dynamic options table */
$hasDynamicOptions = false;
$dynamicOptions = [];

$tableResult = $conn->query("SHOW TABLES LIKE 'question_options'");

if ($tableResult && $tableResult->num_rows > 0) {
    $columnResult = $conn->query("SHOW COLUMNS FROM question_options");
    $optionColumns = [];

    if ($columnResult) {
        while ($column = $columnResult->fetch_assoc()) {
            $optionColumns[] = $column["Field"];
        }
    }

    $hasDynamicOptions =
        in_array("question_id", $optionColumns, true) &&
        in_array("option_label", $optionColumns, true) &&
        in_array("option_text", $optionColumns, true);

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
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>My Library | QuestionBox</title>

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
    overflow-wrap: anywhere;
}

label {
    display: block;
    margin: 12px 0 7px;
    color: var(--text);
}

input,
select {
    width: 100%;
    padding: 12px;
    border: 1px solid var(--border);
    border-radius: 9px;
    background: #0d0d14;
    color: var(--text);
    font-size: 15px;
}

input:focus,
select:focus {
    outline: none;
    border-color: var(--pink);
    box-shadow: 0 0 0 2px #ff4fa326;
}

.search-row {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 14px;
}

button,
.button {
    display: inline-block;
    margin: 12px 8px 0 0;
    padding: 12px 19px;
    border: none;
    border-radius: 9px;
    background: var(--pink);
    color: #1a0711;
    font-weight: bold;
    font-size: 14px;
    text-decoration: none;
    cursor: pointer;
    transition: background 0.2s ease;
}

button:hover,
.button:hover {
    background: var(--orange);
    text-decoration: none;
}

.secondary-button {
    background: var(--panel);
    color: var(--yellow);
    border: 1px solid var(--border);
}

.secondary-button:hover {
    background: #30202d;
}

.delete-button {
    background: #a82d4d;
    color: #fff;
}

.delete-button:hover {
    background: #d9365f;
}

.message {
    padding: 14px 16px;
    border-radius: 10px;
    margin: 18px 0;
    border: 1px solid var(--border);
}

.message.success {
    background: #163d30;
    color: #a7f3d0;
    border-color: #276749;
}

.question-card {
    padding: 22px 0;
    border-bottom: 1px solid var(--border);
}

.question-card:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.question-text {
    line-height: 1.8;
    overflow-wrap: anywhere;
    white-space: pre-wrap;
}

.topic-label {
    display: inline-block;
    background: #30202d;
    border: 1px solid #75415d;
    color: #ffb4d8;
    padding: 6px 11px;
    border-radius: 20px;
    font-size: 13px;
}

.question-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin: 12px 0 18px;
}

.question-actions form {
    margin: 0;
}

.question-actions .button,
.question-actions button {
    margin: 0;
}

.option {
    margin: 10px 0;
    padding: 12px 14px;
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 9px;
    color: #d5d5e2;
    overflow-wrap: anywhere;
}

.option.correct {
    background: #163d30;
    border-color: #276749;
    color: #a7f3d0;
}

.correct-label {
    color: #a7f3d0;
    font-size: 13px;
    font-weight: bold;
}

.explanation {
    margin-top: 16px;
    padding: 16px;
    background: #0d0d14;
    border: 1px solid var(--border);
    border-radius: 10px;
    color: #d5d5e2;
    overflow-wrap: anywhere;
    white-space: pre-wrap;
}

.meta {
    color: var(--muted);
    font-size: 13px;
    margin-top: 16px;
}

.empty-state {
    padding: 24px 0;
    text-align: center;
}

.results-count {
    margin-top: 20px;
    color: var(--muted);
    font-size: 14px;
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

    .search-row {
        grid-template-columns: 1fr;
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
    <p class="subtitle">
        Your personal question library. Create. Organize. Practise.
    </p>

    <p>
        Hello, <?= e($_SESSION["user_name"] ?? "User") ?>!
    </p>

    <nav class="navigation">
        <a href="dashboard.php">Dashboard</a>
        <a href="create_question.php">Create Question</a>
        <a href="library.php">My Library</a>
        <a href="practice.php">Practice Quiz</a>
        <a href="custom_test.php">Create Custom Test</a>
        <a href="history.php">Practice History</a>
        <a href="logout.php">Log Out</a>
    </nav>

    <?php if ($message !== ""): ?>
        <p class="message <?= e($messageType) ?>">
            <?= e($message) ?>
        </p>
    <?php endif; ?>

    <section class="card">
        <h2>My Saved Questions</h2>

        <p class="muted">
            Search your questions or filter them by topic.
        </p>

        <form method="GET" action="library.php">
            <div class="search-row">
                <div>
                    <label for="search">Search by question keyword</label>
                    <input
                        id="search"
                        name="search"
                        type="search"
                        placeholder="Type a keyword..."
                        value="<?= e($search) ?>"
                    >
                </div>

                <div>
                    <label for="topic_filter">Filter by topic</label>
                    <select id="topic_filter" name="topic">
                        <option value="">All Topics</option>

                        <?php foreach ($topics as $topic): ?>
                            <option
                                value="<?= e($topic) ?>"
                                <?= $topicFilter === $topic ? "selected" : "" ?>
                            >
                                <?= e($topic) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <button type="submit">Search / Filter</button>

            <a class="button secondary-button" href="library.php">
                Clear Filters
            </a>
        </form>

        <p class="results-count">
            <?= (int)$result->num_rows ?> matching question(s) found.
        </p>

        <?php if ($result->num_rows === 0): ?>

            <div class="empty-state">
                <h3>No Questions Found</h3>

                <p class="muted">
                    Try another keyword or topic, or create a new question.
                </p>

                <a class="button" href="create_question.php">
                    + Create Question
                </a>
            </div>

        <?php else: ?>

            <?php while ($row = $result->fetch_assoc()): ?>

                <?php
                $questionId = (int)$row["id"];
                $correctAnswer = strtoupper(
                    trim((string)($row["correct_answer"] ?? ""))
                );

                if (isset($dynamicOptions[$questionId])) {
                    $optionMap = $dynamicOptions[$questionId];
                } else {
                    $optionMap = [];

                    foreach (["A", "B", "C", "D"] as $letter) {
                        $field = "option_" . strtolower($letter);
                        $text = trim((string)($row[$field] ?? ""));

                        if ($text !== "") {
                            $optionMap[$letter] = $text;
                        }
                    }
                }
                ?>

                <article class="question-card">

                    <h3 class="question-text">
                        <?= nl2br(e($row["question"])) ?>
                    </h3>

                    <p>
                        <span class="topic-label">
                            Topic: <?= e($row["topic"] ?: "General") ?>
                        </span>
                    </p>

                    <div class="question-actions">
                        <a
                            class="button"
                            href="edit_question.php?id=<?= $questionId ?>"
                        >
                            Edit Question
                        </a>

                        <form
                            method="POST"
                            action="delete_question.php"
                            onsubmit="return confirm('Are you sure you want to delete this question? Its practice history may also be deleted.');"
                        >
                            <input
                                type="hidden"
                                name="question_id"
                                value="<?= $questionId ?>"
                            >

                            <button
                                type="submit"
                                class="delete-button"
                            >
                                Delete Question
                            </button>
                        </form>
                    </div>

                    <h3>Options</h3>

                    <?php if (empty($optionMap)): ?>

                        <p class="muted">
                            No option details are available for this question.
                        </p>

                    <?php else: ?>

                        <?php foreach ($optionMap as $letter => $optionText): ?>

                            <?php $isCorrect = ($letter === $correctAnswer); ?>

                            <div class="option <?= $isCorrect ? 'correct' : '' ?>">
                                <strong><?= e($letter) ?>.</strong>
                                <?= e($optionText) ?>

                                <?php if ($isCorrect): ?>
                                    <span class="correct-label">
                                        ✓ Correct Answer
                                    </span>
                                <?php endif; ?>
                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    <?php if (!empty($row["explanation"])): ?>
                        <div class="explanation">
                            <strong>Explanation</strong>
                            <p><?= nl2br(e($row["explanation"])) ?></p>
                        </div>
                    <?php endif; ?>

                    <p class="meta">
                        Created:
                        <?= e($row["created_at"] ?? "Not available") ?>
                    </p>

                </article>

            <?php endwhile; ?>

        <?php endif; ?>
    </section>

</div>
</body>
</html>

<?php
$stmt->close();
?>
