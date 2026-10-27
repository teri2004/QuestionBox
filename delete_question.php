
<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: library.php");
    exit;
}

$userId = (int) $_SESSION["user_id"];
$questionId = (int) ($_POST["question_id"] ?? 0);

if ($questionId <= 0) {
    header("Location: library.php");
    exit;
}

$stmt = $conn->prepare(
    "DELETE FROM questions WHERE id = ? AND user_id = ?"
);
$stmt->bind_param("ii", $questionId, $userId);

if ($stmt->execute()) {
    $stmt->close();
    header("Location: library.php?deleted=1");
    exit;
}

$stmt->close();
header("Location: library.php");
exit;
