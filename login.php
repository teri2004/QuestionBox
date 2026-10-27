

<?php
session_start();
require_once "config.php";

$message = "";
$messageType = "";

// Redirect users who are already logged in.
if (isset($_SESSION["user_id"])) {
    header("Location: dashboard.php");
    exit;
}

// Process login form.
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($email === "" || $password === "") {
        $message = "Please enter your email and password.";
        $messageType = "error";
    } else {
        $stmt = $conn->prepare(
            "SELECT id, name, email, password
             FROM users
             WHERE email = ?
             LIMIT 1"
        );

        if (!$stmt) {
            error_log(
                "QuestionBox login query preparation failed: " . $conn->error
            );

            $message = "Something went wrong. Please try again.";
            $messageType = "error";
        } else {
            $stmt->bind_param("s", $email);

            if (!$stmt->execute()) {
                error_log(
                    "QuestionBox login query execution failed: " . $stmt->error
                );

                $message = "Something went wrong. Please try again.";
                $messageType = "error";
            } else {
                $result = $stmt->get_result();
                $user = $result->fetch_assoc();

                if (
                    $user &&
                    password_verify($password, $user["password"])
                ) {
                    session_regenerate_id(true);

                    $_SESSION["user_id"] = (int) $user["id"];
                    $_SESSION["user_name"] = $user["name"];
                    $_SESSION["user_email"] = $user["email"];

                    $stmt->close();

                    header("Location: dashboard.php");
                    exit;
                } else {
                    $message = "Invalid email or password.";
                    $messageType = "error";
                }
            }

            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Login | QuestionBox</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    min-height: 100vh;
    display: grid;
    place-items: center;
    padding: 20px;
    background:
        radial-gradient(
            ellipse at top left,
            #24101e 0%,
            transparent 40%
        ),
        #09090d;
    color: #f8fafc;
    font-family: Arial, Helvetica, sans-serif;
}

.card {
    width: 100%;
    max-width: 484px;
    padding: 36px;
    background: #16151d;
    border: 1px solid #36333f;
    border-radius: 22px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
}

h1 {
    margin: 0 0 14px;
    font-size: 34px;
    font-weight: 800;
    letter-spacing: -1px;
    background: linear-gradient(90deg, #ff4fa3, #ff963c);
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
}

.subtitle {
    margin: 0 0 28px;
    color: #b8b6c5;
    font-size: 16px;
    line-height: 1.6;
}

label {
    display: block;
    margin: 18px 0 8px;
    color: #f4f2f8;
    font-size: 14px;
    font-weight: 700;
}

input {
    display: block;
    width: 100%;
    min-width: 0;
    padding: 14px 15px;
    border: 1px solid #41404d;
    border-radius: 10px;
    background: #20202a;
    color: #ffffff;
    font-size: 15px;
}

input:focus {
    outline: none;
    border-color: #ff4fa3;
    box-shadow: 0 0 0 3px rgba(255, 79, 163, 0.12);
}

.password-box {
    position: relative;
}

.password-box input {
    padding-right: 50px;
}

.eye-button {
    position: absolute;
    top: 50%;
    right: 8px;
    transform: translateY(-50%);
    width: 36px;
    height: 36px;
    margin: 0;
    padding: 0;
    border: none;
    border-radius: 8px;
    background: transparent;
    color: white;
    font-size: 18px;
    cursor: pointer;
}

.eye-button:hover {
    background: #34313e;
}

.submit-button {
    width: 100%;
    margin-top: 26px;
    padding: 14px;
    border: none;
    border-radius: 10px;
    background: linear-gradient(90deg, #ff4fa3, #ff963c);
    color: #160c13;
    font-size: 16px;
    font-weight: 800;
    cursor: pointer;
    transition: opacity 0.2s, transform 0.2s;
}

.submit-button:hover {
    opacity: 0.92;
    transform: translateY(-1px);
}

.message {
    padding: 12px 14px;
    border-radius: 10px;
    font-size: 14px;
    line-height: 1.5;
}

.message.error {
    border: 1px solid #713044;
    background: #351923;
    color: #ffc5d8;
}

.register-text {
    margin: 24px 0 0;
    color: #b8b6c5;
    text-align: center;
    font-size: 14px;
}

.register-link {
    color: #ff78b5;
    font-weight: 700;
    text-decoration: none;
}

.register-link:hover {
    text-decoration: underline;
}

@media (max-width: 480px) {
    .card {
        padding: 26px 22px;
    }

    h1 {
        font-size: 30px;
    }
}
</style>
</head>

<body>
<main class="card">

    <h1>QuestionBox</h1>

    <p class="subtitle">
        Welcome back! Log in to continue your practice. ✿
    </p>

    <?php if ($message !== ""): ?>
        <p
            class="message <?= htmlspecialchars($messageType, ENT_QUOTES, "UTF-8") ?>"
            role="status"
        >
            <?= htmlspecialchars($message, ENT_QUOTES, "UTF-8") ?>
        </p>
    <?php endif; ?>

    <form method="POST" action="login.php">

        <label for="email">Email Address</label>

        <input
            id="email"
            name="email"
            type="email"
            placeholder="Enter your email address"
            autocomplete="username"
            value="<?= htmlspecialchars($_POST["email"] ?? "", ENT_QUOTES, "UTF-8") ?>"
            required
        >

        <label for="password">Password</label>

        <div class="password-box">
            <input
                id="password"
                name="password"
                type="password"
                placeholder="Enter your password"
                autocomplete="current-password"
                required
            >

            <button
                type="button"
                id="togglePassword"
                class="eye-button"
                aria-label="Show password"
                title="Show password"
            >👁️</button>
        </div>

        <button type="submit" class="submit-button">
            Log In
        </button>

    </form>

    <p class="register-text">
        Don't have an account?
        <a class="register-link" href="register.php">
            Register here
        </a>
    </p>

</main>

<script>
const passwordInput = document.getElementById("password");
const toggleButton = document.getElementById("togglePassword");

toggleButton.addEventListener("click", function () {
    const isHidden = passwordInput.type === "password";

    passwordInput.type = isHidden ? "text" : "password";
    toggleButton.textContent = isHidden ? "🙈" : "👁️";

    toggleButton.setAttribute(
        "aria-label",
        isHidden ? "Hide password" : "Show password"
    );

    toggleButton.title = isHidden ? "Hide password" : "Show password";
});
</script>

</body>
</html>
