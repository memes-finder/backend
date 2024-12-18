# Memes Search Engine

A powerful search engine for Arabic and English memes, built with PHP Phalcon framework.

## Features

- Crawl memes from various sources (Google Images, with extensibility for more sources)
- Store memes with metadata in MySQL database
- Use RediSearch for fast and efficient search capabilities
- Support for both Arabic and English memes
- Automatic language detection
- Search suggestions and autocomplete
- CDN-based image delivery through AWS S3
- RESTful API for mobile app integration

## Requirements

- PHP >= 8.1
- Phalcon Extension >= 5.3
- MySQL >= 8.0
- Redis with RediSearch module
- AWS S3 account (for image storage)
- Google Custom Search API credentials

## Installation

1. Clone the repository
2. Install dependencies:
   ```bash
   composer install
   ```

3. Copy `.env.example` to `.env` and configure your environment variables:
   ```bash
   cp .env.example .env
   ```

4. Create the database and tables:
   ```bash
   mysql -u your_user -p < schema.sql
   ```

5. Set up RediSearch:
   The search index will be created automatically when the application starts.

## Docker Installation

1. Copy the environment file:
   ```bash
   cp .env.example .env
   ```

2. Build and start the Docker containers:
   ```bash
   docker-compose up -d --build
   ```

3. Install PHP dependencies:
   ```bash
   docker-compose exec php composer install
   ```

4. The following services will be available:
   - API: http://localhost:8080
   - PHPMyAdmin: http://localhost:8081
   - MySQL: localhost:3306
   - Redis: localhost:6379

5. Default database credentials:
   - Database: memes_db
   - Username: memes_user
   - Password: memes_password

6. Run the indexer:
   ```bash
   docker-compose exec php php cli.php index main
   ```

7. Run the crawler:
   ```bash
   docker-compose exec php php cli.php crawler google
   ```

## Docker Commands

- Start containers:
  ```bash
  docker-compose up -d
  ```

- Stop containers:
  ```bash
  docker-compose down
  ```

- View logs:
  ```bash
  docker-compose logs -f
  ```

- Access PHP container:
  ```bash
  docker-compose exec php bash
  ```

- Access MySQL:
  ```bash
  docker-compose exec mysql mysql -u memes_user -p memes_db
  ```

- Access Redis CLI:
  ```bash
  docker-compose exec redis redis-cli
  ```

## Configuration

Configure the following in your `.env` file:

- Database credentials
- Redis connection details
- AWS S3 credentials
- Google API credentials
- API settings

## Usage

### Start the API Server

```bash
php -S localhost:8000 -t public
```

### Run the Crawler

```bash
php cli.php crawler google
```

### Reindex Existing Data

```bash
php cli.php index main
```

## API Endpoints

### Search Memes
```
GET /api/search?q=query&lang=en&page=1&limit=20
```

Parameters:
- `q`: Search query
- `lang`: Language (en|ar|auto)
- `page`: Page number
- `limit`: Results per page

### Get Search Suggestions
```
GET /api/suggestions?q=prefix&lang=en
```

Parameters:
- `q`: Search prefix
- `lang`: Language (en|ar|auto)

## Contributing

1. Fork the repository
2. Create your feature branch
3. Commit your changes
4. Push to the branch
5. Create a new Pull Request

## License

This project is licensed under the MIT License.
