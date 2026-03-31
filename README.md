<<<<<<< HEAD
# WMSU Thesis Repository System (WTRS)

## Project organization

- `index.php` - root entry to start the app (redirects to login)
- `index.html` - marketing home page static design
- `auth/` - authentication endpoints: `login.php`, `register.php`, `logout.php`, plus static pages
- `admin/` - admin dashboard endpoints: `users.php`, `users.html`
- `student/` - student dashboard endpoints: `index.php` (requires login), `...` (remaining pages)
- `includes/` - shared backend utilities: `db.php`, `session.php`
- `assets/` - CSS, JS, images
- `database/wtrs_schema.sql` - schema + seed data

## Run locally (XAMPP)

1. Start Apache and MySQL in XAMPP.
2. Import schema: `mysql -u root < database/wtrs_schema.sql` (or run the provided SQL commands).
3. Open `http://localhost/wtrs/`.
4. Login as admin: `admin@wmsu.edu.ph` / `password`.

## Important notes

- `auth/login.php` is the active login logic. `auth/login.html` is static UI fallback.
- `admin/users.php` is dynamic user management with the activate/deactivate workflow.
- For production, set strong admin password and disable default seed credentials.

## Suggested next steps

- Add an `/auth/verify.php` route and email verification flow.
- Add `admin/users.php` search + pagination via query parameters.
- Add password reset and profile editing.
=======
# WTRS-Project
WMSU Thesis Repository System 
>>>>>>> e3e8fff93bffcc06b3c16722d26d25572e2e9a99
