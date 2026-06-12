from abc import ABC, abstractmethod
from typing import AsyncGenerator, Any, List, Optional

class LLMStrategy(ABC):
    @abstractmethod
    def run_stream(self, api_key: str, model: str, system_prompt: str, effective_history: list, full_user_message: str, tools: list, context: dict, output_format: str = 'text', thinking_budget: Optional[int] = None) -> AsyncGenerator[dict[str, Any], None]:
        pass
    
    # # run method is for hyde search  
    # @abstractmethod
    # async def run(self, prompt: str, use_cache: bool = True, **kwargs) -> str:
    #     pass

    # @abstractmethod
    # async def run_agentic(self, prompt: str, tools: List[Any], max_iterations: int = 5) -> str:
    #     """The 'Smart Manager' loop: Think -> Act -> Observe."""
    #     pass