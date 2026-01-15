# SSH Configuration for j-sistem/ap Project

## Server Access
- **SSH Alias:** `archaeonews`
- **Connection:** `ssh archaeonews`
- **Server User:** danko1

## Project Paths

### Server (Production)
- **Root:** `~/web/j-sistem.hr/public_html/ap/`
- **Full path:** `/home/danko1/web/j-sistem.hr/public_html/ap/`
- **Backup location:** `~/tmp/`

### Local (Development)
- **Root:** `D:\home\sites\j-sistem\web\ap\`
- **Deployment staging:** `D:\home\sites\j-sistem\web\ap_deploy\`

## Database

### Production Server
- **Database:** `danko1_apcl`
- **User:** `danko1_apcluser`
- **Host:** localhost

### Local (Not committed to git)
- **Database:** varies per environment
- **Config:** See config.php (not tracked in git)

## Git Repository
- **Remote:** https://github.com/djosic56/library-management-system.git
- **Branch:** main

## Common Deployment Commands

### Deploy via SCP
```bash
scp file.php archaeonews:~/web/j-sistem.hr/public_html/ap/
```

### Deploy via TAR
```bash
# Create archive
tar -czf deploy.tar.gz files/

# Upload
scp deploy.tar.gz archaeonews:~/tmp/

# Extract on server
ssh archaeonews "cd ~/web/j-sistem.hr/public_html/ap && tar -xzf ~/tmp/deploy.tar.gz"
```

### Quick Commands
```bash
# PHP syntax check
ssh archaeonews "cd ~/web/j-sistem.hr/public_html/ap && php -l file.php"

# Check HTTP status
ssh archaeonews "curl -s -o /dev/null -w '%{http_code}' https://j-sistem.hr/ap/file.php"

# Create backup
ssh archaeonews "cd ~/web/j-sistem.hr/public_html/ap && tar -czf ~/tmp/backup_\$(date +%Y%m%d_%H%M%S).tar.gz ."
```

## Important Notes
- **Never commit** `config.php` - contains database credentials
- **Never upload** `config.php` during deployment - already configured on server
- **Always backup** before major deployments (location: ~/tmp/)
- **Check PHP syntax** after upload: `php -l file.php`

## Last Updated
2026-01-15
