
<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$userId = (int) $_SESSION["user_id"];
$id = (int) ($_GET["id"] ?? 0);

if ($id <= 0) {
    header("Location: library.php");
    exit;
}

$message = "";

$topics = [
    "DSA",
    "Development",
    "OOP",
    "Aptitude",
    "CS Fundamentals",
    "General"
];

$letters = range("A", "O");

function e($value) {
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

// --------------------------------------
// Load the question owned by this user
// --------------------------------------

$stmt = $conn->prepare(
    "SELECT question, option_a, option_b, option_c, option_d,
            correct_answer, explanation, topic,
            timer_enabled, timer_seconds
     FROM questions
     WHERE id = ? AND user_id = ?"
);

$stmt->bind_param("ii", $id, $userId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    http_response_code(404);
    exit("Question not found or you do not have permission to edit it.");
}

// --------------------------------------
// Load dynamic options A–O
// --------------------------------------

$optionTexts = [];

$stmt = $conn->prepare(
    "SELECT option_label, option_text
     FROM question_options
     WHERE question_id = ?
     ORDER BY id ASC"
);

$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

while ($option = $result->fetch_assoc()) {
    $optionTexts[] = $option["option_text"];
}

$stmt->close();

// For older questions without dynamic options,
// use the legacy A–D columns.
if (count($optionTexts) === 0) {
    foreach (["a", "b", "c", "d"] as $letter) {
        $optionTexts[] = $row["option_" . $letter];
    }
}

$correctAnswer = $row["correct_answer"] ?? "A";
$timerEnabled = (int) ($row["timer_enabled"] ?? 0);
$timerSeconds = (int) ($row["timer_seconds"] ?? 60);

if ($timerSeconds < 5 || $timerSeconds > 3600) {
    $timerSeconds = 60;
}

// --------------------------------------
// Save edited question
// --------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $question = trim($_POST["question"] ?? "");
    $topic = $_POST["topic"] ?? "General";
    $explanation = trim($_POST["explanation"] ?? "");
    $correctAnswer = $_POST["correct_answer"] ?? "";

    $postedOptions = $_POST["options"] ?? [];

    if (!is_array($postedOptions)) {
        $postedOptions = [];
    }

    // Keep only text values and trim them.
    $optionTexts = [];

    foreach ($postedOptions as $optionText) {
        $optionTexts[] = is_string($optionText)
            ? trim($optionText)
            : "";
    }

    $timerEnabled = isset($_POST["timer_enabled"]) ? 1 : 0;

    $postedSeconds = $_POST["timer_seconds"] ?? "";

    $timerSeconds = filter_var(
        $postedSeconds,
        FILTER_VALIDATE_INT
    );

    $valid = true;

    if ($question === "") {
        $message = "Please enter the question.";
        $valid = false;
    } elseif (!in_array($topic, $topics, true)) {
        $message = "Please select a valid topic.";
        $valid = false;
    } elseif (
        count($optionTexts) < 2 ||
        count($optionTexts) > 15
    ) {
        $message = "A question must have between 2 and 15 options.";
        $valid = false;
    } else {
        foreach ($optionTexts as $optionText) {
            if ($optionText === "" || strlen($optionText) > 2000) {
                $message = "Every option must contain text and be no longer than 2,000 characters.";
                $valid = false;
                break;
            }
        }
    }

    $activeLetters = array_slice(
        $letters,
        0,
        count($optionTexts)
    );

    if (
        $valid &&
        !in_array($correctAnswer, $activeLetters, true)
    ) {
        $message = "Please select a correct answer from your current options.";
        $valid = false;
    }

    if (
        $valid &&
        (
            $timerSeconds === false ||
            $timerSeconds < 5 ||
            $timerSeconds > 3600
        )
    ) {
        $message = "Timer duration must be between 5 and 3,600 seconds.";
        $valid = false;
    }

    // Legacy A–D columns remain synchronized for compatibility.
    $legacyOptions = [];

    for ($i = 0; $i < 4; $i++) {
        $legacyOptions[$i] = $optionTexts[$i] ?? "";
    }

    if ($valid) {
        try {
            $conn->begin_transaction();

            // Update the main question and timer.
            $stmt = $conn->prepare(
                "UPDATE questions
                 SET question = ?,
                     option_a = ?,
                     option_b = ?,
                     option_c = ?,
                     option_d = ?,
                     correct_answer = ?,
                     explanation = ?,
                     topic = ?,
                     timer_enabled = ?,
                     timer_seconds = ?
                 WHERE id = ? AND user_id = ?"
            );

            $stmt->bind_param(
                "ssssssssiiii",
                $question,
                $legacyOptions[0],
                $legacyOptions[1],
                $legacyOptions[2],
                $legacyOptions[3],
                $correctAnswer,
                $explanation,
                $topic,
                $timerEnabled,
                $timerSeconds,
                $id,
                $userId
            );

            if (!$stmt->execute()) {
                throw new Exception("Could not update the question.");
            }

            $stmt->close();

            // Replace the saved dynamic options.
            $stmt = $conn->prepare(
                "DELETE FROM question_options
                 WHERE question_id = ?"
            );

            $stmt->bind_param("i", $id);

            if (!$stmt->execute()) {
                throw new Exception("Could not replace the options.");
            }

            $stmt->close();

            // Insert options A–O and mark the correct answer.
            $stmt = $conn->prepare(
                "INSERT INTO question_options
                    (question_id, option_label, option_text, is_correct)
                 VALUES (?, ?, ?, ?)"
            );

            foreach ($optionTexts as $index => $optionText) {
                $label = $letters[$index];
                $isCorrect = ($label === $correctAnswer) ? 1 : 0;

                $stmt->bind_param(
                    "issi",
                    $id,
                    $label,
                    $optionText,
                    $isCorrect
                );

                if (!$stmt->execute()) {
                    throw new Exception("Could not save an option.");
                }
            }

            $stmt->close();
            $conn->commit();

            header("Location: library.php?updated=1");
            exit;

        } catch (Throwable $exception) {
            $conn->rollback();

            $message = "Unable to save changes. Please check your database tables and try again.";

            error_log(
                "QuestionBox edit error: " . $exception->getMessage()
            );
        }
    }

    // Preserve form values if validation or saving fails.
    $row["question"] = $question;
    $row["topic"] = $topic;
    $row["explanation"] = $explanation;
}

// Ensure the form always has at least two editable options.
if (count($optionTexts) < 2) {
    $optionTexts = array_pad($optionTexts, 2, "");
}

if (count($optionTexts) > 15) {
    $optionTexts = array_slice($optionTexts, 0, 15);
}

$activeLetters = array_slice($letters, 0, count($optionTexts));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Edit Question | QuestionBox</title>

<style>
:root {
    --bg: #08090d;
    --card: #15151d;
    --panel: #20202a;
    --pink: #ff4fa3;
    --orange: #ff963c;
    --yellow: #ffd166;
    --white: #f5f5f7;
    --muted: #a1a1b0;
    --border: #383844;
    --danger: #ff6b6b;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 24px;
    background: var(--bg);
    color: var(--white);
    font-family: Arial, Helvetica, sans-serif;
    line-height: 1.6;
}

.container {
    max-width: 850px;
    margin: 0 auto;
}

.navbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 30px;
    padding-bottom: 18px;
    border-bottom: 1px solid var(--border);
}

.logo {
    font-size: 25px;
    font-weight: 800;
    text-decoration: none;
    background: linear-gradient(90deg, var(--pink), var(--orange), var(--yellow));
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
}

.nav-links {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
}

.nav-links a,
.back-link {
    color: var(--muted);
    text-decoration: none;
    font-size: 14px;
}

.nav-links a:hover,
.back-link:hover {
    color: var(--pink);
}

.card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 30px;
    box-shadow: 0 12px 35px rgba(0, 0, 0, 0.22);
}

.eyebrow {
    color: var(--pink);
    font-size: 12px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 2px;
}

h1 {
    margin: 6px 0 8px;
    font-size: clamp(27px, 5vw, 36px);
    line-height: 1.25;
}

h1 span {
    color: var(--orange);
}

.subtitle {
    color: var(--muted);
    margin-top: 0;
    margin-bottom: 25px;
}

.notice {
    padding: 13px 16px;
    border-radius: 10px;
    margin-bottom: 20px;
    background: rgba(255, 107, 107, 0.12);
    border: 1px solid rgba(255, 107, 107, 0.35);
    color: #ffb4b4;
}

.form-section {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 13px;
    padding: 20px;
    margin-bottom: 20px;
}

.form-section h2 {
    margin: 0 0 16px;
    font-size: 17px;
    color: var(--yellow);
}

label {
    display: block;
    margin: 15px 0 7px;
    font-size: 14px;
    font-weight: bold;
}

.required {
    color: var(--pink);
}

input,
textarea,
select {
    display: block;
    width: 100%;
    padding: 13px 14px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: #101017;
    color: var(--white);
    font-size: 15px;
    font-family: inherit;
    outline: none;
}

input:focus,
textarea:focus,
select:focus {
    border-color: var(--pink);
    box-shadow: 0 0 0 3px rgba(255, 79, 163, 0.12);
}

textarea {
    min-height: 110px;
    resize: vertical;
}

select {
    cursor: pointer;
}

.options-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 5px 18px;
}

.option-label {
    display: flex;
    align-items: center;
    gap: 8px;
}

.option-letter {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 27px;
    height: 27px;
    flex-shrink: 0;
    border-radius: 8px;
    color: var(--pink);
    background: rgba(255, 79, 163, 0.12);
}

.option-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 16px;
}

.small-btn {
    width: auto;
    margin: 0;
    padding: 9px 13px;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: #101017;
    color: var(--white);
    cursor: pointer;
    font-weight: bold;
}

.small-btn:hover {
    border-color: var(--pink);
}

.timer-box {
    border: 1px solid var(--border);
    border-radius: 11px;
    padding: 16px;
    margin-top: 15px;
    background: #101017;
}

.timer-toggle {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0;
}

.timer-toggle input {
    width: 18px;
    height: 18px;
    accent-color: var(--pink);
}

.timer-toggle label {
    margin: 0;
    cursor: pointer;
}

.hint {
    margin-top: 7px;
    color: var(--muted);
    font-size: 12px;
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 25px;
}

button,
.cancel-btn {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    min-height: 46px;
    padding: 12px 20px;
    border-radius: 10px;
    font-weight: bold;
    font-size: 14px;
    text-decoration: none;
    cursor: pointer;
}

.save-btn {
    border: none;
    color: #190b12;
    background: linear-gradient(90deg, var(--pink), var(--orange));
}

.cancel-btn {
    border: 1px solid var(--border);
    color: var(--white);
    background: transparent;
}

.footer-note {
    text-align: center;
    color: var(--muted);
    font-size: 12px;
    margin-top: 22px;
}

@media (max-width: 600px) {
    body {
        padding: 15px;
    }

    .card {
        padding: 18px;
    }

    .form-section {
        padding: 15px;
    }

    .options-grid {
        grid-template-columns: 1fr;
    }

    .actions {
        flex-direction: column;
    }

    .save-btn,
    .cancel-btn {
        width: 100%;
    }
}
</style>
</head>

<body>
<div class="container">

    <nav class="navbar">
        <a href="dashboard.php" class="logo">QuestionBox</a>

        <div class="nav-links">
            <a href="dashboard.php">Dashboard</a>
            <a href="create_question.php">Create</a>
            <a href="library.php">My Library</a>
            <a href="practice.php">Practice</a>
            <a href="history.php">History</a>
            <a href="logout.php">Logout</a>
        </div>
    </nav>

    <main class="card">
        <a href="library.php" class="back-link">
            &larr; Back to My Library
        </a>

        <p class="eyebrow">Question Management</p>
        <h1>Edit <span>Question</span></h1>

        <p class="subtitle">
            Update your question, add or remove options, and customize its timer.
        </p>

        <?php if ($message !== ""): ?>
            <div class="notice" role="alert"><?= e($message) ?></div>
        <?php endif; ?>

        <form method="POST" action="edit_question.php?id=<?= $id ?>">

            <section class="form-section">
                <h2>01 — Question Details</h2>

                <label for="question">
                    Question <span class="required">*</span>
                </label>

                <textarea id="question" name="question"
                          maxlength="5000" required><?= e($row["question"]) ?></textarea>

                <label for="topic">
                    Topic <span class="required">*</span>
                </label>

                <select id="topic" name="topic" required>
                    <?php foreach ($topics as $t): ?>
                        <option value="<?= e($t) ?>"
                            <?= ($row["topic"] ?? "General") === $t
                                ? "selected" : "" ?>>
                            <?= e($t) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </section>

            <section class="form-section">
                <h2>02 — Answer Options</h2>

                <p class="hint">
                    Keep 2–15 options. Labels A–O are assigned automatically.
                </p>

                <div class="options-grid" id="optionsGrid">
                    <?php foreach ($optionTexts as $index => $optionText): ?>
                        <div class="option-item">
                            <label class="option-label"
                                   for="option_<?= $index ?>">
                                <span class="option-letter">
                                    <?= e($letters[$index]) ?>
                                </span>
                                Option <?= e($letters[$index]) ?>
                                <span class="required">*</span>
                            </label>

                            <input
                                type="text"
                                id="option_<?= $index ?>"
                                name="options[]"
                                maxlength="2000"
                                required
                                value="<?= e($optionText) ?>"
                                placeholder="Enter option <?= e($letters[$index]) ?>">
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="option-actions">
                    <button type="button" class="small-btn" id="addOption">
                        + Add Option
                    </button>

                    <button type="button" class="small-btn" id="removeOption">
                        − Remove Last Option
                    </button>
                </div>

                <label for="correct_answer">
                    Correct Answer <span class="required">*</span>
                </label>

                <select id="correct_answer" name="correct_answer" required>
                    <?php foreach ($activeLetters as $letter): ?>
                        <option value="<?= e($letter) ?>"
                            <?= $correctAnswer === $letter
                                ? "selected" : "" ?>>
                            Option <?= e($letter) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </section>

            <section class="form-section">
                <h2>03 — Question Timer</h2>

                <div class="timer-box">
                    <div class="timer-toggle">
                        <input type="checkbox"
                               id="timer_enabled"
                               name="timer_enabled"
                               value="1"
                               <?= $timerEnabled ? "checked" : "" ?>>

                        <label for="timer_enabled">
                            Enable timer for this question
                        </label>
                    </div>

                    <label for="timer_seconds">
                        Timer Duration (seconds)
                    </label>

                    <input type="number"
                           id="timer_seconds"
                           name="timer_seconds"
                           min="5"
                           max="3600"
                           step="1"
                           value="<?= e($timerSeconds) ?>"
                           required>

                    <p class="hint">
                        Minimum: 5 seconds. Maximum: 3,600 seconds (60 minutes).
                        This setting belongs to this question only.
                    </p>
                </div>
            </section>

            <section class="form-section">
                <h2>04 — Explanation</h2>

                <label for="explanation">
                    Explanation (optional)
                </label>

                <textarea id="explanation" name="explanation"
                          maxlength="5000"
                          placeholder="Explain why the answer is correct..."><?= e($row["explanation"] ?? "") ?></textarea>
            </section>

            <div class="actions">
                <button type="submit" class="save-btn">Save Changes</button>
                <a href="library.php" class="cancel-btn">Cancel</a>
            </div>

        </form>
    </main>

    <p class="footer-note">
        QuestionBox &bull; Keep learning, keep improving.
    </p>
</div>

<script>
const letters = "ABCDEFGHIJKLMNO";
const optionsGrid = document.getElementById("optionsGrid");
const correctSelect = document.getElementById("correct_answer");
const addButton = document.getElementById("addOption");
const removeButton = document.getElementById("removeOption");

function updateOptions() {
    const items = [...optionsGrid.querySelectorAll(".option-item")];
    const selectedAnswer = correctSelect.value;

    items.forEach((item, index) => {
        const letter = letters[index];
        const label = item.querySelector(".option-label");
        const input = item.querySelector("input");

        label.htmlFor = "option_" + index;
        label.querySelector(".option-letter").textContent = letter;
        label.lastChild.textContent = " Option " + letter + " ";

        input.id = "option_" + index;
        input.placeholder = "Enter option " + letter;
    });

    correctSelect.innerHTML = "";

    items.forEach((item, index) => {
        const letter = letters[index];
        const option = document.createElement("option");

        option.value = letter;
        option.textContent = "Option " + letter;

        if (letter === selectedAnswer) {
            option.selected = true;
        }

        correctSelect.appendChild(option);
    });

    if (![...correctSelect.options].some(
        option => option.value === selectedAnswer
    )) {
        correctSelect.selectedIndex = 0;
    }

    addButton.disabled = items.length >= 15;
    removeButton.disabled = items.length <= 2;

    addButton.style.opacity = addButton.disabled ? "0.45" : "1";
    removeButton.style.opacity = removeButton.disabled ? "0.45" : "1";
}

addButton.addEventListener("click", function () {
    const items = optionsGrid.querySelectorAll(".option-item");

    if (items.length >= 15) {
        return;
    }

    const index = items.length;
    const letter = letters[index];

    const wrapper = document.createElement("div");
    wrapper.className = "option-item";

    const label = document.createElement("label");
    label.className = "option-label";
    label.htmlFor = "option_" + index;

    const badge = document.createElement("span");
    badge.className = "option-letter";
    badge.textContent = letter;

    label.appendChild(badge);
    label.appendChild(document.createTextNode(" Option " + letter + " "));

    const required = document.createElement("span");
    required.className = "required";
    required.textContent = "*";
    label.appendChild(required);

    const input = document.createElement("input");
    input.type = "text";
    input.name = "options[]";
    input.id = "option_" + index;
    input.maxLength = 2000;
    input.required = true;
    input.placeholder = "Enter option " + letter;

    wrapper.appendChild(label);
    wrapper.appendChild(input);
    optionsGrid.appendChild(wrapper);

    updateOptions();
    input.focus();
});

removeButton.addEventListener("click", function () {
    const items = optionsGrid.querySelectorAll(".option-item");

    if (items.length <= 2) {
        return;
    }

    const lastItem = items[items.length - 1];
    const lastInput = lastItem.querySelector("input");
    const removedLetter = letters[items.length - 1];

    if (
        correctSelect.value === removedLetter &&
        lastInput.value.trim() !== ""
    ) {
        correctSelect.selectedIndex = 0;
    }

    lastItem.remove();
    updateOptions();
});

const timerCheckbox = document.getElementById("timer_enabled");
const timerInput = document.getElementById("timer_seconds");

function updateTimerInput() {
    timerInput.disabled = !timerCheckbox.checked;
    timerInput.style.opacity = timerInput.disabled ? "0.5" : "1";
}

timerCheckbox.addEventListener("change", updateTimerInput);

updateOptions();
updateTimerInput();
</script>

</body>
</html>

