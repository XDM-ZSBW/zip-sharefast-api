# ShareFast API Server

> Backend API and server infrastructure for ShareFast remote desktop platform

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

This repository contains the server-side code for ShareFast, including PHP API endpoints, database schema, and deployment configurations for Google Cloud Platform.

## 🌟 Features

- **RESTful API** - PHP-based API endpoints for session management
- **MySQL Database** - Scalable database storage for sessions and admin management
- **WebSocket Relay** - Real-time communication relay server
- **Secure Configuration** - IP-based access control and security headers
- **Auto Deployment** - GitHub Actions for automated GCP deployment

## 📁 Repository Structure

```
zip-sharefast-api/
├── api/                    # PHP API endpoints
│   ├── register.php       # Session registration
│   ├── poll.php           # Signal polling
│   ├── signal.php         # WebRTC signaling
│   ├── list_clients.php   # Client listing
│   └── ...
├── config.php.example     # Configuration template
├── database.php           # Database connection helpers
├── database_schema.sql     # Database schema
├── migrations/            # Database migrations
├── apache/               # Apache configuration
├── scripts/              # Deployment scripts
│   ├── deploy/           # GCP deployment
│   ├── setup/            # Server setup
│   └── server/           # WebSocket server
└── .github/workflows/    # CI/CD workflows
```

## 🚀 Quick Start

### Prerequisites

- Google Cloud Platform account
- MySQL database (Cloud SQL or VM-based)
- PHP 7.4+ with MySQL extensions
- Apache web server

### Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/XDM-ZSBW/zip-sharefast-api.git
   cd zip-sharefast-api
   ```

2. **Configure server**
   ```bash
   cp config.php.example config.php
   # Edit config.php with your database credentials
   ```

3. **Setup database**
   ```bash
   mysql -u your_user -p your_database < database_schema.sql
   ```

4. **Deploy to GCP**
   ```bash
   # Using deployment script
   python scripts/deploy/deploy_api_to_gcp.py
   ```

## 📝 Configuration

### Database Configuration

Edit `config.php`:

```php
define('DB_HOST', 'your-db-host');
define('DB_NAME', 'your-database-name');
define('DB_USER', 'your-db-user');
define('DB_PASS', 'your-db-password');
define('STORAGE_METHOD', 'database');
```

### Apache Configuration

See `apache/sharefast-ssl.conf` for SSL and access control configuration.

## 🔄 Database Migrations

Run migrations:

```bash
# Via SSH to server
mysql -u user -p database < migrations/migration_name.sql

# Or use gcloud
gcloud compute ssh instance --zone=zone --command="mysql -u user -p database < migrations/migration_name.sql"
```

## 🚀 Deployment

### Manual Deployment

```bash
# Deploy API files
python scripts/deploy/deploy_api_to_gcp.py

# Or use batch script
scripts/deploy/deploy_api_gcp.bat
```

### Automated Deployment

GitHub Actions automatically deploys on push to `main` branch.

## 🔒 Security

- API endpoints restricted to backend server IPs
- Database credentials stored in `config.php` (not in repo)
- SSL/TLS encryption for all connections
- Rate limiting on API endpoints
- Input validation and SQL injection prevention

## 📊 API Endpoints

### Session Management
- `POST /api/register.php` - Register new session
- `POST /api/poll.php` - Poll for signals
- `POST /api/signal.php` - Send WebRTC signal
- `POST /api/disconnect.php` - Disconnect session

### Admin Management
- `POST /api/admin_auth.php` - Authenticate admin
- `POST /api/admin_codes.php` - Get admin's client codes
- `POST /api/list_clients.php` - List available clients

See API documentation for full endpoint details.

## 🔗 Related Repositories

- **Desktop App**: [zip-sharefast-app](https://github.com/XDM-ZSBW/zip-sharefast-app) - Client desktop application
- **Website**: [sharefast-www](https://github.com/XDM-ZSBW/sharefast-www) - Public website and downloads

## 📄 License

MIT License - see [LICENSE](LICENSE) file for details

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Submit a pull request

## 📧 Support

For issues and questions, please open an issue on GitHub.

