import time
from collections import deque
from typing import Dict

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

class RateLimiterRegistry:
    """
    Singleton Registry to maintain per-tenant buckets in memory.
    """
    _instance = None
    _buckets: Dict[str, TokenBucket] = {}

    def __new__(cls):
        if cls._instance is None:
            cls._instance = super(RateLimiterRegistry, cls).__new__(cls)
        return cls._instance

    def get_bucket(self, key: str, rate_per_minute: int) -> TokenBucket:
        if key not in self._buckets:
            self._buckets[key] = TokenBucket(rate_per_minute)
        return self._buckets[key]

# Export a singleton instance
rate_limiter_registry = RateLimiterRegistry()