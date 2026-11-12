### Unreleased
- Added AI router provider stats for OpenRouter, OpenAI and Anthropic: 24h tokens, average tokens per request, cache hit rate, cost and top models, shown as cards below the server panels.
- Multiple keys per provider ("+ Add key"); each key is its own card. Keys are shown masked.
- Key-only mode (regular OpenRouter key) shows n/a for tokens/requests instead of 0.
- Server view is hidden when no local endpoint is configured.
- Collapsed dashboard shows provider chips (tokens, cache hit rate, cost).
- API key filter per provider and per-key table (requests, tokens, cache, cost). OpenRouter per-key cost is today (UTC).
- Settings: per-provider enable checkbox, management/admin key and update interval (5-120 minutes).

### 2026.06.23
- Model card border is now yellow for loading models and light blue for sleeping models.

### 2026.06.16
- Simplified config loading to rely on Unraid's `parse_plugin_cfg()`, which already overlays the user config onto `default.cfg`; `default.cfg` is now the single source of truth for defaults (no behavior change).

### 2026.06.13
- Fixed llama-server single-model mode detection when `/models` returns a top-level model list without router state; fixed `-m` models now show as loaded/idle instead of offering a load action.
- Clarified that llama-server single-model mode is supported for monitoring, while load/unload controls require router mode.
- Added a default-off "Load/unload controls" model card field. Unsupported server modes grey it out, hide per-model action space, and hide the unload-all control.
- Fixed the settings preview so it only renders fields that are both selected and supported.

### 2026.06.11
- Initial LLMStats release.
- Dashboard tile with one tab per configured server, online/offline status glow, and a server status card per tab.
- Model cards with configurable fields: quantization, memory, and busy/idle state.
- Ollama support: server status, available models, running models, metadata, memory usage, and model load/unload.
- llama-server support: health, model listing, router mode model state, slot busy/idle activity, and router mode model load/unload.
- Server type autodetection with manual fallback.
- Load unloaded models where supported, unload single model, and unload all loaded models with optional confirmation.
- Collapsed dashboard state with compact server status chips.
- Settings page with server management (add, remove, reorder, test), per-server model display fields, and setup guidance for both server types.
- Light and dark theme support.
