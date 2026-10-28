# Naffezly

A PHP + MySQL project marketplace for custom software requests and curated project listings.

## Requirements
- XAMPP with Apache + MySQL/MariaDB
- PHP 8+

## Local setup
1. Copy the project to `htdocs/naffezly`.
2. Start Apache and MySQL in XAMPP.
3. Visit `http://localhost/naffezly`.
4. The app auto-creates the database and tables on first run.
5. On a new database, create the first administrator from Terminal with `php bin/create-super-admin.php`. The password is requested interactively and is never stored in the source.
6. Existing administrator passwords can be reset interactively using `php bin/reset-admin-password.php`.
7. Configure SMTP credentials in environment variables before using email verification. Never commit an SMTP app password to source control.
8. To resend verification emails, open `http://localhost/naffezly/resend-verification.php`.
9. Project cover images can be uploaded from Admin → Projects as JPG, PNG, or WebP files up to 5 MB. The PHP/Apache account must be able to write to `uploads/projects`; uploaded files are restricted to image extensions and PHP execution is denied in the uploads folder.

## Project structure
- `index.php` — homepage
- `projects.php` — project catalog
- `project.php` — project details
- `request-project.php` — custom project request form
- `register.php` — user registration
- `login.php` — user login
- `account.php` — account management
- `orders.php` — purchase requests list
- `cart.php` — project cart and checkout
- `admin/` — admin dashboard

## Notes
- No payment gateway is included.
- Cart and purchase requests are treated as manual approval flows.
- New delete actions permanently remove the selected record. Deleting users or requests also permanently deletes linked requests, orders, messages, and payment records; project deletion preserves historical order-item snapshots. Items archived before this change remain available in the deleted-items views and can still be restored.
- The interface supports Arabic RTL and English LTR. Project titles, summaries, descriptions, and benefits have separate English and Arabic fields in Admin → Projects. Arabic project text is entered and reviewed manually; a blank Arabic field falls back to English. Existing sample projects are prefilled with Arabic. No third-party machine-translation service is used.
- Customers must sign in before submitting a cart order. Administrator accounts are created separately under Admin → Admins & roles, and are not listed with customer accounts. Each administrator can choose personal notification preferences; administrators with the relevant permission can also control event-wide recipient routing.
- Preferred payment method for custom requests is selected from a dropdown. No online payment or card details are collected.
- The Naffezly brand mark is used as the site favicon on public pages and admin pages.
- The app is designed to work in a shared hosting or XAMPP environment without Node.js.
