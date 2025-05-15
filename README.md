# Digital-Wallet
A PHP-based digital wallet system with admin and user roles, balance management, MPIN authentication, and transaction history tracking. This repository includes SQL files for database setup and sample data for testing.

📦 Features

Admin and User roles

Wallet balance management

MPIN authentication for secure transactions

Transaction history tracking

Sample user data for testing

SQL file for database setup

🛠️ Installation

Clone the repository:

git clone <repository_url>

Move to the project directory:

cd digital-wallet

Set up the database:

Open phpMyAdmin and create a new database named digital_wallet.

Import the digital_wallet.sql file located in the database folder.

Configure the Database Connection:

Open the config.php file and set your database credentials. Default configuration:

$host = 'localhost';
$user = 'root';
$password = 'YOUR_PASSWORD_HERE';
$database = 'digital_wallet';

Start the Server:

If using XAMPP, start Apache and MySQL.

Open the project in your browser:

http://localhost/digital-wallet

🔐 Test Accounts

Admin:

Username: admin

Password: admin

User:

Username: user

Password: user

📂 SQL File

The SQL file digital_wallet.sql contains the database schema and sample data for testing.

It is located in the /database folder.

🤝 Contributing

Feel free to fork the repository and submit pull requests. Ensure to provide clear commit messages and proper documentation.

📄 License

This project is licensed under the MIT License. See the LICENSE file for more information.

