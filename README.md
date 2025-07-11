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
   # For main database
   gunzip < backup.sql.gz | drush sql-cli
   
   # For CiviCRM database (if applicable)
   gunzip < civicrm_backup.sql.gz | ddev mysql -d ilas_civicrm
   ```

6. Clear caches:
   ```bash
   drush cr
   ```

## Backup Strategy

This project uses a comprehensive backup approach following Drupal best practices:

### 1. Git Version Control
- All code changes are tracked in this repository
- Custom themes and modules are version controlled
- Configuration changes are tracked

### 2. Full Site Backups
- Use the `backup-drupal-updated.sh` script for complete backups
- Creates backups of:
  - Main Drupal database
  - CiviCRM database (separate)
  - Files directory (public and private)
  - Custom code (modules and themes)
  - Full site archive
  - CiviCRM settings
- Backups are stored in `~/drupal-backups/ilas/` (outside web root as per Drupal guidelines)
- Automatic cleanup keeps only the 5 most recent backups
- Optional: Copies to Windows Desktop and Google Drive

To run a backup:
```bash
cd web
./backup-drupal-updated.sh
```

**Note**: Never store backups in the web root or modules directories as this can interfere with Drupal's auto-discovery mechanism.

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
│   ├── backup-drupal.sh   # Original backup script
│   └── backup-drupal-updated.sh   # Updated backup script (recommended)
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

### Before Any Updates
1. **Always create a backup first**:
   ```bash
   cd web
   ./backup-drupal-updated.sh
   ```
2. **Put site in maintenance mode**:
   ```bash
   ddev drush state:set system.maintenance_mode 1
   ```

### Updating Drupal Core
```bash
ddev composer update drupal/core --with-dependencies
ddev drush updb -y
ddev drush cr
```

### Updating Modules
```bash
ddev composer update drupal/module_name
ddev drush updb -y
ddev drush cr
```

### After Updates
1. **Take site out of maintenance mode**:
   ```bash
   ddev drush state:set system.maintenance_mode 0
   ```
2. **Clear caches**:
   ```bash
   ddev drush cr
   ```
3. **Test the site thoroughly**

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
