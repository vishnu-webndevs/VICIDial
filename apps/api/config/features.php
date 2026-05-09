<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Runtime Feature Flags (v2 Rollout)
    |--------------------------------------------------------------------------
    |
    | These flags are used for controlled rollout and kill-switch behavior.
    | They are read at runtime and can be toggled per environment.
    |
    */
    'auth_v2' => (bool) env('FF_AUTH_V2', false),
    'tenant_profile_v2' => (bool) env('FF_TENANT_PROFILE_V2', false),
    'rbac_v2' => (bool) env('FF_RBAC_V2', false),
    'dialer_v2' => (bool) env('FF_DIALER_V2', false),
    'call_controls_v2' => (bool) env('FF_CALL_CONTROLS_V2', false),
    'crm_v2' => (bool) env('FF_CRM_V2', false),
    'campaigns_v2' => (bool) env('FF_CAMPAIGNS_V2', false),
    'realtime_v2' => (bool) env('FF_REALTIME_V2', false),
    'analytics_v2' => (bool) env('FF_ANALYTICS_V2', false),
    'billing_v2' => (bool) env('FF_BILLING_V2', false),
    'ai_transcription' => (bool) env('FF_AI_TRANSCRIPTION', false),
    'ai_summary' => (bool) env('FF_AI_SUMMARY', false),
    'ai_scoring' => (bool) env('FF_AI_SCORING', false),
    'phase1_contact_directory' => (bool) env('FF_PHASE1_CONTACT_DIRECTORY', true),
    'phase1_project_context' => (bool) env('FF_PHASE1_PROJECT_CONTEXT', true),
    'phase1_interaction_context' => (bool) env('FF_PHASE1_INTERACTION_CONTEXT', true),
    'phase1_voice_runtime' => (bool) env('FF_PHASE1_VOICE_RUNTIME', true),
    'phase1_voicemail' => (bool) env('FF_PHASE1_VOICEMAIL', true),
    'phase1_sms_inbox' => (bool) env('FF_PHASE1_SMS_INBOX', true),
    'phase1_teams_notifications' => (bool) env('FF_PHASE1_TEAMS_NOTIFICATIONS', true),
    'phase2_ai_receptionist' => (bool) env('FF_PHASE2_AI_RECEPTIONIST', true),
    'phase2_graph_scheduling' => (bool) env('FF_PHASE2_GRAPH_SCHEDULING', true),
    'phase2_whatsapp' => (bool) env('FF_PHASE2_WHATSAPP', true),
    'phase3_workflows' => (bool) env('FF_PHASE3_WORKFLOWS', true),
    'phase3_unified_reporting' => (bool) env('FF_PHASE3_UNIFIED_REPORTING', true),
    'phase3_governance' => (bool) env('FF_PHASE3_GOVERNANCE', true),
];
