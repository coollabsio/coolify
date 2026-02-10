# Coolify Technology Stack

Complete technology stack, dependencies, and infrastructure components.

## Backend Framework

### **Laravel 12.4.1** (PHP Framework)
- **Purpose**: Core application framework
- **Key Features**:
  - Eloquent ORM for database interactions
  - Artisan CLI for development tasks
  - Queue system for background jobs
  - Event-driven architecture

### **PHP 8.4.7**
- **Requirement**: `^8.4` in composer.json
- **Features Used**:
  - Typed properties and return types
  - Attributes for validation and configuration
  - Match expressions
  - Constructor property promotion

## Frontend Stack

### **Livewire 3.5.20** (Primary Frontend Framework)
- **Purpose**: Server-side rendering with reactive components
- **Location**: `app/Livewire/`
- **Key Components**:
  - Dashboard - Main interface
  - ActivityMonitor - Real-time monitoring
  - MonacoEditor - Code editor

### **Alpine.js** (Client-Side Interactivity)
- **Purpose**: Lightweight JavaScript for DOM manipulation
- **Integration**: Works seamlessly with Livewire components
- **Usage**: Declarative directives in Blade templates

### **Tailwind CSS 4.1.4** (Styling Framework)
- **Configuration**: `postcss.config.cjs`
- **Extensions**:
  - `@tailwindcss/forms` - Form styling
  - `@tailwindcss/typography` - Content typography
  - `tailwind-scrollbar` - Custom scrollbars

### **Vue.js 3.5.13** (Component Framework)
- **Purpose**: Enhanced interactive components
- **Integration**: Used alongside Livewire for complex UI
- **Build Tool**: Vite with Vue plugin

## Database & Caching

### **PostgreSQL 15** (Primary Database)
- **Purpose**: Main application data storage
- **Features**: JSONB support, advanced indexing
- **Models**: `app/Models/`

### **Redis 7** (Caching & Real-time)
- **Purpose**:
  - Session storage
  - Queue backend
  - Real-time data caching
  - WebSocket session management

### **Supported Databases** (For User Applications)
- **PostgreSQL**: StandalonePostgresql
- **MySQL**: StandaloneMysql
- **MariaDB**: StandaloneMariadb
- **MongoDB**: StandaloneMongodb
- **Redis**: StandaloneRedis
- **KeyDB**: StandaloneKeydb
- **Dragonfly**: StandaloneDragonfly
- **ClickHouse**: StandaloneClickhouse

## Authentication & Security

### **Laravel Sanctum 4.0.8**
- **Purpose**: API token authentication
- **Usage**: Secure API access for external integrations

### **Laravel Fortify 1.25.4**
- **Purpose**: Authentication scaffolding
- **Features**: Login, registration, password reset

### **Laravel Socialite 5.18.0**
- **Purpose**: OAuth provider integration
- **Providers**:
  - GitHub, GitLab, Google
  - Microsoft Azure, Authentik, Discord, Clerk
  - Custom OAuth implementations

## Background Processing

### **Laravel Horizon 5.30.3**
- **Purpose**: Queue monitoring and management
- **Features**: Real-time queue metrics, failed job handling

### **Queue System**
- **Backend**: Redis-based queues
- **Jobs**: `app/Jobs/`
- **Processing**: Background deployment and monitoring tasks

## Development Tools

### **Build Tools**
- **Vite 6.2.6**: Modern build tool and dev server
- **Laravel Vite Plugin**: Laravel integration
- **PostCSS**: CSS processing pipeline

### **Code Quality**
- **Laravel Pint**: PHP code style fixer
- **Rector**: PHP automated refactoring
- **PHPStan**: Static analysis tool

### **Testing Framework**
- **Pest 3.8.0**: Modern PHP testing framework
- **Laravel Dusk**: Browser automation testing
- **PHPUnit**: Unit testing foundation

## External Integrations

### **Git Providers**
- **GitHub**: Repository integration and webhooks
- **GitLab**: Self-hosted and cloud GitLab support
- **Bitbucket**: Atlassian integration
- **Gitea**: Self-hosted Git service

### **Cloud Storage**
- **AWS S3**: league/flysystem-aws-s3-v3
- **SFTP**: league/flysystem-sftp-v3
- **Local Storage**: File system integration

### **Notification Services**
- **Email**: resend/resend-laravel
- **Discord**: Custom webhook integration
- **Slack**: Webhook notifications
- **Telegram**: Bot API integration
- **Pushover**: Push notifications

### **Monitoring & Logging**
- **Sentry**: sentry/sentry-laravel - Error tracking
- **Laravel Ray**: spatie/laravel-ray - Debug tool
- **Activity Log**: spatie/laravel-activitylog

## DevOps & Infrastructure

### **Docker & Containerization**
- **Docker**: Container runtime
- **Docker Compose**: Multi-container orchestration
- **Docker Swarm**: Container clustering (optional)

### **Web Servers & Proxies**
- **Nginx**: Primary web server
- **Traefik**: Reverse proxy and load balancer
- **Caddy**: Alternative reverse proxy

### **Process Management**
- **S6 Overlay**: Process supervisor
- **Supervisor**: Alternative process manager

### **SSL/TLS**
- **Let's Encrypt**: Automatic SSL certificates
- **Custom Certificates**: Manual SSL management

## Terminal & Code Editing

### **XTerm.js 5.5.0**
- **Purpose**: Web-based terminal emulator
- **Features**: SSH session management, real-time command execution
- **Addons**: Fit addon for responsive terminals

### **Monaco Editor**
- **Purpose**: Code editor component
- **Features**: Syntax highlighting, auto-completion
- **Integration**: Environment variable editing, configuration files

## API & Documentation

### **OpenAPI/Swagger**
- **Documentation**: openapi.json (373KB)
- **Generator**: zircote/swagger-php
- **API Routes**: `routes/api.php`

### **WebSocket Communication**
- **Laravel Echo**: Real-time event broadcasting
- **Pusher**: WebSocket service integration
- **Soketi**: Self-hosted WebSocket server

## Package Management

### **PHP Dependencies** (composer.json)
```json
{
  "require": {
    "php": "^8.4",
    "laravel/framework": "12.4.1",
    "livewire/livewire": "^3.5.20",
    "spatie/laravel-data": "^4.13.1",
    "lorisleiva/laravel-actions": "^2.8.6"
  }
}
```

### **JavaScript Dependencies** (package.json)
```json
{
  "devDependencies": {
    "vite": "^6.2.6",
    "tailwindcss": "^4.1.4",
    "@vitejs/plugin-vue": "5.2.3"
  },
  "dependencies": {
    "@xterm/xterm": "^5.5.0",
    "ioredis": "5.6.0"
  }
}
```

## Configuration Files

### **Build Configuration**
- **vite.config.js**: Frontend build setup
- **postcss.config.cjs**: CSS processing
- **rector.php**: PHP refactoring rules
- **pint.json**: Code style configuration

### **Testing Configuration**
- **phpunit.xml**: Unit test configuration
- **phpunit.dusk.xml**: Browser test configuration
- **tests/Pest.php**: Pest testing setup

## Version Requirements

### **Minimum Requirements**
- **PHP**: 8.4+
- **Node.js**: 18+ (for build tools)
- **PostgreSQL**: 15+
- **Redis**: 7+
- **Docker**: 20.10+
- **Docker Compose**: 2.0+

### **Recommended Versions**
- **Ubuntu**: 22.04 LTS or 24.04 LTS
- **Memory**: 2GB+ RAM
- **Storage**: 20GB+ available space
- **Network**: Stable internet connection for deployments
