FROM redis/redis-stack-server:latest

# Configure Redis to load RediSearch module
COPY redis.conf /redis-stack.conf

CMD ["redis-stack-server", "/redis-stack.conf"]
