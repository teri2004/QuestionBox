
<?php
session_start();
require_once "config.php";

$message = "";
$messageType = "";

if (isset($_SESSION["user_id"])) {
    header("Location: dashboard.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name = trim($_POST["name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    if (
        $name === "" ||
        strlen($name) > 100 ||
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        strlen($email) > 150 ||
        strlen($password) < 8 ||
        strlen($password) > 72
    ) {
        $message = "Enter a valid name and email. Password must be 8–72 characters.";
        $messageType = "error";
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)"
        );

        if ($stmt === false) {
            error_log("QuestionBox registration query preparation failed: " . $conn->error);
            $message = "Registration failed. Please try again.";
            $messageType = "error";
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            if ($hashedPassword === false) {
                $message = "Could not secure your password. Please try again.";
                $messageType = "error";
            } else {
                $stmt->bind_param("sss", $name, $email, $hashedPassword);

                if ($stmt->execute()) {
                    header("Location: login.php?registered=1");
                    exit;
                } else {
                    if ($stmt->errno === 1062) {
                        $message = "This email is already registered. Please log in.";
                    } else {
                        error_log("QuestionBox registration failed: " . $stmt->error);
                        $message = "Registration failed. Please try again.";
                    }

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
    <title>Register | QuestionBox</title>

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
                radial-gradient(ellipse at top left, #24101e 0%, transparent 40%),
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
            margin: 0 0 26px;
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
            width: 100%;
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
            display: grid;
            place-items: center;
            background: transparent;
            color: #ffffff;
            border: none;
            border-radius: 8px;
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
            overflow-wrap: anywhere;
            padding: 12px 14px;
            border-radius: 10px;
            font-size: 14px;
            line-height: 1.5;
        }

        .success {
            background: #123327;
            color: #a7f3d0;
        }

        .error {
            background: #351923;
            border: 1px solid #713044;
            color: #ffc5d8;
        }

        .login-text {
            margin: 24px 0 0;
            color: #b8b6c5;
            text-align: center;
            font-size: 14px;
        }

        a {
            color: #ff78b5;
            font-weight: 700;
            text-decoration: none;
        }

        a:hover {
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
        <p class="subtitle">Create your free account and start practising.</p>

        <?php if ($message !== ""): ?>
            <p class="message <?= htmlspecialchars($messageType, ENT_QUOTES, "UTF-8") ?>">
                <?= htmlspecialchars($message, ENT_QUOTES, "UTF-8") ?>
            </p>
        <?php endif; ?>

        <form method="POST" action="register.php">
            <label for="name">Full Name</label>
            <input
                id="name"
                name="name"
                type="text"
                maxlength="100"
                autocomplete="name"
                placeholder="Enter your full name"
                value="<?= htmlspecialchars($_POST["name"] ?? "", ENT_QUOTES, "UTF-8") ?>"
                required
            >

            <label for="email">Email Address</label>
            <input
                id="email"
                name="email"
                type="email"
                maxlength="150"
                autocomplete="email"
                placeholder="Enter your email address"
                value="<?= htmlspecialchars($_POST["email"] ?? "", ENT_QUOTES, "UTF-8") ?>"
                required
            >

            <label for="password">Password (8–72 characters)</label>
            <div class="password-box">
                <input
                    id="password"
                    name="password"
                    type="password"
                    minlength="8"
                    maxlength="72"
                    autocomplete="new-password"
                    placeholder="Create a password"
                    required
                >

                <button
                    type="button"
                    id="togglePassword"
                    class="eye-button"
                    aria-label="Show password"
                    aria-pressed="false"
                    title="Show password"
                >👁️</button>
            </div>

            <button type="submit" class="submit-button">
                Create Account
            </button>
        </form>

        <p class="login-text">
            Already have an account?
            <a href="login.php">Log in</a>
        </p>
    </main>

    <script>
        const passwordInput = document.getElementById("password");
        const togglePassword = document.getElementById("togglePassword");

        togglePassword.addEventListener("click", function () {
            const isHidden = passwordInput.type === "password";

            passwordInput.type = isHidden ? "text" : "password";
            togglePassword.textContent = isHidden ? "🙈" : "👁️";

            togglePassword.setAttribute(
                "aria-label",
                isHidden ? "Hide password" : "Show password"
            );

            togglePassword.setAttribute(
                "title",
                isHidden ? "Hide password" : "Show password"
            );

            togglePassword.setAttribute("aria-pressed", String(isHidden));
        });
    </script>
</body>
</html>

