<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AgentController;
use App\Http\Controllers\Api\V1\AgentSessionController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AdminCommunicationSettingsController;
use App\Http\Controllers\Api\V1\ApiTokenController;
use App\Http\Controllers\Api\SandboxThirdPartyController;
use App\Http\Controllers\Api\V1\OperationalHealthController;
use App\Http\Controllers\Api\V1\CampaignController;
use App\Http\Controllers\Api\V1\CallController;
use App\Http\Controllers\Api\V1\CorePhaseOneController;
use App\Http\Controllers\Api\V1\DialerLoopIncidentController;
use App\Http\Controllers\Api\V1\LeadController;
use App\Http\Controllers\Api\V1\LeadWorkflowController;
use App\Http\Controllers\Api\V1\MessagingController;
use App\Http\Controllers\Api\V1\MessageTemplateController;
use App\Http\Controllers\Api\V1\MetaTemplateController;
use App\Http\Controllers\Api\V1\WhatsAppIntegrationController;
use App\Http\Controllers\Api\V1\MessageAttachmentController;
use App\Http\Controllers\Api\V1\RealtimeController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\ProviderController;
use App\Http\Controllers\Api\V1\ProviderWebhookController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrgHierarchyController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\SuperAdmin\PlanManagementController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\TwilioVoiceWebhookController;
use App\Http\Controllers\Api\V1\WebhookLogController;
use App\Http\Controllers\Api\V1\GovernanceComplianceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('api.version')->group(function () {
    Route::prefix('auth')->middleware('throttle:auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    });
    Route::post('/team/invitations/{token}/accept', [TeamController::class, 'acceptInvitation']);
    Route::get('/plans', [PlanController::class, 'index']);

    Route::middleware(['auth:sanctum', 'tenant.resolve', 'usage.quota'])->group(function () {
        Route::prefix('auth')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
            Route::patch('/me', [AuthController::class, 'updateMe']);
        });

        Route::get('/tenant', [TenantController::class, 'show'])
            ->middleware('permission:tenant.view');
        Route::patch('/tenant', [TenantController::class, 'update'])
            ->middleware('permission:tenant.update');
        Route::get('/tenant/voice-profile', [TenantController::class, 'voiceProfile'])
            ->middleware('permission:voice_profile.view');
        Route::patch('/tenant/voice-profile', [TenantController::class, 'updateVoiceProfile'])
            ->middleware('permission:voice_profile.manage');
        Route::get('/tenant/recording-policy', [TenantController::class, 'recordingPolicy'])
            ->middleware('permission:tenant.view');
        Route::patch('/tenant/recording-policy', [TenantController::class, 'updateRecordingPolicy'])
            ->middleware('permission:tenant.update');

        Route::get('/team/members', [TeamController::class, 'index'])
            ->middleware('permission:team.view');
        Route::post('/team/invitations', [TeamController::class, 'invite'])
            ->middleware(['permission:team.invite', 'permission:role.assign']);
        Route::patch('/team/members/{id}', [TeamController::class, 'update'])
            ->middleware(['permission:team.update', 'permission:role.assign']);
        Route::delete('/team/members/{id}', [TeamController::class, 'destroy'])
            ->middleware('permission:team.remove');
        Route::prefix('platform')->middleware('tenant.super_admin')->group(function () {
            Route::get('/team/members', [TeamController::class, 'index'])
                ->middleware('permission:team.view');
            Route::post('/team/invitations', [TeamController::class, 'invite'])
                ->middleware(['permission:team.invite', 'permission:role.assign']);
        });

        Route::prefix('super-admin')->middleware('tenant.super_admin')->group(function () {
            Route::get('/plans', [PlanManagementController::class, 'indexPlans']);
            Route::post('/plans', [PlanManagementController::class, 'storePlan']);
            Route::get('/plans/{id}', [PlanManagementController::class, 'showPlan']);
            Route::put('/plans/{id}', [PlanManagementController::class, 'updatePlan']);
            Route::delete('/plans/{id}', [PlanManagementController::class, 'deletePlan']);
            Route::put('/plans/reorder', [PlanManagementController::class, 'reorderPlans']);

            Route::get('/plans/{id}/features', [PlanManagementController::class, 'listFeatures']);
            Route::post('/plans/{id}/features', [PlanManagementController::class, 'storeFeature']);
            Route::put('/plans/{id}/features/{featureId}', [PlanManagementController::class, 'updateFeature']);
            Route::delete('/plans/{id}/features/{featureId}', [PlanManagementController::class, 'deleteFeature']);

            Route::get('/companies', [PlanManagementController::class, 'listCompanies']);
            Route::get('/companies/{id}/plan', [PlanManagementController::class, 'companyPlan']);
            Route::put('/companies/{id}/plan', [PlanManagementController::class, 'updateCompanyPlan']);
            Route::get('/companies/{id}/usage', [PlanManagementController::class, 'companyUsage']);
        });
        Route::get('/org/units', [OrgHierarchyController::class, 'index'])
            ->middleware('permission:team.view');
        Route::post('/org/units', [OrgHierarchyController::class, 'store'])
            ->middleware('permission:team.update');
        Route::patch('/org/memberships/{id}/units', [OrgHierarchyController::class, 'assignMembershipUnits'])
            ->middleware('permission:team.update');

        Route::get('/audit-logs', [AuditLogController::class, 'index'])
            ->middleware('permission:audit.view');

        Route::get('/subscription', [SubscriptionController::class, 'show'])
            ->middleware('permission:billing.view');
        Route::post('/subscription/change-plan', [SubscriptionController::class, 'changePlan'])
            ->middleware('permission:billing.manage');

        Route::get('/providers', [ProviderController::class, 'index'])
            ->middleware('permission:provider.view');
        Route::get('/providers/failover-policy', [ProviderController::class, 'failoverPolicy'])
            ->middleware('permission:failover.view');
        Route::patch('/providers/failover-policy', [ProviderController::class, 'updateFailoverPolicy'])
            ->middleware('permission:failover.manage');
        Route::post('/providers', [ProviderController::class, 'store'])
            ->middleware('permission:provider.create');
        Route::patch('/providers/{id}', [ProviderController::class, 'update'])
            ->middleware('permission:provider.update');
        Route::delete('/providers/{id}', [ProviderController::class, 'destroy'])
            ->middleware('permission:provider.delete');
        Route::post('/providers/{id}/test-connection', [ProviderController::class, 'testConnection'])
            ->middleware('permission:provider.test');

        Route::post('/calls', [CallController::class, 'store'])
            ->middleware('permission:call.initiate');
        Route::post('/calls/bulk', [CallController::class, 'bulkStore'])
            ->middleware('permission:call.initiate');
        Route::post('/calls/{id}/dispatch-now', [CallController::class, 'dispatchNow'])
            ->middleware('permission:call.initiate');
        Route::post('/calls/dialer-loop-incidents', [DialerLoopIncidentController::class, 'store'])
            ->middleware('permission:call.initiate');
        Route::get('/calls', [CallController::class, 'index'])
            ->middleware('permission:call.view');
        Route::get('/calls/export', [CallController::class, 'export'])
            ->middleware('permission:call.export');
        Route::get('/calls/{id}', [CallController::class, 'show'])
            ->middleware('permission:call.view');
        Route::get('/calls/{id}/recording', [CallController::class, 'recording'])
            ->middleware('permission:call.view');
        Route::post('/calls/{id}/tag', [CallController::class, 'tag'])
            ->middleware('permission:call.initiate');
        Route::post('/calls/{id}/retry', [CallController::class, 'retry'])
            ->middleware('permission:call.retry');
        Route::post('/calls/{id}/mute', [CallController::class, 'mute'])
            ->middleware('permission:call.initiate');
        Route::post('/calls/{id}/hold', [CallController::class, 'hold'])
            ->middleware('permission:call.initiate');
        Route::post('/calls/{id}/end', [CallController::class, 'end'])
            ->middleware('permission:call.initiate');
        Route::post('/calls/{id}/transfer', [CallController::class, 'transfer'])
            ->middleware('permission:call.initiate');
        Route::post('/calls/{id}/supervision', [CallController::class, 'supervision'])
            ->middleware('permission:call.initiate');
        Route::get('/calls/{id}/ai', [CallController::class, 'aiArtifact'])
            ->middleware('permission:call.view');
        Route::post('/calls/{id}/ai/process', [CallController::class, 'aiProcess'])
            ->middleware('permission:call.initiate');

        Route::get('/campaigns', [CampaignController::class, 'index'])
            ->middleware('permission:call.view|call.initiate');
        Route::post('/campaigns', [CampaignController::class, 'store'])
            ->middleware('permission:call.initiate');
        Route::patch('/campaigns/{id}', [CampaignController::class, 'update'])
            ->middleware('permission:call.initiate');
        Route::post('/campaigns/{id}/start', [CampaignController::class, 'start'])
            ->middleware('permission:call.initiate');
        Route::post('/campaigns/{id}/pause', [CampaignController::class, 'pause'])
            ->middleware('permission:call.initiate');
        Route::post('/campaigns/{id}/stop', [CampaignController::class, 'stop'])
            ->middleware('permission:call.initiate');
        Route::get('/campaigns/{id}/status', [CampaignController::class, 'status'])
            ->middleware('permission:call.view|call.initiate');
        Route::get('/campaigns/{id}/stats', [CampaignController::class, 'stats'])
            ->middleware('permission:call.view|call.initiate');
        Route::get('/campaigns/{id}/queue', [CampaignController::class, 'queue'])
            ->middleware('permission:call.view|call.initiate');
        Route::get('/campaigns/{id}/message-report', [CampaignController::class, 'messageReport'])
            ->middleware('permission:call.view|call.initiate');

        Route::post('/agents/session', [AgentSessionController::class, 'upsert'])
            ->middleware('permission:call.initiate');
        Route::get('/agents/activities', [AgentSessionController::class, 'index'])
            ->middleware('permission:call.view');
        Route::patch('/agents/sessions/{id}', [AgentSessionController::class, 'update'])
            ->middleware('permission:call.initiate');
        Route::get('/agents', [AgentController::class, 'index'])
            ->middleware('permission:agent.view');
        Route::post('/agents', [AgentController::class, 'store'])
            ->middleware('permission:agent.create');
        Route::patch('/agents/{id}', [AgentController::class, 'update'])
            ->middleware('permission:agent.update');
        Route::delete('/agents/{id}', [AgentController::class, 'destroy'])
            ->middleware('permission:agent.delete');
        Route::get('/agents/number-assignments', [AgentController::class, 'listNumberAssignments'])
            ->middleware('permission:agent.view');
        Route::post('/agents/number-assignments', [AgentController::class, 'assignNumber'])
            ->middleware('permission:agent.assign');
        Route::get('/agents/validated-numbers', [AgentController::class, 'listValidatedNumbers'])
            ->middleware('permission:agent.view');
        Route::get('/campaigns/{campaignId}/agent-assignments', [AgentController::class, 'listCampaignAgents'])
            ->middleware('permission:agent.view');
        Route::put('/campaigns/{campaignId}/agent-assignments', [AgentController::class, 'mapCampaignAgents'])
            ->middleware('permission:agent.assign');

        Route::prefix('admin/settings')->middleware('tenant.admin')->group(function () {
            Route::get('/communication', [AdminCommunicationSettingsController::class, 'index']);
            Route::get('/communication/providers/{providerId}/twilio/numbers', [AdminCommunicationSettingsController::class, 'fetchProviderNumbers'])
                ->middleware('throttle:provider.twilio');
            Route::post('/communication/providers/{providerId}/numbers/sync', [AdminCommunicationSettingsController::class, 'syncProviderNumbers']);
            Route::post('/communication/providers/{providerId}/test', [AdminCommunicationSettingsController::class, 'testProviderAndNumber'])
                ->middleware('throttle:provider.twilio');
            Route::get('/communication/numbers/validated', [AdminCommunicationSettingsController::class, 'listValidatedNumbers']);
            Route::get('/communication/agents/number-assignments', [AgentController::class, 'listNumberAssignments']);
            Route::post('/communication/agents/number-assignments', [AgentController::class, 'assignNumber']);
            Route::get('/communication/campaigns/{campaignId}/agents', [AgentController::class, 'listCampaignAgents']);
            Route::put('/communication/campaigns/{campaignId}/agents', [AgentController::class, 'mapCampaignAgents']);
        });

        Route::get('/realtime/calls/stream', [RealtimeController::class, 'calls'])
            ->middleware('permission:call.view');

        Route::get('/leads', [LeadController::class, 'index'])
            ->middleware('permission:call.view');
        Route::post('/leads', [LeadController::class, 'store'])
            ->middleware('permission:call.initiate');
        Route::patch('/leads/{id}', [LeadController::class, 'update'])
            ->middleware('permission:call.initiate');
        Route::post('/leads/import', [LeadController::class, 'import'])
            ->middleware('permission:call.initiate');
        Route::get('/leads/import-jobs/{id}', [LeadController::class, 'importStatus'])
            ->middleware('permission:call.view');
        Route::get('/leads/{id}/timeline', [LeadWorkflowController::class, 'timeline'])
            ->middleware('permission:call.view');
        Route::post('/leads/{id}/sms', [MessagingController::class, 'sendSms'])
            ->middleware('permission:call.initiate');
        Route::post('/leads/bulk/sms', [MessagingController::class, 'sendBulkSms'])
            ->middleware('permission:call.initiate');
        Route::post('/leads/{id}/whatsapp', [MessagingController::class, 'sendWhatsapp'])
            ->middleware('permission:call.initiate');
        Route::post('/leads/bulk/whatsapp', [MessagingController::class, 'sendBulkWhatsapp'])
            ->middleware('permission:call.initiate');

        Route::get('/message-templates', [MessageTemplateController::class, 'index'])
            ->middleware('permission:tenant.view');
        Route::post('/message-templates', [MessageTemplateController::class, 'store'])
            ->middleware('permission:tenant.update');
        Route::patch('/message-templates/{id}', [MessageTemplateController::class, 'update'])
            ->middleware('permission:tenant.update');
        Route::delete('/message-templates/{id}', [MessageTemplateController::class, 'destroy'])
            ->middleware('permission:tenant.update');

        Route::get('/whatsapp-integration', [WhatsAppIntegrationController::class, 'show'])
            ->middleware('permission:tenant.update');
        Route::put('/whatsapp-integration', [WhatsAppIntegrationController::class, 'upsert'])
            ->middleware('permission:tenant.update');
        Route::post('/whatsapp-integration/test', [WhatsAppIntegrationController::class, 'test'])
            ->middleware('permission:tenant.update');
        Route::get('/whatsapp-integration/message-templates', [MetaTemplateController::class, 'index'])
            ->middleware('permission:tenant.view');
        Route::post('/whatsapp-integration/message-templates/sync', [MetaTemplateController::class, 'sync'])
            ->middleware('permission:tenant.update');

        // Short routes for Meta Templates
        Route::get('/meta-templates', [MetaTemplateController::class, 'index'])
            ->middleware('permission:tenant.view');
        Route::post('/meta-templates/sync', [MetaTemplateController::class, 'sync'])
            ->middleware('permission:tenant.update');

        Route::get('/message-attachments/{id}/download', [MessageAttachmentController::class, 'download'])
            ->middleware('permission:tenant.view');

        Route::post('/leads/dispositions', [LeadWorkflowController::class, 'dispositionStore'])
            ->middleware('permission:call.initiate');
        Route::get('/leads/callbacks', [LeadWorkflowController::class, 'callbacks'])
            ->middleware('permission:call.view');
        Route::patch('/leads/callbacks/{id}', [LeadWorkflowController::class, 'callbackUpdate'])
            ->middleware('permission:call.initiate');

        Route::get('/lead-lists', [LeadWorkflowController::class, 'listsIndex'])
            ->middleware('permission:call.view');
        Route::post('/lead-lists', [LeadWorkflowController::class, 'listsStore'])
            ->middleware('permission:call.initiate');
        Route::post('/lead-lists/{id}/leads', [LeadWorkflowController::class, 'listsAttachLeads'])
            ->middleware('permission:call.initiate');
        Route::post('/lead-lists/{id}/leads/detach', [LeadWorkflowController::class, 'listsDetachLeads'])
            ->middleware('permission:call.initiate');

        Route::get('/dnc', [LeadWorkflowController::class, 'dncIndex'])
            ->middleware('permission:call.view');
        Route::post('/dnc', [LeadWorkflowController::class, 'dncStore'])
            ->middleware('permission:call.initiate');
        Route::delete('/dnc/{id}', [LeadWorkflowController::class, 'dncDestroy'])
            ->middleware('permission:call.initiate');

        Route::get('/api-tokens', [ApiTokenController::class, 'index'])
            ->middleware('permission:api_token.view');
        Route::post('/api-tokens', [ApiTokenController::class, 'store'])
            ->middleware('permission:api_token.create');
        Route::delete('/api-tokens/{id}', [ApiTokenController::class, 'destroy'])
            ->middleware('permission:api_token.revoke');

        Route::get('/analytics/campaigns', [AnalyticsController::class, 'campaigns'])
            ->middleware('permission:analytics.view');
        Route::get('/analytics/calls', [AnalyticsController::class, 'calls'])
            ->middleware('permission:analytics.view');
        Route::get('/analytics/agents', [AnalyticsController::class, 'agents'])
            ->middleware('permission:analytics.view');
        Route::get('/analytics/dashboard-summary', [AnalyticsController::class, 'dashboardSummary'])
            ->middleware('permission:analytics.view');
        Route::get('/analytics/trends', [AnalyticsController::class, 'trends'])
            ->middleware('permission:analytics.view');
        Route::get('/analytics/heatmap', [AnalyticsController::class, 'heatmap'])
            ->middleware('permission:analytics.view');
        Route::get('/analytics/scorecards', [AnalyticsController::class, 'scorecards'])
            ->middleware('permission:analytics.view');
        Route::get('/analytics/lists', [AnalyticsController::class, 'lists'])
            ->middleware('permission:analytics.view');

        Route::get('/webhooks/overview', [WebhookLogController::class, 'overview'])
            ->middleware('permission:webhook.view');
        Route::post('/webhooks/replay', [WebhookLogController::class, 'replay'])
            ->middleware('permission:webhook.update');
        Route::get('/webhooks/delivery-logs', [WebhookLogController::class, 'index'])
            ->middleware('permission:webhook.view');

        Route::get('/notifications', [NotificationController::class, 'index'])
            ->middleware('permission:tenant.view');
        Route::patch('/notifications/{id}/read', [NotificationController::class, 'markRead'])
            ->middleware('permission:tenant.view');

        Route::get('/search', [SearchController::class, 'index'])
            ->middleware('permission:tenant.view');

        Route::get('/contacts', [CorePhaseOneController::class, 'contactsIndex'])
            ->middleware('permission:tenant.view');
        Route::post('/contacts', [CorePhaseOneController::class, 'contactsStore'])
            ->middleware('permission:tenant.update');
        Route::patch('/contacts/{id}', [CorePhaseOneController::class, 'contactsUpdate'])
            ->middleware('permission:tenant.update');

        Route::get('/projects', [CorePhaseOneController::class, 'projectsIndex'])
            ->middleware('permission:tenant.view');
        Route::post('/projects', [CorePhaseOneController::class, 'projectsStore'])
            ->middleware('permission:tenant.update');
        Route::patch('/projects/{id}', [CorePhaseOneController::class, 'projectsUpdate'])
            ->middleware('permission:tenant.update');
        Route::post('/projects/{id}/contacts', [CorePhaseOneController::class, 'projectLinkContact'])
            ->middleware('permission:tenant.update');
        Route::post('/projects/{id}/assignments', [CorePhaseOneController::class, 'projectAssignEngineer'])
            ->middleware('permission:tenant.update');

        Route::get('/interaction-context', [CorePhaseOneController::class, 'interactionContext'])
            ->middleware('permission:tenant.view');

        Route::get('/extensions', [CorePhaseOneController::class, 'extensionsIndex'])
            ->middleware('permission:tenant.view');
        Route::post('/extensions', [CorePhaseOneController::class, 'extensionsStore'])
            ->middleware('permission:tenant.update');
        Route::get('/ring-groups', [CorePhaseOneController::class, 'ringGroupsIndex'])
            ->middleware('permission:tenant.view');
        Route::post('/ring-groups', [CorePhaseOneController::class, 'ringGroupsStore'])
            ->middleware('permission:tenant.update');

        Route::get('/voicemail', [CorePhaseOneController::class, 'voicemailIndex'])
            ->middleware('permission:tenant.view');
        Route::post('/voicemail', [CorePhaseOneController::class, 'voicemailStore'])
            ->middleware('permission:tenant.update');

        Route::get('/inbox/threads', [CorePhaseOneController::class, 'threadsIndex'])
            ->middleware('permission:tenant.view');
        Route::patch('/inbox/threads/{threadId}', [CorePhaseOneController::class, 'threadUpdate'])
            ->middleware('permission:tenant.update');
        Route::post('/inbox/threads/{threadId}/messages', [CorePhaseOneController::class, 'threadsSendMessage'])
            ->middleware('permission:tenant.update');
        Route::get('/inbox/threads/{threadId}/messages', [CorePhaseOneController::class, 'threadsMessagesIndex'])
            ->middleware('permission:tenant.view');
        Route::get('/inbox/sla-policy', [CorePhaseOneController::class, 'inboxSlaPolicyShow'])
            ->middleware('permission:tenant.view');
        Route::post('/inbox/sla-policy', [CorePhaseOneController::class, 'inboxSlaPolicyUpsert'])
            ->middleware('permission:tenant.update');
        Route::post('/inbox/whatsapp-opt-in', [CorePhaseOneController::class, 'whatsappOptInUpdate'])
            ->middleware('permission:tenant.update');

        Route::post('/whatsapp-debug/send-test', [CorePhaseOneController::class, 'whatsappDebugSendTest'])
            ->middleware('permission:tenant.update');
        Route::get('/whatsapp-debug/delivery-inspector', [CorePhaseOneController::class, 'whatsappDebugDeliveryInspector'])
            ->middleware('permission:tenant.view');

        Route::post('/ai/reception/handle', [CorePhaseOneController::class, 'aiReceptionHandleMock'])
            ->middleware('permission:tenant.view');
        Route::post('/integrations/graph/availability', [CorePhaseOneController::class, 'graphAvailabilityMock'])
            ->middleware('permission:tenant.view');
        Route::post('/integrations/graph/book', [CorePhaseOneController::class, 'graphBookMock'])
            ->middleware('permission:tenant.update');
        Route::get('/integrations/graph/bookings', [CorePhaseOneController::class, 'graphBookingsIndex'])
            ->middleware('permission:tenant.view');
        Route::get('/integrations/graph/bookings/{id}', [CorePhaseOneController::class, 'graphBookingShow'])
            ->middleware('permission:tenant.view');
        Route::patch('/integrations/graph/bookings/{id}', [CorePhaseOneController::class, 'graphBookingUpdate'])
            ->middleware('permission:tenant.update');
        Route::post('/integrations/graph/bookings/{id}/cancel', [CorePhaseOneController::class, 'graphBookingCancel'])
            ->middleware('permission:tenant.update');

        Route::get('/integrations/teams/approvals', [CorePhaseOneController::class, 'teamsApprovalsIndex'])
            ->middleware('permission:tenant.view');
        Route::post('/integrations/teams/approvals', [CorePhaseOneController::class, 'teamsApprovalCreate'])
            ->middleware('permission:tenant.update');
        Route::post('/integrations/teams/approvals/{id}/respond', [CorePhaseOneController::class, 'teamsApprovalRespond'])
            ->middleware('permission:tenant.update');
        Route::get('/automation/workflows', [CorePhaseOneController::class, 'workflowsIndex'])
            ->middleware('permission:tenant.view');
        Route::post('/automation/workflows', [CorePhaseOneController::class, 'workflowsStore'])
            ->middleware('permission:tenant.update');
        Route::post('/automation/workflows/run', [CorePhaseOneController::class, 'workflowRunMock'])
            ->middleware('permission:tenant.update');
        Route::get('/reporting/unified', [CorePhaseOneController::class, 'unifiedReportingMock'])
            ->middleware('permission:analytics.view');
        Route::get('/features/planned/status', [CorePhaseOneController::class, 'plannedFeatureStatus'])
            ->middleware('permission:tenant.view');
        Route::post('/governance/retention-policy', [CorePhaseOneController::class, 'governanceRetentionMock'])
            ->middleware('permission:tenant.update');
        Route::get('/governance/retention-policy', [CorePhaseOneController::class, 'governanceRetentionShow'])
            ->middleware('permission:tenant.view');
        Route::get('/governance/legal-holds', [CorePhaseOneController::class, 'governanceLegalHoldsIndex'])
            ->middleware('permission:tenant.view');
        Route::post('/governance/legal-holds', [CorePhaseOneController::class, 'governanceLegalHoldStore'])
            ->middleware('permission:tenant.update');
        Route::patch('/governance/legal-holds/{id}/release', [CorePhaseOneController::class, 'governanceLegalHoldRelease'])
            ->middleware('permission:tenant.update');
        Route::get('/governance/dsr', [GovernanceComplianceController::class, 'dsrIndex'])
            ->middleware('permission:tenant.view');
        Route::post('/governance/dsr', [GovernanceComplianceController::class, 'dsrStore'])
            ->middleware('permission:tenant.update');
        Route::patch('/governance/dsr/{id}/approve', [GovernanceComplianceController::class, 'dsrApprove'])
            ->middleware('permission:tenant.update');
        Route::get('/governance/dsr/{id}/download', [GovernanceComplianceController::class, 'dsrDownload'])
            ->middleware('permission:tenant.view');
        Route::post('/governance/drill', [CorePhaseOneController::class, 'governanceDrillMock'])
            ->middleware('permission:tenant.update');
        Route::get('/governance/drills', [CorePhaseOneController::class, 'governanceDrillsIndex'])
            ->middleware('permission:tenant.view');
        Route::get('/system/logs', [OperationalHealthController::class, 'logs'])
            ->middleware('permission:tenant.view');
    });
});

Route::match(['GET', 'POST'], '/sandbox/third-party/{service}/{path?}', [SandboxThirdPartyController::class, 'handle'])
    ->where('path', '.*');

Route::match(['GET', 'POST'], '/webhooks/twilio/twiml/outbound', function (\Illuminate\Http\Request $request) {
    $callSessionId = (string) $request->query('call_session_id', '');
    $token = (string) $request->query('token', '');

    if ($callSessionId === '') {
        $twiml = implode('', [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<Response>',
            '<Say voice="alice">Please hold while we connect your call.</Say>',
            '<Pause length="60"/>',
            '</Response>',
        ]);

        return response($twiml, 200, ['Content-Type' => 'text/xml']);
    }

    $call = \App\Models\CallSession::query()->where('id', $callSessionId)->first();
    if (! $call) {
        return response('<?xml version="1.0" encoding="UTF-8"?><Response><Hangup/></Response>', 200, ['Content-Type' => 'text/xml']);
    }

    $metadata = (array) ($call->metadata ?? []);
    $dialMode = (string) ($metadata['dial_mode'] ?? 'normal');
    $expected = (string) ($metadata['twiml_token'] ?? '');
    if ($expected !== '' && ! hash_equals($expected, $token)) {
        return response('<?xml version="1.0" encoding="UTF-8"?><Response><Hangup/></Response>', 200, ['Content-Type' => 'text/xml']);
    }

    if ($dialMode === 'missed_call') {
        $twiml = implode('', [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<Response>',
            '<Pause length="2"/>',
            '<Hangup/>',
            '</Response>',
        ]);

        return response($twiml, 200, ['Content-Type' => 'text/xml']);
    }

    if ($dialMode === 'auto_dialer') {
        $prompt = (string) ($metadata['tts_prompt'] ?? 'Press 1 if you are interested.');
        $actionUrl = url('/api/webhooks/twilio/gather-result?call_session_id=' . $callSessionId);
        $twiml = implode('', [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<Response>',
            '<Gather numDigits="1" action="' . htmlspecialchars($actionUrl, ENT_QUOTES) . '" method="POST">',
            '<Say voice="alice">' . htmlspecialchars($prompt, ENT_QUOTES) . '</Say>',
            '</Gather>',
            '<Say voice="alice">No input received. Goodbye.</Say>',
            '<Hangup/>',
            '</Response>',
        ]);

        return response($twiml, 200, ['Content-Type' => 'text/xml']);
    }

    $agentId = (string) ($metadata['agent_id'] ?? '');
    if ($agentId === '') {
        $twiml = '<?xml version="1.0" encoding="UTF-8"?><Response><Say voice="alice">No agent is assigned for this call.</Say><Hangup/></Response>';

        return response($twiml, 200, ['Content-Type' => 'text/xml']);
    }

    $agent = \App\Models\Agent::query()
        ->where('tenant_id', $call->tenant_id)
        ->where('id', $agentId)
        ->first();
    $destination = (string) ((array) ($agent?->metadata ?? []))['destination_number'] ?? '';
    if ($destination === '') {
        $twiml = '<?xml version="1.0" encoding="UTF-8"?><Response><Say voice="alice">No agent destination is configured.</Say><Hangup/></Response>';

        return response($twiml, 200, ['Content-Type' => 'text/xml']);
    }

    $callerId = htmlspecialchars((string) ($call->from_number ?? ''), ENT_QUOTES);
    $dest = htmlspecialchars($destination, ENT_QUOTES);
    $twiml = implode('', [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<Response>',
        '<Say voice="alice">Connecting you now.</Say>',
        '<Dial timeout="25" callerId="' . $callerId . '">',
        '<Number>' . $dest . '</Number>',
        '</Dial>',
        '</Response>',
    ]);

    return response($twiml, 200, ['Content-Type' => 'text/xml']);
});

Route::get('/webhooks/vonage/ncco/outbound', function (\Illuminate\Http\Request $request) {
    $callSessionId = (string) $request->query('call_session_id', '');

    return response()->json([
        [
            'action' => 'talk',
            'text' => 'Please hold while we connect your call.',
        ],
        [
            'action' => 'conversation',
            'name' => $callSessionId !== '' ? 'call_' . $callSessionId : 'call_hold',
            'startOnEnter' => true,
            'endOnExit' => true,
        ],
    ]);
});

Route::post('/webhooks/twilio/voice', [TwilioVoiceWebhookController::class, 'inbound']);
Route::post('/webhooks/twilio/voice/voicemail', [TwilioVoiceWebhookController::class, 'voicemail']);
Route::post('/webhooks/twilio/voice/transfer', [TwilioVoiceWebhookController::class, 'transfer']);
Route::post('/webhooks/twilio/gather-result', [TwilioVoiceWebhookController::class, 'gatherResult']);
Route::post('/webhooks/twilio', [ProviderWebhookController::class, 'twilio']);
Route::post('/webhooks/vonage', [ProviderWebhookController::class, 'vonage']);
Route::match(['GET', 'POST'], '/webhooks/teams/approvals/{id}/respond', [CorePhaseOneController::class, 'teamsApprovalWebhookRespond']);
Route::post('/v1/webhooks/twilio/sms', [MessagingController::class, 'webhookSms']);
Route::post('/v1/webhooks/twilio/whatsapp', [MessagingController::class, 'webhookWhatsapp']);
Route::post('/v1/webhooks/twilio/message-status', [MessagingController::class, 'webhookMessageStatus']);
Route::match(['GET', 'POST'], '/v1/webhooks/meta/whatsapp', [MessagingController::class, 'webhookMetaWhatsapp']);
Route::post('/webhooks/sms/mock', [CorePhaseOneController::class, 'inboundSmsMock']);
Route::post('/webhooks/whatsapp/mock', [CorePhaseOneController::class, 'inboundWhatsappMock']);
Route::post('/integrations/teams/mock-notify', [CorePhaseOneController::class, 'teamsNotifyMock']);
Route::get('/health/live', [OperationalHealthController::class, 'liveness']);
Route::get('/health/ready', [OperationalHealthController::class, 'readiness']);
