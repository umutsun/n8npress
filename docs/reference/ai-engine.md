# AI Engine

Three providers supported (configurable per-workflow):

| Provider | Default Model | Use Case |
|----------|---------------|----------|
| OpenAI | gpt-4o-mini | Translation, general |
| Anthropic | claude-haiku-4-5 | Enrichment, AEO |
| Google | gemini-2.0-flash | Budget-friendly |

All AI calls go through `LuwiPress_AI_Engine::dispatch()` which handles:
- Provider routing, token counting, budget enforcement
- Prompt template selection via `LuwiPress_Prompts`
- JSON response parsing with markdown fence extraction
- Cost recording via `LuwiPress_Token_Tracker`
