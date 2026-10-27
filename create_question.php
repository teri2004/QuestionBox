
<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$topics = ['DSA', 'Development', 'OOP', 'Aptitude', 'CS Fundamentals', 'General', 'Numerical'];
$error = '';

function h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function optionLabel(int $index): string {
    $label = '';
    while ($index >= 0) {
        $label = chr(($index % 26) + 65) . $label;
        $index = intdiv($index, 26) - 1;
    }
    return $label;
}

$questionText = '';
$explanation = '';
$topic = 'General';
$optionCount = 4;
$timerEnabled = isset($_POST['timer_enabled']);
$timerSeconds = isset($_POST['timer_seconds'])
    ? (int) $_POST['timer_seconds']
    : 60;
$options = array_fill(0, 15, '');
$correctAnswer = 'A';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $questionText = trim((string)($_POST['question'] ?? ''));
    $explanation = trim((string)($_POST['explanation'] ?? ''));
    $topic = trim((string)($_POST['topic'] ?? 'General'));
    $optionCount = (int)($_POST['option_count'] ?? 4);
    $timerEnabled = isset($_POST['timer_enabled']);
    $timerSeconds = (int)($_POST['timer_seconds'] ?? 60);
    $correctAnswer = strtoupper(trim((string)($_POST['correct_answer'] ?? 'A')));
    $postedOptions = $_POST['options'] ?? [];

    for ($i = 0; $i < 15; $i++) {
        $options[$i] = trim((string)($postedOptions[$i] ?? ''));
    }

    if ($questionText === '') {
        $error = 'Please enter your question.';
    } elseif ($optionCount < 2 || $optionCount > 15) {
        $error = 'Please choose between 2 and 15 options.';
    } elseif (!in_array($topic, $topics, true)) {
        $error = 'Please select a valid topic.';
    } elseif ($timerEnabled && ($timerSeconds < 5 || $timerSeconds > 3600)) {
        $error = 'Timer must be between 5 and 3600 seconds.';
    } else {
        $activeOptions = array_slice($options, 0, $optionCount);

        if (in_array('', $activeOptions, true)) {
            $error = 'Please fill in every visible option.';
        } elseif (
            count(array_unique(array_map('mb_strtolower', $activeOptions)))
            !== count($activeOptions)
        ) {
            $error = 'Options must be different from each other.';
        } elseif (
            !in_array(
                $correctAnswer,
                array_map('optionLabel', range(0, $optionCount - 1)),
                true
            )
        ) {
            $error = 'Please select a correct answer from the visible options.';
        }
    }

    if ($error === '') {
        $conn->begin_transaction();

        try {
            $optA = $options[0];
            $optB = $options[1];
            $optC = $options[2];
            $optD = $options[3];

            $timerFlag = $timerEnabled ? 1 : 0;
            $savedTimer = $timerEnabled ? $timerSeconds : 60;

            // PDF-related fields are no longer used.
            $stmt = $conn->prepare(
                'INSERT INTO questions
                (user_id, question, option_a, option_b, option_c, option_d,
                 correct_answer, explanation, topic, timer_enabled, timer_seconds)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            if (!$stmt) {
                throw new Exception(
                    'Could not prepare question save. Check that the questions table has topic, timer_enabled and timer_seconds columns.'
                );
            }

            $stmt->bind_param(
                'issssssssii',
                $userId,
                $questionText,
                $optA,
                $optB,
                $optC,
                $optD,
                $correctAnswer,
                $explanation,
                $topic,
                $timerFlag,
                $savedTimer
            );

            if (!$stmt->execute()) {
                throw new Exception('Could not save the question: ' . $stmt->error);
            }

            $questionId = (int)$conn->insert_id;
            $stmt->close();

            $optionStmt = $conn->prepare(
                'INSERT INTO question_options
                (question_id, option_label, option_text, is_correct)
                VALUES (?, ?, ?, ?)'
            );

            if (!$optionStmt) {
                throw new Exception(
                    'Could not prepare custom options save. Check the question_options table columns.'
                );
            }

            for ($i = 0; $i < $optionCount; $i++) {
                $label = optionLabel($i);
                $optionText = $options[$i];
                $isCorrect = ($label === $correctAnswer) ? 1 : 0;

                $optionStmt->bind_param(
                    'issi',
                    $questionId,
                    $label,
                    $optionText,
                    $isCorrect
                );

                if (!$optionStmt->execute()) {
                    throw new Exception(
                        'Could not save an option: ' . $optionStmt->error
                    );
                }
            }

            $optionStmt->close();
            $conn->commit();

            header('Location: library.php?saved=1');
            exit;

        } catch (Throwable $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Question | QuestionBox</title>

<style>
:root {
    --bg: #050505;
    --panel: #101010;
    --line: #333;
    --text: #f8f8f8;
    --muted: #b8b8b8;
    --pink: #ff6fae;
    --pinkdark: #d93683;
    --yellow: #fff3a6;
    --orange: #ffd0a8;
    --orangedark: #d97832;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    background: var(--bg);
    color: var(--text);
    font-family: Arial, Helvetica, sans-serif;
    line-height: 1.5;
}

a { color: inherit; text-decoration: none; }

.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 18px max(5%, calc((100% - 1120px)/2));
    border-bottom: 1px solid #292929;
    background: #080808;
}

.brand {
    font-size: 24px;
    font-weight: 900;
    background: linear-gradient(90deg, var(--pink), var(--orange), var(--yellow));
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
}

.nav {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    color: #ddd;
    font-size: 14px;
}

.nav a:hover { color: var(--pink); }

main {
    width: min(960px, 92%);
    margin: 34px auto 60px;
}

.hero {
    position: relative;
    overflow: hidden;
    border: 1px solid #44303a;
    border-radius: 20px;
    padding: 26px;
    background: linear-gradient(120deg, #191016, #17120d 65%, #17160e);
    margin-bottom: 22px;
}

.hero:after {
    content: '✿  ❀  ✿';
    position: absolute;
    right: 24px;
    top: 18px;
    color: var(--pink);
    font-size: 27px;
    letter-spacing: 7px;
    opacity: .9;
    pointer-events: none;
}

.flower-divider {
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--pink);
    font-size: 19px;
    margin: 13px 0 0;
}

.flower-divider:before,
.flower-divider:after {
    content: '';
    height: 1px;
    flex: 1;
    background: linear-gradient(90deg, transparent, #ff6fae88, transparent);
}

.brand-flower {
    display: inline-grid;
    place-items: center;
    width: 34px;
    height: 34px;
    margin-right: 7px;
    border: 1px solid #ff6fae66;
    border-radius: 50%;
    color: var(--pink);
    background: #ff6fae12;
}

.eyebrow {
    color: var(--pink);
    font-size: 12px;
    font-weight: bold;
    letter-spacing: 2px;
    text-transform: uppercase;
}

.hero h1 {
    margin: 8px 0;
    font-size: clamp(27px, 5vw, 40px);
}

.gradient {
    background: linear-gradient(90deg, var(--pink), var(--orange), var(--yellow));
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
}

.hero p, .muted { color: var(--muted); }

.panel {
    background: var(--panel);
    border: 1px solid #303030;
    border-radius: 18px;
    padding: 24px;
    margin-bottom: 20px;
}

.panel h2 {
    margin: 0 0 18px;
    font-size: 20px;
}

.grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
}

.field { margin-bottom: 17px; }

.field label, .label {
    display: block;
    font-weight: 700;
    margin-bottom: 7px;
}

.field input,
.field select,
.field textarea {
    width: 100%;
    padding: 12px 13px;
    border: 1px solid #414141;
    border-radius: 10px;
    background: #080808;
    color: var(--text);
    font: inherit;
    outline: none;
}

.field input:focus,
.field select:focus,
.field textarea:focus {
    border-color: var(--pink);
    box-shadow: 0 0 0 3px #ff6fae20;
}

.field textarea {
    min-height: 110px;
    resize: vertical;
}

.hint {
    font-size: 12px;
    color: #aaa;
    margin-top: 6px;
}

.options {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

.option-row {
    display: flex;
    align-items: center;
    gap: 9px;
}

.option-label {
    flex: 0 0 38px;
    height: 38px;
    display: grid;
    place-items: center;
    border-radius: 9px;
    background: #26141e;
    color: var(--pink);
    font-weight: bold;
}

.option-row input {
    min-width: 0;
    flex: 1;
    padding: 10px;
    border: 1px solid #414141;
    border-radius: 9px;
    background: #080808;
    color: white;
}

.option-row[hidden] { display: none; }

.timer-row {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    background: #17130e;
    border: 1px solid #4b3a2c;
    border-radius: 12px;
    padding: 14px;
}

.timer-row input[type=number] {
    width: 130px;
    padding: 10px;
    background: #080808;
    color: white;
    border: 1px solid #444;
    border-radius: 8px;
}

.check {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 700;
}

.check input {
    accent-color: var(--pink);
    width: 18px;
    height: 18px;
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 13px 19px;
    border: 0;
    border-radius: 11px;
    background: linear-gradient(90deg, var(--pink), var(--orange));
    color: #160b10;
    font-weight: 800;
    font-size: 15px;
    cursor: pointer;
}

.btn.secondary {
    background: #242424;
    color: white;
    border: 1px solid #444;
}

.btn:disabled {
    opacity: .65;
    cursor: wait;
}

.actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 20px;
}

.message {
    padding: 13px 15px;
    border-radius: 10px;
    margin-bottom: 18px;
}

.error {
    background: #35151e;
    border: 1px solid #9d3150;
    color: #ffdce7;
}

.small { font-size: 12px; color: #aaa; }

@media(max-width:650px) {
    .topbar {
        align-items: flex-start;
        flex-direction: column;
        padding: 16px 5%;
    }

    .grid, .options { grid-template-columns: 1fr; }
    .panel { padding: 17px; }
    .hero { padding: 21px; }
}
</style>
</head>

<body>
<header class="topbar">
    <a class="brand" href="dashboard.php">
        <span class="brand-flower">✿</span>QuestionBox
    </a>

    <nav class="nav">
        <a href="dashboard.php">Dashboard</a>
        <a href="library.php">My Library</a>
        <a href="practice.php">Practice</a>
        <a href="history.php">History</a>
        <a href="logout.php">Logout</a>
    </nav>
</header>

<main>
<section class="hero">
    <div class="eyebrow">✿ Question creator ✿</div>
    <h1>Create a <span class="gradient">new question</span></h1>
    <p>Add your question, choose between 2 and 15 options, select the correct answer, and optionally enable a timer.</p>
    <div class="flower-divider" aria-hidden="true">❀ ✿ ❀</div>
</section>

<?php if ($error !== ''): ?>
    <div class="message error"><?= h($error) ?></div>
<?php endif; ?>

<form method="post" id="questionForm">

<section class="panel">
    <h2>01 · Question details</h2>

    <div class="field">
        <label for="question">Question text</label>
        <textarea id="question" name="question" required
            placeholder="Type your question here..."><?= h($questionText) ?></textarea>
    </div>

    <div class="grid">
        <div class="field">
            <label for="topic">Topic</label>
            <select id="topic" name="topic">
                <?php foreach ($topics as $item): ?>
                    <option value="<?= h($item) ?>"
                        <?= $topic === $item ? 'selected' : '' ?>>
                        <?= h($item) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</section>

<section class="panel">
    <h2>02 · Answer options</h2>

    <div class="field">
        <label for="option_count">Number of options</label>
        <select id="option_count" name="option_count">
            <?php for ($n = 2; $n <= 15; $n++): ?>
                <option value="<?= $n ?>"
                    <?= $optionCount === $n ? 'selected' : '' ?>>
                    <?= $n ?> options
                </option>
            <?php endfor; ?>
        </select>
        <div class="hint">Choose 2–15 options. Only visible options are saved.</div>
    </div>

    <div class="options" id="optionsContainer">
        <?php for ($i = 0; $i < 15; $i++): ?>
            <div class="option-row" data-index="<?= $i ?>"
                <?= $i >= $optionCount ? 'hidden' : '' ?>>
                <span class="option-label"><?= h(optionLabel($i)) ?></span>
                <input type="text"
                    name="options[<?= $i ?>]"
                    value="<?= h($options[$i]) ?>"
                    placeholder="Option <?= h(optionLabel($i)) ?>"
                    <?= $i < $optionCount ? 'required' : 'disabled' ?>>
            </div>
        <?php endfor; ?>
    </div>

    <div class="field" style="margin-top:20px">
        <label for="correct_answer">Correct answer</label>
        <select id="correct_answer" name="correct_answer" required>
            <?php for ($i = 0; $i < $optionCount; $i++):
                $label = optionLabel($i); ?>
                <option value="<?= h($label) ?>"
                    <?= $correctAnswer === $label ? 'selected' : '' ?>>
                    Option <?= h($label) ?>
                </option>
            <?php endfor; ?>
        </select>
    </div>
</section>

<section class="panel">
    <h2>03 · Timer and explanation</h2>

    <div class="timer-row">
        <label class="check">
            <input type="checkbox" name="timer_enabled" id="timer_enabled"
                value="1" <?= $timerEnabled ? 'checked' : '' ?>>
            Enable question timer
        </label>

        <label for="timer_seconds">Seconds:</label>
        <input type="number" id="timer_seconds" name="timer_seconds"
            min="5" max="3600" value="<?= h($timerSeconds) ?>">

        <span class="small">5–3600 seconds</span>
    </div>

    <div class="field" style="margin-top:20px">
        <label for="explanation">Explanation (optional)</label>
        <textarea id="explanation" name="explanation"
            placeholder="Explain why the answer is correct..."><?= h($explanation) ?></textarea>
    </div>
</section>

<div class="actions">
    <button class="btn" type="submit" id="saveBtn">✦ Save Question</button>
    <button class="btn secondary" type="reset" id="clearBtn">Clear form</button>
    <a class="btn secondary" href="library.php">Cancel</a>
</div>

</form>
</main>

<script>
const countSelect = document.getElementById('option_count');
const rows = [...document.querySelectorAll('.option-row')];
const correctSelect = document.getElementById('correct_answer');

function labelFor(i) {
    let s = '';
    while (i >= 0) {
        s = String.fromCharCode(i % 26 + 65) + s;
        i = Math.floor(i / 26) - 1;
    }
    return s;
}

function updateOptions() {
    const count = Number(countSelect.value);

    rows.forEach((row, i) => {
        const input = row.querySelector('input');
        const visible = i < count;

        row.hidden = !visible;
        input.disabled = !visible;
        input.required = visible;
    });

    const old = correctSelect.value;
    correctSelect.innerHTML = '';

    for (let i = 0; i < count; i++) {
        const label = labelFor(i);
        const option = document.createElement('option');

        option.value = label;
        option.textContent = 'Option ' + label;
        correctSelect.appendChild(option);
    }

    correctSelect.value =
        old && [...correctSelect.options].some(o => o.value === old)
            ? old
            : 'A';
}

countSelect.addEventListener('change', updateOptions);
updateOptions();

document.getElementById('questionForm').addEventListener('submit', e => {
    const question = document.getElementById('question');

    if (!question.value.trim()) {
        e.preventDefault();
        alert('Please enter your question.');
        question.focus();
        return;
    }

    const count = Number(countSelect.value);

    for (let i = 0; i < count; i++) {
        const input = rows[i].querySelector('input');

        if (!input.value.trim()) {
            e.preventDefault();
            alert('Please fill in option ' + labelFor(i) + '.');
            input.focus();
            return;
        }
    }

    const activeOptions = rows
        .slice(0, count)
        .map(row => row.querySelector('input').value.trim().toLocaleLowerCase());

    if (new Set(activeOptions).size !== activeOptions.length) {
        e.preventDefault();
        alert('Options must be different from each other.');
        return;
    }

    const timer = document.getElementById('timer_enabled').checked;
    const seconds = Number(document.getElementById('timer_seconds').value);

    if (timer && (seconds < 5 || seconds > 3600)) {
        e.preventDefault();
        alert('Timer must be between 5 and 3600 seconds.');
        return;
    }

    const btn = document.getElementById('saveBtn');
    btn.disabled = true;
    btn.textContent = 'Saving...';
});

document.getElementById('clearBtn').addEventListener('click', () => {
    setTimeout(() => {
        countSelect.value = '4';
        document.getElementById('question').value = '';
        document.getElementById('topic').value = 'General';
        document.getElementById('explanation').value = '';
        document.getElementById('timer_enabled').checked = false;
        document.getElementById('timer_seconds').value = '60';

        rows.forEach(row => {
            row.querySelector('input').value = '';
        });

        correctSelect.value = 'A';
        updateOptions();

        const btn = document.getElementById('saveBtn');
        btn.disabled = false;
        btn.textContent = '✦ Save Question';
    }, 0);
});
</script>
</body>
</html>
