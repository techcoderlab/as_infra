from abc import ABC, abstractmethod
from pydantic import BaseModel
from typing import Dict, Any, Type
from pydantic import ValidationError

class BaseTool(ABC):
    name: str
    description: str
    args_schema: Type[BaseModel]

    @abstractmethod
    async def run(self, **kwargs) -> Any:
        pass

    def to_openai_schema(self):
        return {
            "type": "function",
            "function": {
                "name": self.name,
                "description": self.description,
                "parameters": self.args_schema.model_json_schema()
            }
        }

    def to_gemini_schema(self):
        full_schema = self.args_schema.model_json_schema()
        definitions = full_schema.pop("$defs", {})

        def resolve_and_clean(s):
            if not isinstance(s, dict):
                return s

            # 1. Handle $ref (References)
            if "$ref" in s:
                ref_path = s.pop("$ref")
                ref_name = ref_path.split("/")[-1]
                ref_content = definitions.get(ref_name, {})
                s.update(ref_content)
                return resolve_and_clean(s)

            # 2. Handle allOf (Merging)
            if "allOf" in s:
                all_of_parts = s.pop("allOf")
                for part in all_of_parts:
                    s.update(resolve_and_clean(part))
                return resolve_and_clean(s)

            # 3. Handle anyOf (Flattening Nullables)
            # Gemini doesn't support anyOf. If it's a nullable type, 
            # we pick the one that ISN'T 'null'.
            if "anyOf" in s:
                any_of_parts = s.pop("anyOf")
                # Look for the first part that isn't type: null
                real_part = next((p for p in any_of_parts if p.get("type") != "null"), any_of_parts[0])
                s.update(resolve_and_clean(real_part))
                s["nullable"] = True # Gemini Schema support nullable field
                return resolve_and_clean(s)

            # 4. Purge Gemini-incompatible fields
            s.pop("title", None)
            s.pop("default", None)
            s.pop("additionalProperties", None)
            # Protobuf Schema does not accept 'description' inside nested objects easily
            # but we keep it at the top level of the parameter if possible.

            # 5. Map and Uppercase Types
            if "type" in s:
                type_map = {
                    "string": "string", "integer": "integer", 
                    "number": "number", "boolean": "boolean", 
                    "array": "array", "object": "object"
                }
                raw_type = s["type"]
                # If Pydantic gives us a list of types, take the first non-null one
                if isinstance(raw_type, list):
                    raw_type = next((t for t in raw_type if t != "null"), raw_type[0])
                
                s["type"] = type_map.get(str(raw_type).lower(), "OBJECT")

            # 6. Recursive cleaning
            if "properties" in s:
                for k, v in s["properties"].items():
                    s["properties"][k] = resolve_and_clean(v)
            
            if "items" in s:
                s["items"] = resolve_and_clean(s["items"])

            return s

        cleaned_parameters = resolve_and_clean(full_schema)

        return {
            "name": self.name,
            "description": self.description,
            "parameters": cleaned_parameters
        }

    def to_anthropic_schema(self):
        """
        Converts the Pydantic model to an Anthropic tool schema.
        Anthropic expects:
        {
            "name": "tool_name",
            "description": "tool_description",
            "input_schema": { ... JSON Schema ... }
        }
        """
        schema = self.args_schema.model_json_schema()
        
        # Remove Common Pydantic artifacts that might confuse the LLM or aren't standard JSON Schema
        if "title" in schema:
            del schema["title"]

        # Clean up definitions if any (Anthropic supports standard JSON schema, 
        # but often it's safer to resolve refs or keep it simple. 
        # For now, we pass the schema as-is, assuming simple structures.
        # If complex recursive schemas are used, we might need a resolver similar to Gemini's)
        
        return {
            "name": self.name,
            "description": self.description,
            "input_schema": schema
        }