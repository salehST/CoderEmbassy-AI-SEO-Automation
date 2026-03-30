import React, { useState, useEffect, useRef } from 'react';
import axios from 'axios';
import { createJob, previewProduct, getSettings, estimateCost, listRules } from '../api';

const IS_PRO = (() => { const t = window.AiWoo?.tier ?? 'free'; return t === 'pro' || t === 'scale'; })();

const INPUT_CLS =
  'w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 text-sm ' +
  'bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-100 ' +
  'placeholder-gray-400 dark:placeholder-gray-500 ' +
  'focus:outline-none focus:ring-2 focus:ring-indigo-500';

function PreviewCard({ preview }) {
  if (!preview) return null;
  return (
    <div className="border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4 text-sm space-y-2">
      <div>
        <span className="font-medium text-gray-600 dark:text-gray-300">Title:</span>{' '}
        <span className="text-gray-800 dark:text-gray-100">{preview.title || '—'}</span>
      </div>
      <div>
        <span className="font-medium text-gray-600 dark:text-gray-300">Meta:</span>{' '}
        <span className="text-gray-500 dark:text-gray-400">{preview.meta || '—'}</span>
      </div>
      {preview.alt?.length > 0 && (
        <div>
          <span className="font-medium text-gray-600 dark:text-gray-300">Alt:</span>{' '}
          <span className="text-gray-500 dark:text-gray-400">{preview.alt[0]}</span>
        </div>
      )}
    </div>
  );
}

export default function BulkGenerator({ onJobCreated, onNavigate }) {
  const [jobName, setJobName]               = useState('');
  const [rule, setRule]                     = useState('');
  const [selectedCategories, setSelectedCategories] = useState([]); // { id, name, count }
  const [allCategories, setAllCategories]       = useState(false);
  const [storeProductCount, setStoreProductCount] = useState(0);
  const [categorySearch, setCategorySearch]       = useState('');
  const [categoryResults, setCategoryResults]   = useState([]);
  const [categorySearchLoading, setCategorySearchLoading] = useState(false);
  const categorySearchTimer                       = useRef(null);
  const [addedProducts, setAddedProducts]       = useState([]);
  const [excludedProducts, setExcludedProducts] = useState([]);
  const [productSearch, setProductSearch]       = useState('');
  const [searchResults, setSearchResults]       = useState([]);
  const [searchLoading, setSearchLoading]       = useState(false);
  const searchTimer                             = useRef(null);
  const [previews, setPreviews]             = useState([]);
  const [previewLoading, setPreviewLoading] = useState(false);
  const [previewError, setPreviewError]     = useState(null);
  const [skipExisting, setSkipExisting]     = useState(false);
  const [submitting, setSubmitting]         = useState(false);
  const [submitError, setSubmitError]       = useState(null);
  const [rules, setRules]                   = useState([]);
  const [rulesLoading, setRulesLoading]     = useState(true);

  // Cost estimate
  const [estimate, setEstimate]             = useState(null);
  const [estimateLoading, setEstimateLoading] = useState(false);
  const [currentProvider, setCurrentProvider] = useState('openai');
  const [currentModel, setCurrentModel]     = useState('gpt-4o-mini');
  const estimateTimer                       = useRef(null);
  const hasCategories = allCategories || selectedCategories.length > 0;

  useEffect(() => {
    getSettings()
      .then((s) => {
        const provider = s.provider ?? 'openai';
        setCurrentProvider(provider);
        setCurrentModel(s[`${provider}_model`] ?? 'gpt-4o-mini');
      })
      .catch(() => {});

    // Rules API only exists on Pro/Scale
    if (IS_PRO) {
      listRules()
        .then((data) => {
          const list = Array.isArray(data) ? data : (Array.isArray(data?.rules) ? data.rules : []);
          setRules(list);
        })
        .catch(() => {})
        .finally(() => setRulesLoading(false));
    } else {
      setRulesLoading(false);
    }
  }, []);

  useEffect(() => {
    if (!allCategories) {
      setStoreProductCount(0);
      return;
    }
    const wcRoot = window.AiWoo?.wcRoot ?? '/wp-json/wc/v3/';
    const nonce  = window.AiWoo?.nonce ?? '';
    axios
      .get(`${wcRoot}products`, {
        params: { per_page: 1, status: 'publish' },
        headers: { 'X-WP-Nonce': nonce },
      })
      .then((r) => {
        const h = r.headers || {};
        const total = parseInt(h['x-wp-total'] || h['X-WP-Total'] || '0', 10);
        setStoreProductCount(Number.isFinite(total) ? total : 0);
      })
      .catch(() => setStoreProductCount(0));
  }, [allCategories]);

  useEffect(() => {
    clearTimeout(categorySearchTimer.current);
    if (categorySearch.trim().length < 1) { setCategoryResults([]); return; }
    categorySearchTimer.current = setTimeout(async () => {
      setCategorySearchLoading(true);
      try {
        const wcRoot = window.AiWoo?.wcRoot ?? '/wp-json/wc/v3/';
        const nonce  = window.AiWoo?.nonce ?? '';
        const r = await axios.get(`${wcRoot}products/categories`, {
          params: { search: categorySearch, per_page: 10, orderby: 'name' },
          headers: { 'X-WP-Nonce': nonce },
        });
        setCategoryResults(r.data.filter(c => !selectedCategories.find(s => s.id === c.id)));
      } catch {
        setCategoryResults([]);
      } finally {
        setCategorySearchLoading(false);
      }
    }, 300);
  }, [categorySearch, selectedCategories]);

  useEffect(() => {
    clearTimeout(searchTimer.current);
    if (productSearch.trim().length < 2) { setSearchResults([]); return; }
    searchTimer.current = setTimeout(async () => {
      setSearchLoading(true);
      try {
        const wcRoot = window.AiWoo?.wcRoot ?? '/wp-json/wc/v3/';
        const nonce  = window.AiWoo?.nonce ?? '';
        const params = new URLSearchParams({ search: productSearch, per_page: 20, status: 'publish' });
        const r = await axios.get(`${wcRoot}products?${params}`, {
          headers: { 'X-WP-Nonce': nonce },
        });
        const existing = hasCategories ? excludedProducts : addedProducts;
        setSearchResults(r.data.filter(p => !existing.find(e => e.id === p.id)));
      } catch {
        setSearchResults([]);
      } finally {
        setSearchLoading(false);
      }
    }, 350);
  }, [productSearch, hasCategories, addedProducts, excludedProducts]);

  function addCategory(cat) {
    const id = cat.id;
    const name = cat.name;
    const count = cat.count ?? 0;
    setSelectedCategories((prev) => {
      if (prev.find((s) => s.id === id)) return prev;
      return [...prev, { id, name, count }];
    });
  }

  function removeCategory(id) {
    setSelectedCategories((prev) => prev.filter((c) => c.id !== id));
  }

  function addToList(product) {
    if (hasCategories) {
      setExcludedProducts(prev => [...prev, { id: product.id, name: product.name }]);
    } else {
      setAddedProducts(prev => [...prev, { id: product.id, name: product.name }]);
    }
    setProductSearch(''); setSearchResults([]);
  }
  function removeFromList(id) {
    if (hasCategories) setExcludedProducts(prev => prev.filter(p => p.id !== id));
    else setAddedProducts(prev => prev.filter(p => p.id !== id));
  }

  async function handlePreview() {
    setPreviewError(null); setPreviewLoading(true); setPreviews([]);
    try {
      let ids = [];
      if (hasCategories) {
        const wcRoot = window.AiWoo?.wcRoot ?? '/wp-json/wc/v3/';
        const nonce  = window.AiWoo?.nonce ?? '';
        const params = new URLSearchParams({ per_page: 5, status: 'publish' });
        if (!allCategories && selectedCategories.length > 0) params.set('category', String(selectedCategories[0].id));
        const r = await axios.get(`${wcRoot}products?${params}`, { headers: { 'X-WP-Nonce': nonce } });
        ids = r.data.map(p => p.id).filter(id => !excludedProducts.find(e => e.id === id)).slice(0, 3);
      } else {
        ids = addedProducts.slice(0, 3).map(p => p.id);
      }
      if (!ids.length) { setPreviewError('No products to preview.'); return; }
      const results = await Promise.all(ids.map(id => previewProduct(id).then(r => ({ productId: id, ...r.preview }))));
      setPreviews(results);
    } catch (err) {
      setPreviewError(err?.response?.data?.message ?? err.message ?? 'Preview failed');
    } finally {
      setPreviewLoading(false);
    }
  }

  async function handleQueueJob() {
    if (!hasCategories && addedProducts.length === 0) {
      setSubmitError('Select a category or add specific products.');
      return;
    }
    setSubmitError(null); setSubmitting(true);
    try {
      const payload = {
        name: jobName || `SEO Job ${new Date().toLocaleDateString()}`,
        skip_existing: skipExisting,
        options: IS_PRO && rule ? { rule_id: parseInt(rule, 10) } : {},
      };
      if (hasCategories) {
        payload.category_ids = allCategories ? [] : selectedCategories.map((c) => c.id);
        payload.exclude_ids  = excludedProducts.map(p => p.id);
      } else {
        payload.product_ids = addedProducts.map(p => p.id);
      }
      const result = await createJob(payload);
      onJobCreated(result.job_id);
    } catch (err) {
      setSubmitError(err?.response?.data?.message ?? err.message ?? 'Failed to queue job');
    } finally {
      setSubmitting(false);
    }
  }

  const estimatedCount = hasCategories
    ? (allCategories
        ? storeProductCount
        : selectedCategories.reduce((s, c) => s + (c.count || 0), 0)
      ) - excludedProducts.length
    : addedProducts.length;

  useEffect(() => {
    clearTimeout(estimateTimer.current);
    const count = Math.max(estimatedCount, 0);
    if (count === 0) { setEstimate(null); return; }
    estimateTimer.current = setTimeout(() => {
      setEstimateLoading(true);
      estimateCost(count, currentProvider, currentModel)
        .then(setEstimate).catch(() => setEstimate(null))
        .finally(() => setEstimateLoading(false));
    }, 500);
  }, [estimatedCount, currentProvider, currentModel]);

  const idCount = hasCategories ? Math.max(estimatedCount, 0) : addedProducts.length;

  return (
    <div className="space-y-6 max-w-2xl">
      <div className="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-6 space-y-5">

        {/* Job name */}
        <div>
          <label className="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">
            Job Name <span className="text-gray-400 dark:text-gray-500 font-normal">(optional)</span>
          </label>
          <input
            type="text"
            value={jobName}
            onChange={(e) => setJobName(e.target.value)}
            placeholder="e.g. Black Friday SEO Update"
            className={INPUT_CLS}
          />
        </div>

        {/* Rule selector — Pro/Scale only, Free always uses default */}
        {IS_PRO && (
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Rule / Style</label>
            <select
              value={rule}
              onChange={(e) => setRule(e.target.value)}
              disabled={rulesLoading}
              className={INPUT_CLS}
            >
              <option value="">— Default (AI chooses best approach) —</option>
              {rules.map((r) => (
                <option key={r.id} value={r.id}>
                  {r.name}{r.is_default ? ' ★' : ''}
                </option>
              ))}
            </select>
            {rulesLoading && (
              <p className="text-xs text-gray-400 mt-1">Loading rules…</p>
            )}
            {!rulesLoading && rules.length === 0 && (
              <p className="text-xs text-gray-400 mt-1">
                No rules created yet.{' '}
                <button
                  onClick={() => onNavigate?.('rules')}
                  className="text-indigo-500 hover:underline bg-transparent border-0 p-0 cursor-pointer text-xs"
                >
                  Create one →
                </button>
              </p>
            )}
          </div>
        )}

        {/* Category filter — search + tags */}
        <div>
          <label className="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">
            Filter by Category{' '}
            <span className="text-gray-400 dark:text-gray-500 font-normal">(optional, multiple)</span>
          </label>

          <label className="flex items-center gap-2 mb-2 cursor-pointer select-none">
            <input
              type="checkbox"
              checked={allCategories}
              onChange={(e) => { setAllCategories(e.target.checked); if (e.target.checked) setSelectedCategories([]); }}
              className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
            />
            <span className="text-sm font-medium text-indigo-700 dark:text-indigo-300">
              All Categories (entire store)
            </span>
          </label>

          {!allCategories && selectedCategories.length > 0 && (
            <div className="flex flex-wrap gap-2 mb-2">
              {selectedCategories.map((c) => (
                <span key={c.id} className="inline-flex items-center gap-1 px-2 py-1 rounded text-xs font-medium bg-indigo-100 dark:bg-indigo-900/40 text-indigo-800 dark:text-indigo-200">
                  {c.name} <span className="text-indigo-400">({c.count})</span>
                  <button type="button" onClick={() => removeCategory(c.id)} className="ml-1 opacity-60 hover:opacity-100">×</button>
                </span>
              ))}
            </div>
          )}

          {!allCategories && (
            <div className="relative">
              <input
                type="text"
                value={categorySearch}
                onChange={(e) => setCategorySearch(e.target.value)}
                placeholder="Search categories to add…"
                className={INPUT_CLS}
              />
              {categorySearchLoading && (
                <div className="absolute right-3 top-2.5">
                  <svg className="animate-spin h-4 w-4 text-gray-400" viewBox="0 0 24 24" fill="none">
                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4l3-3-3-3v4a8 8 0 00-8 8h4z" />
                  </svg>
                </div>
              )}
              {categoryResults.length > 0 && (
                <div className="absolute z-10 w-full mt-1 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md shadow-lg max-h-48 overflow-y-auto">
                  {categoryResults.map((c) => (
                    <button
                      key={c.id}
                      type="button"
                      onClick={() => { addCategory(c); setCategorySearch(''); setCategoryResults([]); }}
                      className="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-600 text-gray-800 dark:text-gray-100"
                    >
                      {c.name} <span className="text-gray-400">({c.count})</span>
                    </button>
                  ))}
                </div>
              )}
            </div>
          )}
        </div>

        {/* Scope summary */}
        <div className="px-3 py-2 rounded-md bg-gray-50 dark:bg-gray-700/50 border border-gray-200 dark:border-gray-600 text-xs text-gray-600 dark:text-gray-300">
          {!hasCategories
            ? addedProducts.length === 0 ? '⚡ No products selected — add products below' : `⚡ ${addedProducts.length} product${addedProducts.length !== 1 ? 's' : ''} will be processed`
            : allCategories ? `⚡ All published products will be processed${excludedProducts.length > 0 ? `, excluding ${excludedProducts.length}` : ''}`
            : `⚡ All products in ${selectedCategories.length} categor${selectedCategories.length === 1 ? 'y' : 'ies'} will be processed${excludedProducts.length > 0 ? `, excluding ${excludedProducts.length}` : ''}`
          }
        </div>

        {/* Add / Exclude products */}
        <div>
          <label className="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">
            {hasCategories ? 'Exclude Products' : 'Add Products'}
            <span className="text-gray-400 font-normal ml-1 text-xs">
              {hasCategories ? '(optional)' : '(required when no category selected)'}
            </span>
          </label>
          {(hasCategories ? excludedProducts : addedProducts).length > 0 && (
            <div className="flex flex-wrap gap-2 mb-2">
              {(hasCategories ? excludedProducts : addedProducts).map(p => (
                <span key={p.id} className={`inline-flex items-center gap-1 px-2 py-1 rounded text-xs font-medium ${hasCategories ? 'bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200' : 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-800 dark:text-indigo-200'}`}>
                  #{p.id} {p.name}
                  <button type="button" onClick={() => removeFromList(p.id)} className="ml-1 opacity-60 hover:opacity-100">×</button>
                </span>
              ))}
            </div>
          )}
          <div className="relative">
            <input type="text" value={productSearch} onChange={e => setProductSearch(e.target.value)}
              placeholder={hasCategories ? 'Search products to exclude…' : 'Search products to add…'}
              className={INPUT_CLS} />
            {searchLoading && <div className="absolute right-3 top-2.5"><svg className="animate-spin h-4 w-4 text-gray-400" viewBox="0 0 24 24" fill="none"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4l3-3-3-3v4a8 8 0 00-8 8h4z"/></svg></div>}
            {searchResults.length > 0 && (
              <div className="absolute z-10 w-full mt-1 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md shadow-lg max-h-48 overflow-y-auto">
                {searchResults.map(p => (
                  <button key={p.id} type="button" onClick={() => addToList(p)}
                    className="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-600 text-gray-800 dark:text-gray-100">
                    <span className="text-gray-400 mr-1">#{p.id}</span> {p.name}
                  </button>
                ))}
              </div>
            )}
          </div>

          {/* Cost estimate */}
          {idCount > 0 && (
            <div className="mt-2 rounded-md bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-700 px-3 py-2 text-xs text-indigo-700 dark:text-indigo-300">
              {estimateLoading ? (
                <span className="opacity-60">Calculating estimate…</span>
              ) : estimate ? (
                <>
                  <span className="font-semibold">
                    Estimated cost: ~${estimate.estimated_cost_usd < 0.01
                      ? estimate.estimated_cost_usd.toFixed(5)
                      : estimate.estimated_cost_usd.toFixed(4)}{' '}
                  </span>
                  for {estimate.products} product{estimate.products !== 1 ? 's' : ''}{' '}
                  ({estimate.provider} / {estimate.model})
                  <span className="block mt-0.5 opacity-70">{estimate.disclaimer}</span>
                </>
              ) : null}
            </div>
          )}
        </div>

        {/* Previews */}
        {previewError && (
          <p className="text-sm text-red-600 dark:text-red-400">{previewError}</p>
        )}
        {previews.length > 0 && (
          <div className="space-y-3">
            <p className="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">
              Preview — first {previews.length} product{previews.length !== 1 ? 's' : ''}
            </p>
            {previews.map((p) => <PreviewCard key={p.productId} preview={p} />)}
          </div>
        )}

        <label className="flex items-start gap-3 cursor-pointer select-none">
          <input
            type="checkbox"
            checked={skipExisting}
            onChange={(e) => setSkipExisting(e.target.checked)}
            className="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
          />
          <span className="text-sm text-gray-700 dark:text-gray-200">
            Skip products that already have AI-generated SEO
            <span className="block text-xs text-gray-400 dark:text-gray-500 font-normal mt-0.5">
              When checked, only products without existing AI SEO will be processed. Uncheck to regenerate everything.
            </span>
          </span>
        </label>

        {submitError && (
          <p className="text-sm text-red-600 dark:text-red-400">{submitError}</p>
        )}

        {/* Actions */}
        <div className="flex gap-3 pt-2">
          <button
            onClick={handlePreview}
            disabled={previewLoading}
            className="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50 transition-colors"
          >
            {previewLoading ? 'Previewing…' : 'Preview First 3'}
          </button>
          <button
            onClick={handleQueueJob}
            disabled={submitting || (!hasCategories && addedProducts.length === 0)}
            className="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700 disabled:opacity-50 transition-colors"
          >
            {submitting ? 'Queuing…' : 'Queue Job'}
          </button>
        </div>
      </div>
    </div>
  );
}
