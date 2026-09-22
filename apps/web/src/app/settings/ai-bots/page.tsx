"use client";

import { useEffect, useState } from "react";
import { Box, Button, Card, CardContent, Chip, Dialog, DialogActions, DialogContent, DialogTitle, IconButton, Paper, Switch, TextField, ToggleButton, ToggleButtonGroup, Typography } from "@mui/material";
import { AppShell, LoadingState } from "@/components/app-shell";
import { ToastMessage } from "@/components/ui-primitives";
import { createAiBot, deleteAiBot, getTenantAiSettings, listAiBots, saveTenantAiSettings, updateAiBot } from "@/lib/product-api";

export default function AiBotsManagementPage() {
  const [bots, setBots] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [toastMsg, setToastMsg] = useState("");
  const [toastTone, setToastTone] = useState<"neutral" | "success" | "error">("neutral");

  // API Key & Calendar Settings
  const [apiKey, setApiKey] = useState("");
  const [hasApiKey, setHasApiKey] = useState(false);
  const [provider, setProvider] = useState<"openai">("openai");
  const [activeProviderName, setActiveProviderName] = useState("openai");
  const [savingKey, setSavingKey] = useState(false);
  const [savingBot, setSavingBot] = useState(false);

  // Google Calendar API Settings
  const [googleCalendarId, setGoogleCalendarId] = useState("");
  const [googleCalendarJson, setGoogleCalendarJson] = useState("");
  const [hasGoogleCalendarJson, setHasGoogleCalendarJson] = useState(false);
  const [savingGcal, setSavingGcal] = useState(false);

  // Dialog State
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editingBot, setEditingBot] = useState<any | null>(null);

  // Form State
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [systemInstructions, setSystemInstructions] = useState("");
  const [fallbackMessage, setFallbackMessage] = useState("Mujhe iski exact jankari abhi nahi hai, main confirm karke aapko bataunga.");
  const [privacyPolicy, setPrivacyPolicy] = useState("");
  const [customKnowledgePrompt, setCustomKnowledgePrompt] = useState("");
  const [humanDelay, setHumanDelay] = useState(3);
  const [strictMode, setStrictMode] = useState(true);
  const [agentEmail, setAgentEmail] = useState("");
  const [calendarId, setCalendarId] = useState("");

  // Knowledge Base Q&A Array
  const [qaList, setQaList] = useState<{ question: string; answer: string }[]>([
    { question: "2BHK Price & Details", answer: "2BHK flats prime location me ₹45 Lakh se start hain jisme Gym, Parking aur Club House included hai." },
    { question: "3BHK Price & Details", answer: "3BHK luxury flats ₹65 Lakh se start hain 1800 sq ft spacious area ke sath." },
    { question: "Contact Details / Phone Number", answer: "Aap hamare sales team se +91-9876543210 ya office address: Sector 62, Noida par contact kar sakte hain." }
  ]);

  // Interactive Flow Array
  const [flows, setFlows] = useState<{ trigger_keyword: string; question_text: string; options: string }[]>([
    { trigger_keyword: "Interested", question_text: "Aap kitne BHK flat dekhna chahte hain?", options: "2BHK, 3BHK, 4BHK" }
  ]);

  const loadData = async () => {
    setLoading(true);
    try {
      const [botsData, settingsData] = await Promise.all([
        listAiBots(),
        getTenantAiSettings()
      ]);
      setBots(botsData);
      setHasApiKey(settingsData.has_api_key);
      setGoogleCalendarId(settingsData.google_calendar_id || "");
      setHasGoogleCalendarJson(settingsData.has_google_calendar_json || false);
      setProvider("openai");
      setActiveProviderName("openai");
    } catch (err) {
      setToastMsg("Failed to load AI Bots configuration.");
      setToastTone("error");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void loadData();
  }, []);

  const handleSaveApiKey = async () => {
    if (!apiKey.trim()) return;
    setSavingKey(true);
    try {
      await saveTenantAiSettings({ api_key: apiKey.trim(), provider: "openai", default_model: "gpt-4o-mini" });
      setToastMsg("OpenAI API Key saved and encrypted successfully.");
      setToastTone("success");
      setApiKey("");
      void loadData();
    } catch (err) {
      setToastMsg("Failed to save API key.");
      setToastTone("error");
    } finally {
      setSavingKey(false);
    }
  };

  const handleSaveGoogleCalendar = async () => {
    setSavingGcal(true);
    try {
      const payload: any = {
        google_calendar_id: googleCalendarId.trim()
      };
      if (googleCalendarJson.trim()) {
        payload.google_calendar_service_account_json = googleCalendarJson.trim();
      }
      await saveTenantAiSettings(payload);
      setToastMsg("Google Calendar Service Account settings saved successfully.");
      setToastTone("success");
      setGoogleCalendarJson("");
      void loadData();
    } catch (err) {
      setToastMsg("Failed to save Google Calendar settings.");
      setToastTone("error");
    } finally {
      setSavingGcal(false);
    }
  };

  const handleOpenDialog = (bot?: any) => {
    if (bot) {
      setEditingBot(bot);
      setName(bot.name || "");
      setAgentEmail(bot.agent_email || "");
      setCalendarId(bot.calendar_id || "");
      setDescription(bot.description || "");
      setSystemInstructions(bot.system_instructions || "");
      setPrivacyPolicy(bot.privacy_policy || "");
      setCustomKnowledgePrompt(bot.custom_knowledge_prompt || "");
      setFallbackMessage(bot.fallback_message || "");
      setHumanDelay(bot.human_delay_seconds || 3);
      setStrictMode(bot.strict_mode ?? true);

      // Q&A
      if (Array.isArray(bot.knowledge_base)) {
        setQaList(bot.knowledge_base);
      } else {
        setQaList([]);
      }

      // Flows
      if (Array.isArray(bot.interactive_flows)) {
        setFlows(bot.interactive_flows.map((f: any) => ({
          trigger_keyword: f.trigger_keyword || "",
          question_text: f.question_text || "",
          options: Array.isArray(f.options) ? f.options.join(", ") : ""
        })));
      } else {
        setFlows([]);
      }
    } else {
      setEditingBot(null);
      setName("");
      setAgentEmail("");
      setCalendarId("");
      setDescription("");
      setSystemInstructions("Aap ek warm aur helpful Sales Executive ki tarah real person ki bhasha me baat karein. Kabhi robot jaise mat bolna.");
      setPrivacyPolicy("Hum OTP, Passwords, PINs ya Banking Details kisi ke sath share nahi karte aur na puchte hain.");
      setCustomKnowledgePrompt("");
      setFallbackMessage("Mujhe iski exact jankari abhi nahi hai, main confirm karke aapko bataunga.");
      setHumanDelay(3);
      setStrictMode(true);
      setQaList([
        { question: "2BHK Price & Details", answer: "2BHK flats prime location me ₹45 Lakh se start hain jisme Gym, Parking aur Club House included hai." },
        { question: "3BHK Price & Details", answer: "3BHK luxury flats ₹65 Lakh se start hain 1800 sq ft spacious area ke sath." },
        { question: "Contact Details / Phone Number", answer: "Aap hamare sales team se +91-9876543210 ya office address: Sector 62, Noida par contact kar sakte hain." }
      ]);
      setFlows([
        { trigger_keyword: "Interested", question_text: "Aap kitne BHK flat dekhna chahte hain?", options: "2BHK, 3BHK, 4BHK" }
      ]);
    }
    setDialogOpen(true);
  };

  const handleSaveBot = async () => {
    if (!name.trim()) return;
    setSavingBot(true);
    try {
      const payload = {
        name: name.trim(),
        agent_email: agentEmail.trim(),
        calendar_id: calendarId.trim(),
        description: description.trim(),
        system_instructions: systemInstructions.trim(),
        privacy_policy: privacyPolicy.trim(),
        custom_knowledge_prompt: customKnowledgePrompt.trim(),
        fallback_message: fallbackMessage.trim(),
        human_delay_seconds: Number(humanDelay),
        strict_mode: strictMode,
        knowledge_base: qaList.filter(q => q.question.trim() && q.answer.trim()),
        interactive_flows: flows.filter(f => f.trigger_keyword.trim() && f.question_text.trim()).map(f => ({
          trigger_keyword: f.trigger_keyword.trim(),
          question_text: f.question_text.trim(),
          response_type: "button_list",
          options: f.options.split(",").map(o => o.trim()).filter(Boolean)
        }))
      };

      if (editingBot) {
        await updateAiBot(editingBot.id, payload);
        setToastMsg("AI Bot Agent updated successfully.");
      } else {
        await createAiBot(payload);
        setToastMsg("AI Bot Agent created successfully.");
      }
      setToastTone("success");
      setDialogOpen(false);
      void loadData();
    } catch (err: any) {
      setToastMsg(err?.message || "Failed to save AI Bot Agent.");
      setToastTone("error");
    } finally {
      setSavingBot(false);
    }
  };

  const handleDeleteBot = async (id: string) => {
    if (!window.confirm("Are you sure you want to delete this AI Bot Agent?")) return;
    try {
      await deleteAiBot(id);
      setToastMsg("AI Bot Agent deleted.");
      setToastTone("success");
      void loadData();
    } catch (err) {
      setToastMsg("Failed to delete bot agent.");
      setToastTone("error");
    }
  };

  return (
    <AppShell requiredPermissions={["tenant.view"]}>
      {toastMsg ? <ToastMessage tone={toastTone} message={toastMsg} /> : null}

      <Box sx={{ p: { xs: 2, md: 4 }, maxWidth: 1200, margin: '0 auto' }}>

        {/* Page Header */}
        <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 4 }}>
          <Box>
            <Typography variant="h4" sx={{ fontWeight: 700, color: '#1e293b' }}>
              🤖 AI Conversational Agents & Flow Engine
            </Typography>
            <Typography variant="body2" sx={{ color: 'text.secondary', mt: 0.5 }}>
              Configure human-like AI Bot Agents, Knowledge Bases, and Interactive WhatsApp Button Flows for your campaigns.
            </Typography>
          </Box>
          <Button
            variant="contained"
            onClick={() => handleOpenDialog()}
            startIcon={<i className="bx bx-plus" />}
            sx={{ bgcolor: '#6366f1', textTransform: 'none', borderRadius: 2, px: 3, py: 1, fontWeight: 600, '&:hover': { bgcolor: '#4f46e5' } }}
          >
            Create AI Bot Agent
          </Button>
        </Box>

        {/* Tenant API Key Setup Card */}
        <Paper sx={{ p: 3, mb: 4, borderRadius: 3, border: '1px solid #e2e8f0', bgcolor: '#ffffff', boxShadow: '0 4px 12px rgba(0,0,0,0.03)' }}>
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5, mb: 2 }}>
            <i className="bx bx-key" style={{ fontSize: 24, color: '#6366f1' }} />
            <Typography variant="h6" sx={{ fontWeight: 600, color: '#1e293b' }}>
              Tenant OpenAI API Key (ChatGPT)
            </Typography>
            <Chip
              label={hasApiKey ? "Active: OpenAI ChatGPT" : "No API Key Set"}
              color={hasApiKey ? "success" : "warning"}
              size="small"
              sx={{ fontWeight: 600, ml: 'auto' }}
            />
          </Box>
          <Typography variant="body2" sx={{ color: 'text.secondary', mb: 2 }}>
            Aap apni <strong>OpenAI (ChatGPT) API Key</strong> enter kar sakte hain. Keys database me encrypted save hoti hain.
          </Typography>

          <Box sx={{ display: 'flex', gap: 2, maxWidth: 650 }}>
            <TextField
              size="small"
              fullWidth
              type="password"
              placeholder={hasApiKey ? "••••••••••••••••••••••••" : "Enter OpenAI API Key (sk-...)"}
              value={apiKey}
              onChange={(e) => setApiKey(e.target.value)}
              sx={{ '& .MuiOutlinedInput-root': { bgcolor: '#f8fafc', borderRadius: 2 } }}
            />
            <Button
              variant="contained"
              disabled={savingKey || !apiKey.trim()}
              onClick={handleSaveApiKey}
              sx={{ bgcolor: '#1e293b', textTransform: 'none', borderRadius: 2, px: 3, whiteSpace: 'nowrap' }}
            >
              {savingKey ? "Saving..." : "Save OpenAI Key"}
            </Button>
          </Box>
        </Paper>

        {/* Tenant Google Calendar Service Account Setup Card */}
        <Paper sx={{ p: 3, mb: 4, borderRadius: 3, border: '1px solid #e2e8f0', bgcolor: '#ffffff', boxShadow: '0 4px 12px rgba(0,0,0,0.03)' }}>
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5, mb: 2 }}>
            <i className="bx bx-calendar" style={{ fontSize: 24, color: '#059669' }} />
            <Typography variant="h6" sx={{ fontWeight: 600, color: '#1e293b' }}>
              📅 Google Calendar API Integration (Service Account)
            </Typography>
            <Chip
              label={hasGoogleCalendarJson ? "Active: Service Account API" : "Fallback: Web URL Links"}
              color={hasGoogleCalendarJson ? "success" : "default"}
              size="small"
              sx={{ fontWeight: 600, ml: 'auto' }}
            />
          </Box>
          <Typography variant="body2" sx={{ color: 'text.secondary', mb: 2 }}>
            Google Cloud Console se <strong>Service Account JSON Key</strong> aur apna <strong>Default Google Calendar ID</strong> enter karein. Isse AI Agent appointments direct Google Calendar me create karega. <em>(Calendar ko Service Account Email ke sath 'Make changes to events' permission de kar share zaroor karein)</em>.
          </Typography>

          <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2, maxWidth: 750 }}>
            <TextField
              size="small"
              fullWidth
              label="Default Google Calendar ID"
              placeholder="e.g. primary or your-agent@company.com"
              value={googleCalendarId}
              onChange={(e) => setGoogleCalendarId(e.target.value)}
              sx={{ '& .MuiOutlinedInput-root': { bgcolor: '#f8fafc', borderRadius: 2 } }}
            />
            <TextField
              size="small"
              fullWidth
              multiline
              rows={3}
              label="Google Service Account Credentials JSON"
              placeholder={hasGoogleCalendarJson ? "{ \"type\": \"service_account\", \"project_id\": \"...\", \"private_key\": \"...\" } (Leave empty to keep existing encrypted JSON)" : "Paste full contents of Service Account JSON file here..."}
              value={googleCalendarJson}
              onChange={(e) => setGoogleCalendarJson(e.target.value)}
              sx={{ '& .MuiOutlinedInput-root': { bgcolor: '#f8fafc', borderRadius: 2, fontFamily: 'monospace', fontSize: '0.85rem' } }}
            />
            <Button
              variant="contained"
              disabled={savingGcal}
              onClick={handleSaveGoogleCalendar}
              sx={{ bgcolor: '#059669', textTransform: 'none', borderRadius: 2, px: 3, alignSelf: 'flex-start', '&:hover': { bgcolor: '#047857' } }}
            >
              {savingGcal ? "Saving..." : "Save Google Calendar Settings"}
            </Button>
          </Box>
        </Paper>

        {/* AI Bots List */}
        {loading ? (
          <LoadingState label="Loading AI Bot Agents..." />
        ) : bots.length === 0 ? (
          <Paper sx={{ p: 6, textAlign: 'center', borderRadius: 3, bgcolor: '#f8fafc', border: '1px dashed #cbd5e1' }}>
            <i className="bx bx-bot" style={{ fontSize: 48, color: '#94a3b8' }} />
            <Typography variant="h6" sx={{ fontWeight: 600, color: '#475569', mt: 2 }}>
              No AI Bot Agents Configured Yet
            </Typography>
            <Typography variant="body2" sx={{ color: 'text.secondary', mb: 3 }}>
              Create your first AI Bot Agent (e.g. Real Estate Bot or Sabzi/Grocery Bot) to attach to WhatsApp campaigns.
            </Typography>
            <Button
              variant="contained"
              onClick={() => handleOpenDialog()}
              sx={{ bgcolor: '#6366f1', textTransform: 'none', borderRadius: 2 }}
            >
              Create AI Bot Agent
            </Button>
          </Paper>
        ) : (
          <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', md: 'repeat(2, 1fr)' }, gap: 3 }}>
            {bots.map((bot) => (
              <Card key={bot.id} sx={{ borderRadius: 3, border: '1px solid #e2e8f0', boxShadow: '0 4px 12px rgba(0,0,0,0.03)' }}>
                <CardContent sx={{ p: 3 }}>
                  <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', mb: 2 }}>
                    <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5 }}>
                      <Box sx={{ width: 44, height: 44, borderRadius: 2, bgcolor: '#eef2ff', color: '#6366f1', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                        <i className="bx bx-bot" style={{ fontSize: 24 }} />
                      </Box>
                      <Box>
                        <Typography variant="h6" sx={{ fontWeight: 600, color: '#1e293b' }}>
                          {bot.name}
                        </Typography>
                        <Typography variant="caption" sx={{ color: 'text.secondary' }}>
                          Delay: {bot.human_delay_seconds ?? 3}s | Strict Knowledge Lock: {bot.strict_mode ? 'ON' : 'OFF'}
                        </Typography>
                      </Box>
                    </Box>
                    <Box sx={{ display: 'flex', gap: 0.5 }}>
                      <IconButton size="small" onClick={() => handleOpenDialog(bot)} sx={{ color: '#6366f1' }}>
                        <i className="bx bx-edit" />
                      </IconButton>
                      <IconButton size="small" onClick={() => handleDeleteBot(bot.id)} sx={{ color: '#ef4444' }}>
                        <i className="bx bx-trash" />
                      </IconButton>
                    </Box>
                  </Box>

                  {bot.description && (
                    <Typography variant="body2" sx={{ color: 'text.secondary', mb: 2 }}>
                      {bot.description}
                    </Typography>
                  )}

                  <Box sx={{ display: 'flex', gap: 1, flexWrap: 'wrap', mb: 2 }}>
                    <Chip
                      icon={<i className="bx bx-book-open" style={{ fontSize: 16 }} />}
                      label={`${Array.isArray(bot.knowledge_base) ? bot.knowledge_base.length : 0} Q&A Pairs`}
                      size="small"
                      sx={{ bgcolor: '#f1f5f9' }}
                    />
                    {bot.custom_knowledge_prompt && (
                      <Chip
                        icon={<i className="bx bx-file" style={{ fontSize: 16 }} />}
                        label="Master Prompt Set"
                        size="small"
                        color="primary"
                        variant="outlined"
                      />
                    )}
                    {bot.agent_email && (
                      <Chip
                        icon={<i className="bx bx-envelope" style={{ fontSize: 16 }} />}
                        label={`Calendar Email: ${bot.agent_email}`}
                        size="small"
                        color="secondary"
                        variant="outlined"
                      />
                    )}
                    {bot.privacy_policy && (
                      <Chip
                        icon={<i className="bx bx-shield-quarter" style={{ fontSize: 16 }} />}
                        label="Privacy Rules Set"
                        size="small"
                        color="success"
                        variant="outlined"
                      />
                    )}
                    <Chip
                      icon={<i className="bx bx-git-repo-forked" style={{ fontSize: 16 }} />}
                      label={`${Array.isArray(bot.interactive_flows) ? bot.interactive_flows.length : 0} Interactive Button Flows`}
                      size="small"
                      sx={{ bgcolor: '#f1f5f9' }}
                    />
                  </Box>

                  <Typography variant="caption" sx={{ color: 'text.secondary', display: 'block', bgcolor: '#f8fafc', p: 1.5, borderRadius: 2, border: '1px solid #f1f5f9' }}>
                    <strong>Fallback Message:</strong> "{bot.fallback_message}"
                  </Typography>
                </CardContent>
              </Card>
            ))}
          </Box>
        )}

        {/* Create / Edit Dialog */}
        <Dialog open={dialogOpen} onClose={() => setDialogOpen(false)} maxWidth="md" fullWidth>
          <DialogTitle sx={{ fontWeight: 700, color: '#1e293b' }}>
            {editingBot ? "Edit AI Bot Agent" : "Create New AI Bot Agent"}
          </DialogTitle>
          <DialogContent dividers sx={{ display: 'flex', flexDirection: 'column', gap: 2.5, py: 3 }}>
            
            <Box sx={{ display: 'flex', gap: 2, flexWrap: 'wrap' }}>
              <TextField
                sx={{ flex: 1, minWidth: 200 }}
                label="Bot Agent Name"
                placeholder="e.g. Real Estate Sales Manager"
                value={name}
                onChange={(e) => setName(e.target.value)}
              />
              <TextField
                sx={{ flex: 1, minWidth: 200 }}
                label="📅 Agent Email (Calendar Invites)"
                placeholder="e.g. agent@company.com"
                value={agentEmail}
                onChange={(e) => setAgentEmail(e.target.value)}
              />
              <TextField
                sx={{ flex: 1, minWidth: 200 }}
                label="📅 Bot Specific Calendar ID (Optional)"
                placeholder="e.g. agent-calendar-id@group.calendar.google.com"
                value={calendarId}
                onChange={(e) => setCalendarId(e.target.value)}
              />
              <TextField
                type="number"
                label="Human Delay (s)"
                value={humanDelay}
                onChange={(e) => setHumanDelay(Math.max(0, Number(e.target.value)))}
                inputProps={{ min: 0, max: 86400 }}
                helperText="Delay in seconds"
                sx={{ width: 140 }}
              />
            </Box>

            <TextField
              fullWidth
              label="Short Description / Scope"
              placeholder="e.g. Handles 2BHK/3BHK inquiries and site visit scheduling"
              value={description}
              onChange={(e) => setDescription(e.target.value)}
            />

            <TextField
              fullWidth
              multiline
              rows={3}
              label="Human Persona & Tone Instructions"
              placeholder="Aap ek warm aur helpful Sales Executive ki tarah real person ki bhasha me baat karein. Robot jaise mat bolna."
              value={systemInstructions}
              onChange={(e) => setSystemInstructions(e.target.value)}
            />

            <TextField
              fullWidth
              multiline
              rows={2}
              label="🔒 Privacy Policy & Security Rules"
              placeholder="e.g. Hum OTP, Passwords, PINs ya Banking Details kisi ke sath share nahi karte aur na puchte hain."
              helperText="Security rules for handling sensitive customer data, OTPs, or privacy terms for this bot agent."
              value={privacyPolicy}
              onChange={(e) => setPrivacyPolicy(e.target.value)}
            />

            <TextField
              fullWidth
              multiline
              rows={5}
              label="📝 Master Custom Knowledge Base Prompt (Large Prompt Box)"
              placeholder="Yahan aap ek ek Q&A add karne ke bajaaye direct poora custom prompt, project documentation, brochure text, ya rules paste kar sakte hain."
              helperText="Paste a large custom knowledge base prompt or raw documentation text directly for this AI Agent."
              value={customKnowledgePrompt}
              onChange={(e) => setCustomKnowledgePrompt(e.target.value)}
            />

            <TextField
              fullWidth
              label="Out-of-Scope Fallback Message"
              placeholder="Mujhe iski exact jankari abhi nahi hai, main confirm karke aapko bataunga."
              value={fallbackMessage}
              onChange={(e) => setFallbackMessage(e.target.value)}
            />

            <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', p: 2, bgcolor: '#f8fafc', borderRadius: 2, border: '1px solid #e2e8f0' }}>
              <Box>
                <Typography variant="subtitle2" sx={{ fontWeight: 600 }}>Strict Knowledge Base Lock</Typography>
                <Typography variant="caption" sx={{ color: 'text.secondary' }}>When ON, AI will strictly reply ONLY using the Q&A below. Extra outside answers are forbidden.</Typography>
              </Box>
              <Switch checked={strictMode} onChange={(e) => setStrictMode(e.target.checked)} color="primary" />
            </Box>

            {/* Knowledge Base Q&A Manager */}
            <Box>
              <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 1 }}>
                <Typography variant="subtitle1" sx={{ fontWeight: 600, color: '#1e293b' }}>
                  📚 Knowledge Base Q&A Pairs
                </Typography>
                <Button size="small" onClick={() => setQaList([...qaList, { question: "", answer: "" }])}>
                  + Add Q&A
                </Button>
              </Box>

              {qaList.map((qa, index) => (
                <Box key={index} sx={{ display: 'flex', gap: 1.5, mb: 1.5, alignItems: 'flex-start' }}>
                  <TextField
                    size="small"
                    placeholder="Topic / Question (e.g. 2BHK Price)"
                    value={qa.question}
                    onChange={(e) => {
                      const updated = [...qaList];
                      updated[index].question = e.target.value;
                      setQaList(updated);
                    }}
                    sx={{ width: '35%' }}
                  />
                  <TextField
                    size="small"
                    fullWidth
                    placeholder="Answer / Details (e.g. 2BHK starts from ₹45 Lakhs with Amenities)"
                    value={qa.answer}
                    onChange={(e) => {
                      const updated = [...qaList];
                      updated[index].answer = e.target.value;
                      setQaList(updated);
                    }}
                  />
                  <IconButton size="small" color="error" onClick={() => setQaList(qaList.filter((_, i) => i !== index))}>
                    <i className="bx bx-x" />
                  </IconButton>
                </Box>
              ))}
            </Box>

            {/* Interactive WhatsApp Button Flow Manager */}
            <Box>
              <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 1 }}>
                <Typography variant="subtitle1" sx={{ fontWeight: 600, color: '#1e293b' }}>
                  ⚡ Interactive WhatsApp Quick Reply Button Flows
                </Typography>
                <Button size="small" onClick={() => setFlows([...flows, { trigger_keyword: "", question_text: "", options: "" }])}>
                  + Add Button Flow
                </Button>
              </Box>

              {flows.map((flow, index) => (
                <Box key={index} sx={{ p: 2, border: '1px solid #e2e8f0', borderRadius: 2, mb: 1.5, bgcolor: '#f8fafc', display: 'flex', flexDirection: 'column', gap: 1.5 }}>
                  <Box sx={{ display: 'flex', gap: 1.5, alignItems: 'center' }}>
                    <TextField
                      size="small"
                      label="Trigger Keyword"
                      placeholder="e.g. Interested"
                      value={flow.trigger_keyword}
                      onChange={(e) => {
                        const updated = [...flows];
                        updated[index].trigger_keyword = e.target.value;
                        setFlows(updated);
                      }}
                      sx={{ width: 220 }}
                    />
                    <TextField
                      size="small"
                      fullWidth
                      label="Question Text"
                      placeholder="Aap kitne BHK flat dekhna chahte hain?"
                      value={flow.question_text}
                      onChange={(e) => {
                        const updated = [...flows];
                        updated[index].question_text = e.target.value;
                        setFlows(updated);
                      }}
                    />
                    <IconButton size="small" color="error" onClick={() => setFlows(flows.filter((_, i) => i !== index))}>
                      <i className="bx bx-trash" />
                    </IconButton>
                  </Box>

                  <TextField
                    size="small"
                    fullWidth
                    label="Button Options (Comma Separated)"
                    placeholder="2BHK, 3BHK, 4BHK"
                    value={flow.options}
                    onChange={(e) => {
                      const updated = [...flows];
                      updated[index].options = e.target.value;
                      setFlows(updated);
                    }}
                  />
                </Box>
              ))}
            </Box>

          </DialogContent>
          <DialogActions sx={{ p: 2.5 }}>
            <Button disabled={savingBot} onClick={() => setDialogOpen(false)} sx={{ textTransform: 'none' }}>Cancel</Button>
            <Button disabled={savingBot} variant="contained" onClick={handleSaveBot} sx={{ bgcolor: '#6366f1', textTransform: 'none', px: 3 }}>
              {savingBot ? "Saving..." : "Save AI Bot Agent"}
            </Button>
          </DialogActions>
        </Dialog>

      </Box>
    </AppShell>
  );
}
