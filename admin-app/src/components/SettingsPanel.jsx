import React, { useEffect, useState } from 'react';
import {
  getSettings, saveSettings, testConnection, getUsage,
  activateLicense, deactivateLicense, getLicenseStatus,
} from '../api';

const TIER   = window.AiWoo?.tier ?? 'free';
const IS_PRO = TIER === 'pro' || TIER === 'scale';

const OPENAI_MODELS = [
  { value: 'gpt-4o-mini',   label: 'GPT-4o Mini (recommended)' },
  { value: 'gpt-4o',        label: 'GPT-4o' },
  { value: 'gpt-3.5-turbo', label: 'GPT-3.5 Turbo' },
];

const ANTHROPIC_MODELS = [
  { value: 'claude-haiku-4-5-20251001', label: 'Claude Haiku 4.5 (recommended)' },
  { value: 'claude-sonnet-4-5',         label: 'Claude Sonnet 4.5' },
  { value: 'claude-opus-4-5',           label: 'Claude Opus 4.5' },
];

const GROQ_MODELS = [
  { value: 'llama-3.3-70b-versatile', label: 'Llama 3.3 70B (recommended)' },
  { value: 'llama-3.1-8b-instant',    label: 'Llama 3.1 8B (fast)' },
  { value: 'gemma2-9b-it',            label: 'Gemma 2 9B' },
  { value: 'mixtral-8x7b-32768',      label: 'Mixtral 8x7B' },
];

const GEMINI_MODELS = [
  { value: 'gemini-2.0-flash', label: 'Gemini 2.0 Flash (recommended)' },
  { value: 'gemini-1.5-flash', label: 'Gemini 1.5 Flash' },
  { value: 'gemini-1.5-pro',   label: 'Gemini 1.5 Pro' },
];

const PROVIDER_META = {
  openai:    { label: 'OpenAI',    hint: 'Get your key at platform.openai.com' },
  anthropic: { label: 'Anthropic', hint: 'Get your key at console.anthropic.com' },
  groq:      { label: 'Groq',      hint: 'Free API key at console.groq.com' },
  gemini:    { label: 'Gemini',    hint: 'Free API key at aistudio.google.com' },
};

const PROVIDER_INFO = {
  groq:      { label: 'Free API key (no billing needed)', color: 'text-green-600 dark:text-green-400' },
  openai:    { label: 'Paid API key (billing required)',  color: 'text-amber-600 dark:text-amber-400' },
  anthropic: { label: 'Paid API key (billing required)',  color: 'text-amber-600 dark:text-amber-400' },
  gemini:    { label: 'Paid API key (billing required)',  color: 'text-amber-600 dark:text-amber-400' },
};

const TIER_COLORS = {
  free:    'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
  starter: 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300',
  pro:     'bg-indigo-100 text-indigo-700 dark:bg-indigo-900 dark:text-indigo-300',
  scale:   'bg-emerald-100 text-emerald-700 dark:bg-emerald-900 dark:text-emerald-300',
};

const INPUT_CLS =
  'w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 text-sm ' +
  'bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-100 ' +
  'placeholder-gray-400 dark:placeholder-gray-500 ' +
  'focus:outline-none focus:ring-2 focus:ring-indigo-500';

const LABEL_CLS = 'block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1';

export default function SettingsPanel() {
  const [provider, setProvider]               = useState('openai');
  const [apiKey, setApiKey]                   = useState('');
  const [savedKeyMask, setSavedKeyMask]       = useState('');
  const [openaiModel, setOpenaiModel]         = useState('gpt-4o-mini');
  const [anthropicModel, setAnthropicModel]   = useState('claude-haiku-4-5-20251001');
  const [groqModel, setGroqModel]             = useState('llama-3.3-70b-versatile');
  const [geminiModel, setGeminiModel]         = useState('gemini-2.0-flash');
  const [loading, setLoading]                 = useState(true);
  const [saving, setSaving]                   = useState(false);
  const [saveSuccess, setSaveSuccess]         = useState(false);
  const [saveError, setSaveError]             = useState(null);
  const [testLoading, setTestLoading]         = useState(false);
  const [testResult, setTestResult]           = useState(null);
  const [usage, setUsage]                     = useState(null);
  const [isDirty, setIsDirty]                 = useState(false);
  const [autopilotMode, setAutopilotMode]     = useState('off');

  // License state
  const [licenseKey, setLicenseKey]           = useState('');
  const [licenseStatus, setLicenseStatus]     = useState(null);
  const [licenseLoading, setLicenseLoading]   = useState(false);
  const [licenseError, setLicenseError]       = useState(null);
  const [licenseSuccess, setLicenseSuccess]   = useState(null);

  useEffect(() => {
    Promise.all([getSettings(), getUsage()])
      .then(([settings, usageData]) => {
        setProvider(settings.provider ?? 'openai');
        setOpenaiModel(settings.openai_model ?? 'gpt-4o-mini');
        setAnthropicModel(settings.anthropic_model ?? 'claude-haiku-4-5-20251001');
        setGroqModel(settings.groq_model ?? 'llama-3.3-70b-versatile');
        setGeminiModel(settings.gemini_model ?? 'gemini-2.0-flash');
        // Store the partial mask returned by PHP (e.g. "gsk_uxHU••••••••") as placeholder
        const returnedKey = settings.api_key ?? '';
        if (returnedKey.endsWith('••••••••')) {
          setSavedKeyMask(returnedKey);
        }
        setAutopilotMode(settings.autopilot_mode ?? 'off');
        setUsage(usageData);
      })
      .catch(() => {})
      .finally(() => setLoading(false));

    // Load license status — only available on Pro/Scale (endpoint not registered on Free)
    if (IS_PRO) {
      getLicenseStatus()
        .then((data) => setLicenseStatus(data))
        .catch(() => {});
    }
  }, []);

  // Wrappers that mark the form dirty whenever any field changes
  function markDirty(setter) {
    return (val) => { setter(val); setIsDirty(true); };
  }

  async function handleSave(e) {
    e.preventDefault();
    setSaving(true);
    setSaveSuccess(false);
    setSaveError(null);
    try {
      await saveSettings({
        provider,
        // Only send api_key if the user actually typed something new
        ...(apiKey ? { api_key: apiKey } : {}),
        openai_model:    openaiModel,
        anthropic_model: anthropicModel,
        groq_model:      groqModel,
        gemini_model:    geminiModel,
        autopilot_mode:  autopilotMode,
      });
      setSaveSuccess(true);
      setApiKey('');
      setIsDirty(false);
      // Refresh the mask after saving so it reflects the newly stored key
      if (apiKey) {
        setSavedKeyMask(apiKey.slice(0, 8) + '••••••••');
      }
      setTimeout(() => setSaveSuccess(false), 4000);
    } catch (err) {
      setSaveError(err?.response?.data?.message ?? err.message ?? 'Save failed');
    } finally {
      setSaving(false);
    }
  }

  async function handleTestConnection() {
    setTestLoading(true);
    setTestResult(null);
    try {
      const result = await testConnection();
      setTestResult({ success: result.success, message: result.message });
    } catch (err) {
      setTestResult({
        success: false,
        message: err?.response?.data?.message ?? err.message ?? 'Connection test failed',
      });
    } finally {
      setTestLoading(false);
    }
  }

  async function handleActivateLicense() {
    if (!licenseKey.trim()) return;
    setLicenseLoading(true);
    setLicenseError(null);
    setLicenseSuccess(null);
    try {
      const result = await activateLicense(licenseKey.trim());
      setLicenseStatus(result);
      if (result.status === 'valid') {
        setLicenseSuccess(`License activated! Plan: ${result.tier}`);
        setLicenseKey('');
        // Refresh usage to reflect new tier
        getUsage().then(setUsage).catch(() => {});
      } else {
        setLicenseError(result.message || 'Activation failed.');
      }
    } catch (err) {
      setLicenseError(err?.response?.data?.message ?? err.message ?? 'Activation failed.');
    } finally {
      setLicenseLoading(false);
    }
  }

  async function handleDeactivateLicense() {
    setLicenseLoading(true);
    setLicenseError(null);
    setLicenseSuccess(null);
    try {
      await deactivateLicense();
      setLicenseStatus({ status: 'invalid', tier: 'free', expires: null, message: 'License deactivated.' });
      setLicenseSuccess('License deactivated.');
      getUsage().then(setUsage).catch(() => {});
    } catch (err) {
      setLicenseError(err?.response?.data?.message ?? err.message ?? 'Deactivation failed.');
    } finally {
      setLicenseLoading(false);
    }
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center py-16 text-gray-400 dark:text-gray-500 text-sm">
        Loading settings…
      </div>
    );
  }

  const tierColor  = TIER_COLORS[usage?.tier] ?? TIER_COLORS.free;
  const limitLabel = usage?.limit === null ? '∞' : (usage?.limit ?? '—');

  return (
    <div className="space-y-6 max-w-2xl">

      <form
        onSubmit={handleSave}
        className="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-6 space-y-6"
      >
        {/* ── Provider grid ───────────────────────────────────────────────── */}
        <fieldset>
          <legend className="text-sm font-medium text-gray-700 dark:text-gray-200 mb-3">AI Provider</legend>
          <div className="grid grid-cols-2 gap-3">
            {Object.entries(PROVIDER_META).map(([p, meta]) => (
              <label
                key={p}
                className={`flex flex-col gap-1 px-4 py-3 rounded-lg border cursor-pointer transition-colors ${
                  provider === p
                    ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300'
                    : 'border-gray-200 dark:border-gray-600 text-gray-600 dark:text-gray-300 hover:border-gray-300 dark:hover:border-gray-500'
                }`}
              >
                <input
                  type="radio"
                  name="provider"
                  value={p}
                  checked={provider === p}
                  onChange={() => markDirty(setProvider)(p)}
                  className="sr-only"
                />
                <div className="flex items-center justify-between gap-2 w-full">
                  <span className="text-sm font-medium">{meta.label}</span>
                  {PROVIDER_INFO[p] && (
                    <span className={`text-[10px] font-semibold leading-tight text-right max-w-[min(100%,8rem)] ${PROVIDER_INFO[p].color}`}>
                      {PROVIDER_INFO[p].label}
                    </span>
                  )}
                </div>
              </label>
            ))}
          </div>
          {PROVIDER_INFO[provider] && (
            <p className={`text-xs mt-2 font-medium ${PROVIDER_INFO[provider].color}`}>
              {PROVIDER_INFO[provider].label}
              {provider === 'groq' && (
                <span className="text-gray-400 dark:text-gray-500 font-normal ml-1">
                  — Recommended for getting started
                </span>
              )}
            </p>
          )}
        </fieldset>

        {/* ── API Key ─────────────────────────────────────────────────────── */}
        <div>
          <label className={LABEL_CLS}>API Key</label>
          <input
            type="password"
            value={apiKey}
            onChange={(e) => markDirty(setApiKey)(e.target.value)}
            placeholder={savedKeyMask || 'Enter new API key'}
            autoComplete="new-password"
            className={INPUT_CLS}
          />
          <p className="text-xs text-gray-400 dark:text-gray-500 mt-1">
            {PROVIDER_META[provider]?.hint ?? 'Stored encrypted. Leave blank to keep existing key.'}
          </p>
        </div>

        {/* ── Model selector ──────────────────────────────────────────────── */}
        {provider === 'openai' && (
          <div>
            <label className={LABEL_CLS}>OpenAI Model</label>
            <select value={openaiModel} onChange={(e) => markDirty(setOpenaiModel)(e.target.value)} className={INPUT_CLS}>
              {OPENAI_MODELS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
            </select>
          </div>
        )}
        {provider === 'anthropic' && (
          <div>
            <label className={LABEL_CLS}>Anthropic Model</label>
            <select value={anthropicModel} onChange={(e) => markDirty(setAnthropicModel)(e.target.value)} className={INPUT_CLS}>
              {ANTHROPIC_MODELS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
            </select>
          </div>
        )}
        {provider === 'groq' && (
          <div>
            <label className={LABEL_CLS}>Groq Model</label>
            <select value={groqModel} onChange={(e) => markDirty(setGroqModel)(e.target.value)} className={INPUT_CLS}>
              {GROQ_MODELS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
            </select>
          </div>
        )}
        {provider === 'gemini' && (
          <div>
            <label className={LABEL_CLS}>Gemini Model</label>
            <select value={geminiModel} onChange={(e) => markDirty(setGeminiModel)(e.target.value)} className={INPUT_CLS}>
              {GEMINI_MODELS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
            </select>
          </div>
        )}

        {/* ── Autopilot Mode (Pro/Scale only — not rendered on Free) ─────── */}
        {IS_PRO && (
          <fieldset className="pt-4 border-t border-gray-200 dark:border-gray-600">
            <legend className="text-sm font-medium text-gray-700 dark:text-gray-200">Autopilot Mode</legend>
            <p className="text-xs text-gray-500 dark:text-gray-400 mb-3">Automatically generate SEO when products are saved.</p>
            <div className="space-y-3">
              <label className="flex items-start gap-3 p-3 rounded-lg border cursor-pointer border-gray-200 dark:border-gray-600 hover:border-gray-300 dark:hover:border-gray-500 transition-colors">
                <input
                  type="radio"
                  name="autopilot_mode"
                  value="off"
                  checked={autopilotMode === 'off'}
                  onChange={() => markDirty(setAutopilotMode)('off')}
                  className="mt-0.5"
                />
                <div>
                  <span className="text-sm font-medium text-gray-800 dark:text-gray-200">Off</span>
                  <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Autopilot is disabled. Generate SEO manually.</p>
                </div>
              </label>
              <label className="flex items-start gap-3 p-3 rounded-lg border cursor-pointer border-gray-200 dark:border-gray-600 hover:border-gray-300 dark:hover:border-gray-500 transition-colors">
                <input
                  type="radio"
                  name="autopilot_mode"
                  value="new_only"
                  checked={autopilotMode === 'new_only'}
                  onChange={() => markDirty(setAutopilotMode)('new_only')}
                  className="mt-0.5"
                />
                <div>
                  <span className="text-sm font-medium text-gray-800 dark:text-gray-200">New Products Only</span>
                  <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Auto-generate SEO when a new product is published.</p>
                </div>
              </label>
              <label className="flex items-start gap-3 p-3 rounded-lg border cursor-pointer border-gray-200 dark:border-gray-600 hover:border-gray-300 dark:hover:border-gray-500 transition-colors">
                <input
                  type="radio"
                  name="autopilot_mode"
                  value="all_changes"
                  checked={autopilotMode === 'all_changes'}
                  onChange={() => markDirty(setAutopilotMode)('all_changes')}
                  className="mt-0.5"
                />
                <div>
                  <span className="text-sm font-medium text-gray-800 dark:text-gray-200">All Changes</span>
                  <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Auto-generate SEO whenever any product is saved or updated.</p>
                </div>
              </label>
            </div>
            {autopilotMode === 'all_changes' && (
              <div className="mt-3 px-3 py-2 rounded border border-amber-200 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 text-amber-800 dark:text-amber-200 text-xs">
                ⚠ This will generate SEO on every product save, including quick edits and bulk updates. This uses API credits. Make sure your API key has sufficient quota.
              </div>
            )}
          </fieldset>
        )}

        {/* ── Feedback ────────────────────────────────────────────────────── */}
        {testResult && (
          <div className={`text-sm px-3 py-2 rounded border ${
            testResult.success
              ? 'bg-green-50 dark:bg-green-900/30 border-green-200 dark:border-green-800 text-green-700 dark:text-green-300'
              : 'bg-red-50 dark:bg-red-900/30 border-red-200 dark:border-red-800 text-red-700 dark:text-red-400'
          }`}>
            {testResult.success ? '✓ ' : '✗ '}{testResult.message}
          </div>
        )}
        {saveSuccess && (
          <p className="text-sm text-green-700 dark:text-green-300 bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 rounded px-3 py-2">
            Settings saved successfully.
          </p>
        )}
        {saveError && (
          <p className="text-sm text-red-700 dark:text-red-400 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 rounded px-3 py-2">
            {saveError}
          </p>
        )}

        {/* ── Buttons ─────────────────────────────────────────────────────── */}
        <div className="pt-1 space-y-2">
          <div className="flex gap-3">
            <button
              type="button"
              onClick={handleTestConnection}
              disabled={testLoading || isDirty}
              className="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
            >
              {testLoading ? 'Testing…' : 'Test Connection'}
            </button>
            <button
              type="submit"
              disabled={saving}
              className="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700 disabled:opacity-50 transition-colors"
            >
              {saving ? 'Saving…' : 'Save Settings'}
            </button>
          </div>
          {isDirty && (
            <p className="text-xs text-amber-500">Save your settings first</p>
          )}
        </div>
      </form>



      {/* ── License Key card (Pro/Scale only — not rendered on Free) ─────── */}
      {IS_PRO && (
        <LicenseCard
          licenseKey={licenseKey}
          setLicenseKey={setLicenseKey}
          licenseStatus={licenseStatus}
          licenseLoading={licenseLoading}
          licenseError={licenseError}
          licenseSuccess={licenseSuccess}
          onActivate={handleActivateLicense}
          onDeactivate={handleDeactivateLicense}
          INPUT_CLS={INPUT_CLS}
          LABEL_CLS={LABEL_CLS}
        />
      )}
    </div>
  );
}

// ── License badge helpers ──────────────────────────────────────────────────────

const LICENSE_BADGE = {
  valid:   'bg-green-100  text-green-700  dark:bg-green-900/40  dark:text-green-300',
  expired: 'bg-amber-100  text-amber-700  dark:bg-amber-900/40  dark:text-amber-300',
  invalid: 'bg-gray-100   text-gray-600   dark:bg-gray-700      dark:text-gray-400',
};

const TIER_BADGE = {
  free:    'bg-gray-100   text-gray-700   dark:bg-gray-700      dark:text-gray-300',
  starter: 'bg-blue-100   text-blue-700   dark:bg-blue-900/40   dark:text-blue-300',
  pro:     'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300',
  scale:   'bg-amber-100  text-amber-700  dark:bg-amber-900/40  dark:text-amber-300',
};

function LicenseCard({
  licenseKey, setLicenseKey,
  licenseStatus, licenseLoading,
  licenseError, licenseSuccess,
  onActivate, onDeactivate,
  INPUT_CLS, LABEL_CLS,
}) {
  const isActive = licenseStatus?.status === 'valid';
  const tier     = licenseStatus?.tier ?? 'free';

  return (
    <div className="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-6 space-y-4">
      <div className="flex items-center justify-between">
        <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">License Key</h2>

        {licenseStatus && (
          <div className="flex items-center gap-2">
            <span className={`px-2 py-0.5 rounded-full text-xs font-semibold capitalize ${LICENSE_BADGE[licenseStatus.status] ?? LICENSE_BADGE.invalid}`}>
              {licenseStatus.status}
            </span>
            <span className={`px-2 py-0.5 rounded-full text-xs font-semibold capitalize ${TIER_BADGE[tier] ?? TIER_BADGE.free}`}>
              {tier}
            </span>
          </div>
        )}
      </div>

      {/* Masked key — shown when a key is active */}
      {isActive && licenseStatus?.key_masked && (
        <p className="text-xs font-mono text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded px-3 py-2">
          {licenseStatus.key_masked}
        </p>
      )}

      {/* Expiry date */}
      {isActive && licenseStatus?.expires && (
        <p className="text-xs text-gray-500 dark:text-gray-400">
          Expires: <span className="font-medium">{licenseStatus.expires}</span>
        </p>
      )}

      {/* Activate form — hidden while a key is active */}
      {!isActive && (
        <div className="space-y-2">
          <label className={LABEL_CLS}>Enter License Key</label>
          <input
            type="text"
            value={licenseKey}
            onChange={(e) => setLicenseKey(e.target.value)}
            placeholder="AIWOO-XXXX-XXXX-XXXX-XXXX"
            spellCheck={false}
            autoComplete="off"
            className={INPUT_CLS}
          />
          <button
            type="button"
            onClick={onActivate}
            disabled={licenseLoading || !licenseKey.trim()}
            className="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
          >
            {licenseLoading ? 'Activating…' : 'Activate License'}
          </button>
        </div>
      )}

      {/* Deactivate button — only when a valid key is active */}
      {isActive && (
        <button
          type="button"
          onClick={onDeactivate}
          disabled={licenseLoading}
          className="px-4 py-2 border border-red-300 dark:border-red-700 text-red-600 dark:text-red-400 rounded-md text-sm font-medium hover:bg-red-50 dark:hover:bg-red-900/20 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
        >
          {licenseLoading ? 'Deactivating…' : 'Deactivate License'}
        </button>
      )}

      {/* Feedback */}
      {licenseSuccess && (
        <p className="text-sm text-green-700 dark:text-green-300 bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 rounded px-3 py-2">
          ✓ {licenseSuccess}
        </p>
      )}
      {licenseError && (
        <p className="text-sm text-red-700 dark:text-red-400 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 rounded px-3 py-2">
          ✗ {licenseError}
        </p>
      )}

      {/* Upgrade CTA — only on free tier */}
      {tier === 'free' && (
        <a
          href="https://yourdomain.com/pricing"
          target="_blank"
          rel="noreferrer"
          className="inline-flex items-center gap-1 text-sm font-medium text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-300"
        >
          Upgrade to Pro →
        </a>
      )}
    </div>
  );
}
