<?php
/*
 * QuestionBox example database configuration.
 * This file contains placeholder credentials for GitHub.
 */

$host = "localhost";
$username = "YOUR_DATABASE_USERNAME";
$password = "YOUR_DATABASE_PASSWORD";
$database = "questionbox";

mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    error_log("QuestionBox database connection failed.");
    http_response_code(500);
    exit("Database connection failed. Check your database configuration.");
}

if (!$conn->set_charset("utf8mb4")) {
    error_log("QuestionBox failed to set the database character set.");
}
?>