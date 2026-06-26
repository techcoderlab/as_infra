import time
from collections import deque
from typing import Dict

import redis
from core.config import settings

class TokenBucket:
    def __init__(self, rate_per_minute: int):
        self.capacity = rate_per_minute
        self.tokens = deque()
        self.window = 60.0

    def consume(self) -> float:
        """
        Returns 0.0 if allowed immediately.
        Otherwise returns the number of seconds to wait.
        """
        now = time.time()
        
        # 1. Slide the window: Remove timestamps older than 60s
        while self.tokens and now - self.tokens[0] > self.window:
            self.tokens.popleft()
        
        # 2. Check Capacity
        if len(self.tokens) < self.capacity:
            self.tokens.append(now)
            return 0.0
        
        # 3. Calculate Wait Time
        # We need to wait until the oldest token falls out of the window
        oldest_token = self.tokens[0]
        wait_time = oldest_token + self.window - now
        
        return max(0.0, wait_time)

class RedisTokenBucket:
    def __init__(self, key: str, rate_per_minute: int):
        self.capacity = rate_per_minute
        self.key = f"rate_limit:{key}"
        self.window = 60.0
        try:
            self.redis = redis.Redis(
                host=settings.REDIS_HOST,
                port=settings.REDIS_PORT,
                decode_responses=True
            )
            self.redis.ping()
        except Exception:
            self.redis = None

    def consume(self) -> float:
        if not self.redis:
            return 0.0

        now = time.time()
        pipeline = self.redis.pipeline()
        pipeline.zremrangebyscore(self.key, 0, now - self.window)
        pipeline.zcard(self.key)
        results = pipeline.execute()
        
        current_count = results[1]
        
        if current_count < self.capacity:
            pipeline = self.redis.pipeline()
            pipeline.zadd(self.key, {str(now): now})
            pipeline.expire(self.key, int(self.window) + 1)
            pipeline.execute()
            return 0.0
            
        oldest_tokens = self.redis.zrange(self.key, 0, 0, withscores=True)
        if oldest_tokens:
            oldest_token_time = oldest_tokens[0][1]
            wait_time = oldest_token_time + self.window - now
            return max(0.0, float(wait_time))
            
        return 0.0

class RateLimiterRegistry:
    """
    Singleton Registry to maintain per-tenant buckets.
    """
    _instance = None
    _buckets: Dict[str, object] = {}

    def __new__(cls):
        if cls._instance is None:
            cls._instance = super(RateLimiterRegistry, cls).__new__(cls)
        return cls._instance

    def get_bucket(self, key: str, rate_per_minute: int):
        if key not in self._buckets:
            bucket = RedisTokenBucket(key, rate_per_minute)
            if bucket.redis is None:
                bucket = TokenBucket(rate_per_minute)
            self._buckets[key] = bucket
        return self._buckets[key]

# Export a singleton instance
rate_limiter_registry = RateLimiterRegistry()