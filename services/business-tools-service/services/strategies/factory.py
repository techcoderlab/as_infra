from services.strategies.base import LLMStrategy
from services.strategies.openai_strategy import OpenAIStrategy
from services.strategies.gemini_strategy import GeminiStrategy
from services.strategies.anthropic_strategy import AnthropicStrategy

class LLMStrategyFactory:
    @staticmethod
    def get_strategy(provider: str) -> LLMStrategy:
        provider_lower = provider.lower()
        if provider_lower == "openai":
            return OpenAIStrategy()
        elif provider_lower == "gemini":
            return GeminiStrategy()
        elif provider_lower in ["anthropic", "claude"]:
            return AnthropicStrategy()
        else:
            raise ValueError(f"Provider {provider} not supported.")
