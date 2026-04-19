# Evolution Framework — Core

> AI-native PHP application framework with sovereign agent infrastructure, budget controls, and self-healing systems.

## What is this?

The **Evolution Core** is the `app/Core/Evolution/` namespace of the Evolution Framework — a PHP framework built for AI-augmented web applications. It provides:

- **Agent infrastructure** — model routing, budget guards, credit monitoring, kill-switch controls
- **Self-healing** — self-repair, snapshot/rollback, kernel integrity, hot-swap deployment
- **Memory & knowledge** — vector memory, semantic logs, knowledge retrieval, learning loops
- **AI integrations** — Anthropic Claude, OpenAI, DeepSeek, Ollama (local), Tavily web search
- **Compliance & security** — GDPR, PII scanning, audit logging, compliance logger
- **Growth systems** — lead intelligence, outreach, affiliate tracking, reputation engine

## Structure

```
app/Core/Evolution/
├── AiCreditMonitor.php          # Token spend tracking + budget enforcement
├── BudgetGuardService.php       # Monthly spend caps + approval gates
├── SystemSettingsService.php    # Runtime DB-driven settings (live mode, API keys)
├── ModelRouterService.php       # Multi-model routing (Anthropic/OpenAI/local)
├── VectorMemoryService.php      # Semantic vector store
├── SelfRepairService.php        # Autonomous self-healing
├── EvolutionLogger.php          # Structured audit + evolution logging
├── ComplianceLogger.php         # GDPR/regulatory audit trail
├── GitHub/                      # GitHub sync + academy curriculum
├── Growth/                      # Lead intelligence + outreach
├── Intelligence/                # Cross-check, reasoning, thinking budget
├── Social/                      # Identity network, gossip service
└── Wallet/                      # Agent wallet (ETH, secure key management)
```

## Requirements

- PHP 8.2+
- Composer
- MySQL/MariaDB
- Optional: Redis (cache), APCu (in-process cache), Ollama (local LLM)

## Configuration

All configuration uses JSON files under `config/`:

```json
// config/evolution.json (simplified)
{
  "anthropic": { "api_key": "" },
  "openai":    { "api_key": "" },
  "budget":    { "monthly_budget_eur": 20 }
}
```

API keys are stored in the `system_settings` DB table at runtime (never hardcoded).

## Part of Evolution Framework

This repository contains the Evolution Core extracted from the full [Evolution Framework](https://github.com/monsma-dev/Framework) private repository.

---

Built by [monsma-dev](https://github.com/monsma-dev)
