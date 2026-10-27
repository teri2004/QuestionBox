# QuestionBox 

QuestionBox is a web-based project built to make creating, organizing, and practicing questions easier. Users can create their own questions, organize them by topic, and practice at their own pace. They can also create custom tests by selecting questions from their saved library.

## Features

- Create an account and log in securely.
- Add, edit, and delete your own questions.
- Create multiple-choice questions with a flexible number of options.
- Organize and search questions by topic.
- Practice questions with a timer.
- Build custom tests using questions of your choice.
- Check correct answers and read explanations.
- Keep track of previous practice attempts.

## Technologies Used

- **Frontend:** HTML, CSS, JavaScript
- **Backend:** PHP
- **Database:** MySQL
- **Local Development:** XAMPP

## Getting Started

Follow these steps to run QuestionBox on your computer:

1. Install [XAMPP](https://www.apachefriends.org/).
2. Place the project folder inside `C:\xampp\htdocs\questionbox`.
3. Open the XAMPP Control Panel and start Apache and MySQL.
4. Open phpMyAdmin and create a database named `questionbox`.
5. Configure your local database connection in `config.php`.
6. Open `http://localhost/questionbox/` in your browser.

Make sure the required database tables are created before using the application.

## Database Configuration

The `config.example.php` file provides a sample database configuration. Set up your own `config.php` with the correct database details for your environment.

**Security note:** Never upload your real database credentials, passwords, or private user data to a public repository.

## Project Status

QuestionBox is currently under development. More improvements and features may be added as the project evolves.

## License

No license has been selected for this project yet.
