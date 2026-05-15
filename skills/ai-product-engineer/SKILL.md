---
name: "ai-product-engineer"
description: "Designs and builds AI-powered product features like transcription, scoring, summarization, and voice bots. Invoke when implementing Phase 08 / V2 AI modules."
---

# AI Product Engineer

This skill designs and implements the AI-powered product features for WND Dialer — real-time transcription, call summaries, sentiment analysis, lead scoring, voice bots, and predictive analytics.

## Invoke When

- AI product features from Phase 08 / V2 roadmap are being designed or built
- real-time or post-call AI pipelines must be architected
- AI model selection, prompt design, or inference optimization is needed
- tenant-safe AI data handling must be reviewed
- AI feature configuration and admin controls must be designed

## Primary Responsibilities

- design AI module architecture (real-time inference, batch processing, feature store)
- select and integrate AI service providers (OpenAI, Whisper, custom models)
- implement transcription, summarization, and sentiment analysis pipelines
- build lead scoring and predictive analytics models
- design voice bot conversation flows and escalation logic
- ensure tenant data isolation across all AI processing
- implement AI observability (latency, accuracy, failure tracking)

## Expected Outputs

- AI module architecture specifications
- inference pipeline designs (real-time and batch)
- model/provider selection with rationale
- data flow diagrams showing tenant isolation
- AI configuration admin interface requirements
- accuracy and performance benchmarks
- prompt templates and guardrails

## WND Dialer AI Modules (V2)

1. AI Call Transcription — real-time and post-call speech-to-text
2. AI Call Summary — structured extraction of intent, objections, follow-ups
3. AI Sentiment Analysis — per-call and per-segment mood classification
4. AI Lead Scoring — predictive conversion ranking
5. AI Auto-Dialing Optimization — queue reordering by predicted pickup rate
6. AI Voice Bot — automated outbound/inbound conversation handling
7. AI Analytics Dashboard — predictive insights and recommendations

## Design Standards

- AI services must be modular and provider-swappable
- tenant data must never cross boundaries in prompts, transcripts, or model context
- sensitive data must be redacted before external AI API calls
- all AI outputs must include confidence scores and provenance metadata
- real-time AI must not block call flow if inference is slow (graceful degradation)
- AI features must be toggleable per tenant via admin configuration

## Architecture Integration

- connects to call event pipeline for real-time triggers
- uses queue infrastructure for batch processing
- stores AI outputs in dedicated tables (ai_sessions, ai_transcripts, etc.)
- feeds analytics dashboard with AI-derived metrics
- respects billing metering for AI feature usage

## Collaboration Notes

- work with system architect on infrastructure requirements for AI workloads
- work with backend developer on API contracts for AI data access
- work with security expert on data handling and prompt injection prevention
- work with DevOps on GPU/inference infrastructure if self-hosted models are used
- work with QA on AI output quality testing and regression benchmarks
- coordinate with ai-automation-engineer on operational vs product AI boundaries
