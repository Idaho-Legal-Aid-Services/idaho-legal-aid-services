# ILAS Drupal Website

This repository contains the ILAS (Idaho Legal Aid Services) Drupal website codebase.

## Project Overview

This is a Drupal-based website built with:
- **Drupal Core**: Latest version
- **Custom Theme**: B5 Subtheme (Bootstrap 5 based)
- **Custom Modules**: Located in `/web/modules/custom/`
- **Contributed Modules**: Various Drupal community modules

## Local Development Setup

### Prerequisites
- PHP 8.1 or higher
- MySQL/MariaDB
- Composer
- Git
- Web server (Apache/Nginx)
- Drush (Drupal CLI)

### Installation

1. Clone the repository:
   ```bash
   git clone https://github.com/YOUR_USERNAME/ilas-drupal.git
   cd ilas-drupal
   ```

2. Install dependencies:
   ```bash
   composer install
   ```

3. Create settings file:
   ```bash
   cp web/sites/default/default.settings.php web/sites/default/settings.php
   ```

4. Configure your database settings in `settings.php`

5. Import the database (if you have a backup):
   ```bash
   drush sql-cli < backup.sql
   ```

6. Clear caches:
   ```bash
   drush cr
   ```

## Backup Strategy

This project uses a dual backup approach:

### 1. Git Version Control
- All code changes are tracked in this repository
- Custom themes and modules are version controlled
- Configuration changes are tracked

### 2. Full Site Backups
- Use the `backup-drupal.sh` script for complete backups
- Creates backups of:
  - Database
  - Files directory
  - Custom code
  - Full site archive
- Backups are stored in `/web/backups/`

To run a backup:
```bash
cd web
./backup-drupal.sh
```

## Project Structure

```
ilas/
├── web/                    # Drupal web root
│   ├── core/              # Drupal core (do not modify)
│   ├── modules/           
│   │   ├── contrib/       # Contributed modules
│   │   └── custom/        # Custom modules
│   ├── themes/
│   │   ├── contrib/       # Contributed themes
│   │   └── custom/        # Custom themes
│   │       └── b5subtheme/  # Main custom theme
│   ├── sites/
│   │   └── default/
│   │       ├── files/     # User uploads (not in git)
│   │       └── settings.php # Site settings (not in git)
│   └── backup-drupal.sh   # Backup script
├── vendor/                # Composer dependencies (not in git)
├── composer.json          # PHP dependencies
└── README.md             # This file
```

## Custom Theme

The site uses a custom Bootstrap 5 subtheme located at `/web/themes/custom/b5subtheme/`.

Key features:
- Responsive design
- Custom CSS/JS for site functionality
- Bootstrap 5 components
- Accessibility compliant

## Deployment

1. Push code changes to GitHub:
   ```bash
   git add .
   git commit -m "Your commit message"
   git push
   ```

2. On the production server:
   ```bash
   git pull
   composer install --no-dev
   drush cr
   drush updb -y
   drush cim -y
   ```

## Security Notes

- Never commit sensitive files (settings.php, .env, etc.)
- Keep Drupal core and modules updated
- Regular security updates via Composer
- Use the `.gitignore` file to exclude sensitive data

## Maintenance

### Updating Drupal Core
```bash
composer update drupal/core --with-dependencies
drush updb -y
drush cr
```

### Updating Modules
```bash
composer update drupal/module_name
drush updb -y
drush cr
```

## Contributing

1. Create a feature branch
2. Make your changes
3. Test thoroughly
4. Commit with descriptive messages
5. Push to GitHub
6. Create a pull request

## Support

For issues or questions about this codebase, please create an issue in the GitHub repository.

## License

This project is proprietary software for ILAS. All rights reserved.
