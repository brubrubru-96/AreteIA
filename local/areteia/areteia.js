/**
 * AreteIA — Client-side AJAX navigation and UI reactivity.
 *
 * Responsibilities:
 * 1. Intercept link clicks for AJAX partial-page updates (no full reload)
 * 2. Auto-capture textarea state (d2) before navigation
 * 3. Step 3 reactivity: enable/disable the "next" button based on dimension completion
 * 4. Loading state management for AI-generation buttons
 */
document.addEventListener("click", e => {
    // Robust link detection even if innerHTML was changed by loading states (detaching target)
    const path = e.composedPath ? e.composedPath() : [e.target];
    let link = null;
    for (const el of path) {
        if (el.matches && el.matches("a.opt, a.s0-card, a.sug-card, a.areteia-btn, button.areteia-btn, a.fb-btn, a.areteia-dot")) {
            link = el;
            break;
        }
    }
    
    if (!link || link.classList.contains("external")) return;

    // Skip the ingest, publish, and inline item action buttons — handled separately
    if (link.id === 'confirm-ingest-btn' || link.id === 'btn-publish-quiz') return;
    if (link.classList.contains('item-adjust-submit-btn') || link.classList.contains('item-save-btn') || link.classList.contains('item-cancel-btn') || link.classList.contains('item-edit-btn')) return;

    // Handle confirmation if needed
    if (link.dataset.confirm && !confirm(link.dataset.confirm)) {
        e.preventDefault();
        return;
    }

    // Handle different types of triggers (links vs form buttons)
    let urlString = link.href;
    let isFormSubmit = false;
    let formElement = null;

    if (!urlString && link.type === 'submit' && link.form) {
        isFormSubmit = true;
        formElement = link.form;
        urlString = formElement.action || window.location.href;
    }

    // Guard: ensure we have a valid URL to fetch
    if (!urlString || urlString === '#' || urlString === window.location.href + '#') {
        return;
    }

    const url = new URL(urlString);
    url.searchParams.set("ajax", "1");

    // Auto-capture the objective state for Step 3
    captureStep3State(url);

    // Capture feedback for iterative adjustment (Steps 4, 5, 6)
    // New: If link has item-index, look for feedback in that item's specific textarea
    if (link.dataset.adjust === "1") {
        let feedback = "";
        if (link.dataset.itemIndex !== undefined) {
            const tray = document.querySelector(`.item-adjust-tray[data-index="${link.dataset.itemIndex}"]`);
            const textarea = tray ? tray.querySelector('textarea') : null;
            feedback = textarea ? textarea.value.trim() : "";
            if (feedback) {
                // Prepend item index so backend knows which one to focus on
                feedback = `[Ítem ${link.dataset.itemIndex}] ${feedback}`;
                url.searchParams.set("item_index", link.dataset.itemIndex);
            }
        } else {
            const feedbackArea = document.querySelector('textarea[name="feedback"]');
            feedback = feedbackArea ? feedbackArea.value.trim() : "";
        }
        if (feedback) url.searchParams.set("feedback", feedback);
    }

    // Capture material selection in Step 1 if starting ingestion
    const options = { method: 'GET' };

    // Capture num_items for Step 5 generation
    const numItemsInput = document.getElementById('num_items_input');

    if (numItemsInput && (link.id === 'btn-generate-items' || link.dataset.pStep === "5")) {
        url.searchParams.set("num_items", numItemsInput.value);
    }

    // Prepare body for POST if it's a form or a specific action
    const isIngest = url.searchParams.get("action") === "ingest";
    const isInject = url.searchParams.get("action") === "inject_quiz";

    if (url.searchParams.has("d2_json") || isIngest || isInject || isFormSubmit) {

        // Guard: if ingestion, ensure at least one file is selected (Step 1)
        if (isIngest) {
            const checkedFiles = document.querySelectorAll('.tree-cb[data-type="file"]:checked');
            if (checkedFiles.length === 0) {
                alert("Por favor, selecciona al menos un recurso para continuar.");
                e.preventDefault();
                const primaryBtn = document.querySelector('.areteia-btn-primary.is-loading');
                if (primaryBtn) primaryBtn.classList.remove('is-loading');
                return;
            }
        }

        options.method = 'POST';
        options.headers = { 'Content-Type': 'application/x-www-form-urlencoded' };
        const body = new URLSearchParams();

        // 1. All existing URL search params
        url.searchParams.forEach((val, key) => body.append(key, val));

        // 2. All form data if it's a form submission
        if (isFormSubmit && formElement) {
            const formData = new FormData(formElement);
            for (const [key, val] of formData.entries()) {
                // For array params (like selected_items[]) we must always append.
                // For others, only append if not already present from the URL (to avoid id/step conflict)
                const isArray = key.endsWith('[]');
                const isRestricted = ['id', 'step', 'action'].includes(key);

                if (isArray || (!isRestricted && !body.has(key))) {
                    body.append(key, val);
                }
            }
        }

        options.body = body.toString();
        e.preventDefault();
    } else {
        // Standard link navigation
        e.preventDefault();
    }

    fetch(url, options).then(r => {
        if (!r.ok) throw new Error("Server error " + r.status);
        const finalUrl = new URL(r.url);
        finalUrl.searchParams.delete("ajax");

        const isStepChange = finalUrl.searchParams.get("step") !== new URL(location.href).searchParams.get("step");

        window.history.pushState({}, "", finalUrl.toString());
        return r.text().then(html => ({ html, isStepChange, contentType: r.headers.get('content-type') }));
    }).then(({ html, isStepChange, contentType }) => {
        // Check if response is JSON redirect
        if (contentType && contentType.includes('application/json')) {
            try {
                const json = JSON.parse(html);
                if (json.redirect) {
                    window.location.href = json.redirect;
                    return;
                }
            } catch (e) {
                // Not valid JSON, treat as HTML
            }
        }

        const main = document.getElementById("areteia-main");
        if (isStepChange || !document.getElementById("d2-container")) {
            main.innerHTML = html;
            window.scrollTo({ top: 0, behavior: "smooth" });
        } else {
            surgicalUpdate(html);
        }

        initStep3Reactivity();
        initGenerativeLoading();
        initTreeCheckboxes();
        initRagSearchTest();
        initIngestionForm();
        initPromptPreview();
        initItemAdjustmentUI();
        initInstrumentFallback();
        initQuizWeightsAdjustment();
        initItemDirectEditUI();
    }).catch(err => {
        console.error(err);
        alert("Error en la comunicación con el servidor. Por favor, reintenta.");
        // Reset loading button if any
        const loadingBtn = document.querySelector('.areteia-btn-primary.is-loading');
        if (loadingBtn) {
            loadingBtn.classList.remove('is-loading');
            loadingBtn.innerHTML = loadingBtn.dataset.oldHtml || "Error - Reintentar";
            loadingBtn.style.opacity = '1';
        }
    });
});

/**
 * Hierarchical Tree Selection Logic (Step 1)
 */
function initTreeCheckboxes() {
    const tree = document.getElementById('materials-tree');
    if (!tree) return;

    // Toggle collapse/expand
    tree.addEventListener('click', e => {
        if (e.target.classList.contains('tree-toggle')) {
            const node = e.target.closest('.tree-node');
            if (node) {
                node.classList.toggle('collapsed');
            }
        }
    });

    // Initial count and parent states
    updateSelectionCount();
    document.querySelectorAll('.tree-cb[data-type="file"]').forEach(cb => {
        updateParentStates(cb);
    });

    tree.addEventListener('change', e => {
        if (!e.target.classList.contains('tree-cb')) return;

        const cb = e.target;
        const node = cb.closest('.tree-node');
        const isChecked = cb.checked;

        // Recursive Down: Update all children
        const children = node.querySelectorAll('.tree-cb');
        children.forEach(child => {
            child.checked = isChecked;
            child.indeterminate = false;
        });

        // Bubble Up: Optional - we could update parent state here
        updateParentStates(cb);

        // Update selection counter
        updateSelectionCount();
    });
}

/**
 * Updates the "X files selected" badge in real-time.
 */
function updateSelectionCount() {
    const badge = document.getElementById('selection-count-badge');
    if (!badge) return;

    const count = document.querySelectorAll('.tree-cb[data-type="file"]:checked').length;
    badge.textContent = `${count} ${count === 1 ? 'recurso seleccionado' : 'recursos seleccionados'}`;

    // Aesthetic: Change color if 0
    if (count > 0) {
        badge.style.background = '#28a745'; // OK green
        badge.style.color = '#fff';
    } else {
        badge.style.background = '#ffc107'; // Warn yellow
        badge.style.color = '#000';
    }
}

/**
 * Optional: Update parent indeterminate/checked state based on children.
 */
function updateParentStates(startCb) {
    let current = startCb.closest('.tree-node').parentElement.closest('.tree-node');

    while (current) {
        const parentCb = current.querySelector('.tree-row .tree-cb');

        const treeChildren = Array.from(current.children).find(el => el.classList.contains('tree-children'));
        if (treeChildren && parentCb) {
            const childNodes = Array.from(treeChildren.children).filter(el => el.classList.contains('tree-node'));
            const siblingNodes = childNodes.map(node => node.querySelector('.tree-row .tree-cb')).filter(cb => cb);

            if (siblingNodes.length > 0) {
                const checkedCount = siblingNodes.filter(c => c.checked).length;
                const isIndeterminate = siblingNodes.some(c => c.indeterminate);

                if (checkedCount === 0) {
                    parentCb.checked = false;
                    parentCb.indeterminate = isIndeterminate;
                } else if (checkedCount === siblingNodes.length) {
                    parentCb.checked = true;
                    parentCb.indeterminate = false;
                } else {
                    parentCb.checked = false;
                    parentCb.indeterminate = true;
                }
            }
        }
        current = current.parentElement.closest('.tree-node');
    }
}

/**
 * Step 3 reactivity: Handles dynamic objective form and enables the "next" button.
 */
function initStep3Reactivity() {
    const btn = document.getElementById("next-step-btn");
    const container = document.getElementById("d2-container");
    const list = document.getElementById("objectives-list");
    const addBtn = document.getElementById("add-objective-btn");

    if (!btn || !container) return;

    const updateBtn = () => {
        const rows = document.querySelectorAll('.objective-row');
        let hasValidObjective = false;

        rows.forEach(row => {
            const text = row.querySelector('.objective-text-input').value.trim();
            if (text.length > 0) hasValidObjective = true;
        });

        const activeOpts = document.querySelectorAll(".opt.main").length;
        const expectedOpts = 3; // D1, D3, D4

        if (hasValidObjective && activeOpts >= expectedOpts) {
            btn.classList.remove("disabled");
            btn.style.opacity = "1";
            btn.style.cursor = "pointer";
            btn.innerHTML = "Ver instrumentos recomendados →";
        } else {
            btn.classList.add("disabled");
            btn.style.opacity = "0.5";
            btn.style.cursor = "not-allowed";
            btn.innerHTML = "Completa todas las dimensiones";
        }
    };

    // Objective form interactions
    if (addBtn && !addBtn.dataset.bound) {
        addBtn.dataset.bound = "1";
        addBtn.addEventListener('click', () => {
            const count = document.querySelectorAll('.objective-row').length;
            const firstRow = document.querySelector('.objective-row');
            if (!firstRow) return;

            const newRow = firstRow.cloneNode(true);
            newRow.dataset.index = count;

            // Clear values
            const select = newRow.querySelector('select');
            const input = newRow.querySelector('input');
            select.value = "";
            input.value = "";

            list.appendChild(newRow);

            // Re-bind listeners
            bindRowListeners(newRow);
            updateBtn();
        });
    }

    const triggerAutoSave = debounce(() => {
        // Only trigger if we are still on Step 3
        if (!document.getElementById("d2-container")) return;

        const url = new URL(window.location.href);
        url.searchParams.set("ajax", "1");
        captureStep3State(url);

        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(url.searchParams).toString()
        }).then(r => r.text()).then(html => {
            surgicalUpdate(html, true); // true = requested by auto-save
        });
    }, 2000);

    const bindRowListeners = (row) => {
        const input = row.querySelector('.objective-text-input');
        const select = row.querySelector('.objective-bloom-select');
        const removeBtn = row.querySelector('.remove-objective-btn');

        input.addEventListener('input', () => {
            updateBtn();
            triggerAutoSave();
        });
        select.addEventListener('change', () => {
            updateBtn();
            triggerAutoSave();
        });

        if (removeBtn) {
            removeBtn.addEventListener('click', () => {
                if (document.querySelectorAll('.objective-row').length > 1) {
                    row.remove();
                    updateBtn();
                    triggerAutoSave();
                } else {
                    input.value = "";
                    select.value = "";
                    updateBtn();
                    triggerAutoSave();
                }
            });
        }
    };

    document.querySelectorAll('.objective-row').forEach(bindRowListeners);
    updateBtn();
}

/**
 * Loading state for AI-generation buttons.
 * Shows a spinner and "Generando con IA..." label on click.
 */
function initGenerativeLoading() {
    document.querySelectorAll('.areteia-btn-primary:not(.external):not(#confirm-ingest-btn):not(.item-save-btn):not(.item-cancel-btn):not(.item-edit-btn):not(.item-adjust-submit-btn)').forEach(btn => {
        if (btn.dataset.bound) return;
        btn.dataset.bound = "1";
        btn.addEventListener('click', function (e) {
            if (this.classList.contains('is-loading')) {
                e.preventDefault();
                return;
            }

            let isIA = this.innerText.includes('✨') || this.dataset.ia === "1";
            let label = isIA ? 'Generando con IA...' : 'Cargando...';

            this.classList.add('is-loading');
            this.dataset.oldHtml = this.innerHTML;
            this.innerHTML = '<span class="spinner-border spinner-border-sm mr-2" role="status" aria-hidden="true"></span> ' + label;
            this.style.opacity = '0.7';
        });
    });
}

/**
 * Search Test UI: Semantic query against Python RAG.
 */
function initRagSearchTest() {
    const btn = document.getElementById('rag-search-btn');
    const input = document.getElementById('rag-search-input');
    const container = document.getElementById('rag-search-results');

    if (!btn || !input || !container) return;

    btn.addEventListener('click', () => {
        const query = input.value.trim();
        const courseid = btn.dataset.courseid;
        if (!query) return;

        btn.disabled = true;
        btn.innerHTML = 'Buscando...';
        container.innerHTML = '<div style="text-align:center; padding:20px; color:#666;">⏳ Consultando IA...</div>';

        fetch(`ajax_search.php?course_id=${courseid}&query=${encodeURIComponent(query)}`)
            .then(r => r.json())
            .then(res => {
                btn.disabled = false;
                btn.innerHTML = 'Buscar';

                if (res.status === 'success' && res.results) {
                    if (res.results.length === 0) {
                        container.innerHTML = '<div style="color:#666; font-size:12px;">No se encontraron fragmentos relevantes.</div>';
                        return;
                    }
                    let html = '<div style="display:flex; flex-direction:column; gap:10px;">';
                    res.results.forEach(item => {
                        const sim = item.similarity ? `${(item.similarity * 100).toFixed(1)}% coincidencia` : '';
                        html += `
                            <div style="background:#fff; border:1px solid #eee; padding:10px; border-radius:8px; font-size:12px; line-height:1.4;">
                                <div style="display:flex; justify-content:space-between; margin-bottom:6px;">
                                    <strong style="color:#6c63ff;">📄 ${item.filename}</strong>
                                    <span style="color:#28a745; font-size:10px; font-weight:bold;">${sim}</span>
                                </div>
                                <div style="color:#444; font-style:italic; border-left:3px solid #ddd; padding-left:10px;">"${item.text}"</div>
                            </div>
                        `;
                    });
                    html += '</div>';
                    container.innerHTML = html;
                } else {
                    container.innerHTML = `<div style="color:red; font-size:12px;">Error: ${res.message || 'Error desconocido'}</div>`;
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.innerHTML = 'Buscar';
                container.innerHTML = `<div style="color:red; font-size:12px;">Error de red: ${err.message}</div>`;
            });
    });

    // Enter key support
    input.addEventListener('keypress', e => {
        if (e.key === 'Enter') btn.click();
    });
}

// Reload on browser back/forward so state stays in sync
window.addEventListener("popstate", () => location.reload());

// Initialize on DOM ready
document.addEventListener("DOMContentLoaded", () => {
    initStep3Reactivity();
    initGenerativeLoading();
    initTreeCheckboxes();
    initRagSearchTest();
    initIngestionForm();
    initPromptPreview();
    initItemAdjustmentUI();
    initInstrumentFallback();
    initQuizWeightsAdjustment();
    initItemDirectEditUI();
});

/**
 * Step 5: Item Adjustment UI Toggling + AJAX AI Adjustment
 */
function initItemAdjustmentUI() {
    document.addEventListener("click", e => {
        // Toggle tray open/close
        const trigger = e.target.closest(".item-adjust-trigger");
        if (trigger) {
            const index = trigger.dataset.index;
            const tray = document.querySelector(`.item-adjust-tray[data-index="${index}"]`);
            if (tray) {
                tray.classList.toggle("active");
                trigger.innerHTML = tray.classList.contains("active") ? "Cancelar ✕" : "Ajustar con IA ✨";
            }
            return;
        }

        // Submit adjustment to AI
        const submitBtn = e.target.closest(".item-adjust-submit-btn");
        if (submitBtn) {
            e.preventDefault();
            e.stopPropagation();
            const index = parseInt(submitBtn.dataset.index);
            adjustItemWithAI(index, submitBtn);
            return;
        }
    });
}

/**
 * Send a single item to the AI for adjustment via AJAX.
 */
function adjustItemWithAI(index, submitBtn) {
    const payloadInput = document.querySelector('input[name="src_data_payload"]');
    if (!payloadInput) return;

    const tray = document.querySelector(`.item-adjust-tray[data-index="${index}"]`);
    const textarea = tray ? tray.querySelector('.item-adjust-textarea') : null;
    const statusSpan = tray ? tray.querySelector('.item-adjust-status') : null;
    const instruction = textarea ? textarea.value.trim() : '';

    if (!instruction) {
        if (textarea) textarea.focus();
        if (statusSpan) statusSpan.textContent = '⚠️ Escribe una instrucción primero.';
        return;
    }

    let data;
    try {
        data = JSON.parse(payloadInput.value);
    } catch (err) {
        console.error("Error parsing payload:", err);
        return;
    }

    const item = data.items[index];
    if (!item) return;

    // Show loading state
    const originalLabel = submitBtn.innerHTML;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm mr-2" role="status" aria-hidden="true"></span> Generando...';
    submitBtn.disabled = true;
    submitBtn.classList.add("is-loading");
    if (statusSpan) statusSpan.textContent = '';
    if (textarea) textarea.disabled = true;

    // Build URL for the AJAX call
    const url = new URL(window.location.href);
    url.searchParams.set("action", "adjust_item");
    url.searchParams.set("ajax", "1");

    // Send as POST with form data
    const formData = new FormData();
    formData.append("item_json", JSON.stringify(item));
    formData.append("instruction", instruction);
    formData.append("item_index", index);

    fetch(url.toString(), {
        method: "POST",
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        if (res.status === "success" && res.item) {
            const adjustedItem = res.item;

            // Preserve the original type if the AI didn't return one
            if (!adjustedItem.type && item.type) {
                adjustedItem.type = item.type;
            }

            // Update item in data
            data.items[index] = adjustedItem;
            payloadInput.value = JSON.stringify(data);

            // Refresh the entire card view
            refreshCardView(index, adjustedItem);

            // Also update the edit form values
            const card = document.querySelectorAll('.item-card')[index];
            if (card) {
                resetEditFormValues(card, adjustedItem, index);
            }

            if (statusSpan) {
                statusSpan.innerHTML = '✅ <span style="color:#1e8e3e;">Ítem ajustado correctamente.</span>';
            }
            if (textarea) textarea.value = '';

            // Log usage if available
            if (res.usage) {
                console.log('AI Token Usage (Adjust Item):', res.usage);
            }
        } else {
            const errMsg = res.message || 'Error desconocido';
            if (statusSpan) {
                statusSpan.innerHTML = `❌ <span style="color:#d93025;">${escapeHtml(errMsg)}</span>`;
            }
        }
    })
    .catch(err => {
        console.error("Adjust item fetch error:", err);
        if (statusSpan) {
            statusSpan.innerHTML = '❌ <span style="color:#d93025;">Error de conexión con el servidor.</span>';
        }
    })
    .finally(() => {
        submitBtn.innerHTML = originalLabel;
        submitBtn.disabled = false;
        submitBtn.classList.remove("is-loading");
        if (textarea) textarea.disabled = false;
    });
}

/**
 * Refresh a card's view mode with new item data.
 */
function refreshCardView(index, item) {
    const type = (item.type || "").toLowerCase();

    // Update body text
    const bodyTextDiv = document.getElementById(`body-text-${index}`);
    if (bodyTextDiv) {
        const newConsigna = item.consigna || item.consiga || item.text || "";
        if (type.includes("lacunar") || type.includes("cloze") || type === 'multianswer') {
            bodyTextDiv.innerHTML = renderLacunarPreviewClientSide(newConsigna, item.short_answer || item.cloze_answer || "");
        } else {
            bodyTextDiv.innerText = newConsigna;
        }
    }

    // Update badges
    const typeBadge = document.getElementById(`badge-type-${index}`);
    const diffBadge = document.getElementById(`badge-diff-${index}`);
    const ptsBadge = document.getElementById(`badge-pts-${index}`);
    if (typeBadge) typeBadge.innerText = item.type || `Ítem ${index + 1}`;
    if (diffBadge) diffBadge.innerText = item.difficulty || "Media";
    if (ptsBadge && item.points) ptsBadge.innerText = `${item.points} pts`;

    // Update objectives
    const objectivesDiv = document.getElementById(`item-objectives-${index}`);
    if (objectivesDiv) {
        const itemObjs = item.objectives || [];
        if (itemObjs.length > 0 && window.areteiaObjectivesMap) {
            const formatted = itemObjs.map(objKey => {
                const mapped = window.areteiaObjectivesMap[objKey];
                if (mapped) {
                    const bloom = mapped.bloom;
                    const text = mapped.text;
                    return bloom ? `<li><b>${escapeHtml(bloom)}</b> ${escapeHtml(text)}` : escapeHtml(text);
                }
                return escapeHtml(objKey);
            });
            objectivesDiv.innerHTML = `🎯 <strong>Objetivos:</strong><ul> ${formatted.join('; ')}</ul>`;
        } else {
            objectivesDiv.innerHTML = '';
        }
    }

    // Update rich content
    const richContentDiv = document.getElementById(`rich-content-${index}`);
    if (richContentDiv) {
        if (type.includes("múltiple") || type.includes("selección") || type.includes("cerrada")) {
            let html = "";
            const correctIdx = item.correct_index !== undefined ? parseInt(item.correct_index) : -1;
            (item.alternativas || []).forEach((opt, optIdx) => {
                const isCorrect = (optIdx === correctIdx);
                const optStyle = isCorrect ? 'background:#e6f4ea; border-color:#1e8e3e;' : '';
                const icon = isCorrect
                    ? '<i style="font-style:normal; color:#1e8e3e; font-weight:bold;">✔</i>'
                    : '<i style="font-style:normal; opacity:0.5;">○</i>';
                html += `<div class="item-option" style="${optStyle}">${icon} ${escapeHtml(opt)}</div>`;
            });
            richContentDiv.innerHTML = html;
        } else if (type.includes("verdadero")) {
            richContentDiv.innerHTML = `
                <div style="display:flex; gap:10px;">
                    <span class="item-vf-badge item-vf-v">Verdadero</span>
                    <span class="item-vf-badge item-vf-f">Falso</span>
                </div>`;
        } else if (type.includes("match") || type.includes("emparejamiento") || type.includes("orden")) {
            let html = `<table class="areteia-match-preview-table" style="width:100%; border-collapse:collapse; margin-top:10px;">
                <thead><tr><th style="padding:8px; border:1px solid #ddd; background:#f5f7fb; text-align:left;">Premisa</th><th style="padding:8px; border:1px solid #ddd; background:#f5f7fb; text-align:left;">Respuesta</th></tr></thead><tbody>`;
            (item.pairs || []).forEach((pair, pairIdx) => {
                html += `<tr>
                    <td style="padding:8px; border:1px solid #ddd; vertical-align:top;">${pairIdx + 1}. ${escapeHtml(pair.premise || pair.premisa || "")}</td>
                    <td style="padding:8px; border:1px solid #ddd; vertical-align:top;">${String.fromCharCode(65 + pairIdx)}. ${escapeHtml(pair.answer || pair.respuesta || "")}</td>
                </tr>`;
            });
            html += `</tbody></table>`;
            richContentDiv.innerHTML = html;
        } else if (type.includes("lacunar") || type.includes("cloze") || type === 'multianswer') {
            const realAnswer = item.short_answer || item.cloze_answer || "";
            const qText = item.consigna || item.consiga || item.text || "";
            if (shouldShowLacunarAnswerClientSide(realAnswer, qText)) {
                richContentDiv.innerHTML = `<div class="cloze-answer-preview-label" style="font-size:12px; color:#666; margin-top:8px;">Respuesta esperada: ${escapeHtml(realAnswer)}</div>`;
            } else {
                richContentDiv.innerHTML = "";
            }
        } else if (type.includes("breve") || type.includes("clásica") || type === "shortanswer") {
            let html = "";
            if (item.short_answer) {
                html += `<div class="shortanswer-answer-preview-label" style="font-size:12px; color:#1e8e3e; margin-top:8px; background:#e6f4ea; padding:8px 12px; border-radius:6px;">✅ Respuesta esperada: ${escapeHtml(item.short_answer)}</div>`;
            }
            richContentDiv.innerHTML = html;
        } else if (type.includes("abierta") || type.includes("ensayo")) {
            richContentDiv.innerHTML = '<div style="border:1px dashed #ccc; padding:15px; border-radius:8px; color:#999; font-style:italic; font-size:12px;">El estudiante redactará su respuesta aquí...</div>';
        }
    }
}

/**
 * AI Prompt Preview Logic
 */
function initPromptPreview() {
    // 1. Modal Close logic
    window.closePromptPreview = function () {
        const overlay = document.getElementById("prompt-preview-overlay");
        if (overlay) overlay.classList.remove("active");
    };

    // 2. Copy logic
    window.copyPromptToClipboard = function () {
        const system = document.getElementById("preview-system-content").innerText;
        const user = document.getElementById("preview-user-content").innerText;
        const full = "SYSTEM PROMPT:\n" + system + "\n\nUSER PROMPT:\n" + user;

        navigator.clipboard.writeText(full).then(() => {
            const btn = document.querySelector(".btn-copy-prompt");
            if (btn) {
                const oldText = btn.innerText;
                btn.innerText = "✅ ¡Copiado!";
                setTimeout(() => btn.innerText = oldText, 2000);
            }
        });
    };

    // 3. Global click listener for the "Ver Prompt" button
    document.addEventListener("click", e => {
        const btn = e.target.closest(".areteia-btn-preview");
        if (!btn) return;

        const step = btn.dataset.pStep;
        const feedbackArea = document.querySelector('textarea[name="feedback"]');
        const feedback = feedbackArea ? feedbackArea.value : "";

        btn.innerHTML = "⏳ Cargando...";
        btn.style.opacity = "0.7";

        const url = new URL(window.location.href);
        url.searchParams.set("action", "preview");
        url.searchParams.set("p_step", step);
        url.searchParams.set("feedback", feedback);
        url.searchParams.set("ajax", "1");

        // Capture num_items for preview if present
        const numItemsInput = document.getElementById('num_items_input');
        if (numItemsInput) {
            url.searchParams.set("num_items", numItemsInput.value);
        }

        fetch(url).then(r => r.json()).then(res => {
            btn.innerHTML = "👁️ Ver Prompt";
            btn.style.opacity = "1";

            if (res.status === "success") {
                const sysContent = document.getElementById("preview-system-content");
                const userContent = document.getElementById("preview-user-content");
                const overlay = document.getElementById("prompt-preview-overlay");

                if (sysContent) sysContent.innerText = res.system_prompt;
                if (userContent) userContent.innerText = res.user_prompt;
                if (overlay) overlay.classList.add("active");
            } else {
                alert("Error: " + (res.message || "No se pudo obtener el prompt"));
            }
        }).catch(err => {
            btn.innerHTML = "👁️ Ver Prompt";
            btn.style.opacity = "1";
            console.error(err);
            alert("Error de conexión con el servicio de IA.");
        });
    });

    // Close on ESC and Toggle prompt button on F9
    document.addEventListener("keydown", e => {
        if (e.key === "Escape") {
            closePromptPreview();
        } else if (e.key === "F9") {
            e.preventDefault();
            const btns = document.querySelectorAll(".areteia-btn-preview");
            btns.forEach(btn => {
                if (btn.style.display === "none") {
                    btn.style.display = "inline-block";
                } else {
                    btn.style.display = "none";
                }
            });
        }
    });
}

/**
 * Step 1: Ingestion Form Handling
 * Intercepts the native form submit to gather selected files into the hidden input.
 */
function initIngestionForm() {
    const form = document.getElementById('areteia-ingest-form');
    const input = document.getElementById('selected-files-input');
    const btn = document.getElementById('confirm-ingest-btn');

    if (!form || !input) return;

    // Use onsubmit to overwrite any previously attached listeners on AJAX re-loads
    form.onsubmit = function (e) {
        // Collect checked file checkboxes
        const selectedFiles = [];
        document.querySelectorAll('.tree-cb[data-type="file"]:checked').forEach(cb => {
            if (cb.value) selectedFiles.push(cb.value);
        });

        if (selectedFiles.length === 0) {
            e.preventDefault();
            alert('Por favor, seleccioná al menos un material para continuar.');
            return;
        }

        // Fill the hidden input with the selection JSON before the form POSTs
        input.value = JSON.stringify(selectedFiles);

        // Show loading state gracefully, without killing the submit event
        if (btn) {
            setTimeout(() => {
                btn.innerHTML = '⏳ Construyendo...';
                btn.disabled = true;
            }, 0);
        }
    };
}




/**
 * Real-time RAG Ingestion Poller
 */
function initIngestionPoller(courseid) {
    const bar = document.getElementById('areteia-ingestion-bar');
    const statusText = document.getElementById('areteia-ingestion-status');
    const percentText = document.getElementById('areteia-ingestion-percent');

    if (!bar || !statusText || !percentText) return;

    let pollCount = 0;
    const MAX_POLLS = 900; // 30 min max (900 * 2s) — large courses need time

    function redirectToSuccess() {
        clearInterval(interval);
        bar.style.width = '100%';
        percentText.innerHTML = '100%';
        statusText.innerHTML = '¡Completado!';
        setTimeout(() => {
            // Navigate to success state with a full page reload
            const base = window.location.href.split('?')[0];
            const params = new URLSearchParams(window.location.search);
            params.set('ingested', '1');
            params.delete('ajax');
            window.location.href = base + '?' + params.toString();
        }, 1500);
    }

    const interval = setInterval(() => {
        pollCount++;
        if (pollCount > MAX_POLLS) {
            clearInterval(interval);
            // Redirect without ?ingested so PHP re-checks real Python status
            const base = window.location.href.split('?')[0];
            const p2 = new URLSearchParams(window.location.search);
            p2.delete('ingested');
            p2.delete('ajax');
            window.location.href = base + '?' + p2.toString();
            return;
        }

        fetch(`ajax_status.php?course_id=${courseid}`)
            .then(r => r.json())
            .then(res => {
                // Reset counter — any live response means the server is still working
                pollCount = 0;

                // Case 1: active progress tracking (during build)
                if (res.status === 'success' && res.data && typeof res.data.progress !== 'undefined') {
                    const data = res.data;
                    // Update UI
                    const p = data.progress || 0;
                    bar.style.width = p + '%';
                    percentText.innerHTML = p + '%';
                    statusText.innerHTML = data.message || 'Procesando...';

                    if (p >= 100) {
                        redirectToSuccess();
                    }
                    return;
                }

                // Case 2: build finished, embedding_exists=true (state after reset of progress tracker)
                if (res.embedding_exists) {
                    redirectToSuccess();
                    return;
                }
            })
            .catch(err => {
                console.error("Error polling ingestion:", err);
            });
    }, 2000);
}


/**
 * Surgical update: updates only the parts of Step 3 that changed.
 * Prevents focus loss and visual 'flashes'.
 */
function surgicalUpdate(html, isAutoSave = false) {
    const parser = new DOMParser();
    const doc = parser.parseFromString(html, 'text/html');

    // Containers to check for updates
    const targets = [
        'd1-container', 'd3-container', 'd4-container',
        'rag-feedback-container', 'next-step-btn'
    ];

    targets.forEach(id => {
        const oldEl = document.getElementById(id);
        let newEl = doc.getElementById(id);

        // Special case for next-step-btn which might be inside a list
        if (!newEl) newEl = doc.querySelector(`#${id}`);

        if (oldEl && newEl) {
            // Avoid flashing if content is identical
            if (oldEl.innerHTML === newEl.innerHTML) return;

            // Visual transition for RAG feedback to make it feel alive
            if (id === 'rag-feedback-container') {
                oldEl.style.opacity = '0.5';
                setTimeout(() => {
                    oldEl.innerHTML = newEl.innerHTML;
                    oldEl.style.opacity = '1';
                }, 100);
            } else {
                oldEl.innerHTML = newEl.innerHTML;
            }
        }
    });

    // We specifically do NOT update #objectives-list during surgical updates
    // to preserve focus and cursor position while typing.
    // The state is already saved on server via the POST payload.
}

/**
 * Utility: Capture Step 3 objectives into a URL object.
 */
function captureStep3State(url) {
    const d2Container = document.getElementById('d2-container');
    if (!d2Container) return;

    const rows = document.querySelectorAll('.objective-row');
    let combinedText = "";
    let structured = [];

    rows.forEach(row => {
        const bloom = row.querySelector('.objective-bloom-select').value;
        const text = row.querySelector('.objective-text-input').value.trim();

        // Always save to JSON to preserve UI state (fix: incluso si el texto está vacío)
        structured.push({ bloom, text });

        // Only add to combined text for AI if there is content
        if (text) {
            combinedText += (bloom ? `[${bloom}] ` : "") + text + "\n";
        }
    });

    url.searchParams.set("d2", combinedText.trim());
    url.searchParams.set("d2_json", JSON.stringify(structured));
}

/**
 * Utility: Debounce function to limit execution frequency.
 */
function debounce(func, wait) {
    let timeout;
    return function (...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
    };
}


/**
 * Step 4: Instrument Fallback Selection (AJAX)
 */
function initInstrumentFallback() {
    const select = document.getElementById('instrument-fallback-select');
    if (!select || select.dataset.bound) return;
    select.dataset.bound = "1";

    select.addEventListener('change', function () {
        const name = this.value;
        const baseUrl = this.dataset.baseurl;
        if (!name || !baseUrl) return;

        const url = new URL(baseUrl);
        url.searchParams.set("sel_sug", name);
        url.searchParams.set("ajax", "1");

        // Use the global fetch pattern
        const main = document.getElementById("areteia-main");
        main.style.opacity = '0.5';

        fetch(url).then(r => r.text()).then(html => {
            main.innerHTML = html;
            main.style.opacity = '1';

            // Re-initialize all UI components
            initStep3Reactivity();
            initGenerativeLoading();
            initTreeCheckboxes();
            initRagSearchTest();
            initPromptPreview();
            initItemAdjustmentUI();
            initInstrumentFallback();
            initQuizWeightsAdjustment();

            // Update URL in history
            const finalUrl = new URL(url);
            finalUrl.searchParams.delete("ajax");
            window.history.pushState({}, "", finalUrl.toString());
        }).catch(err => {
            console.error(err);
            main.style.opacity = '1';
        });
    });
}

/**
 * Update absolute points labels (pts) based on current max grade and percentages.
 */
function updateQuizItemAbsPoints() {
    const maxGradeInput = document.getElementById('max_grade_input');
    if (!maxGradeInput) return;

    const maxGrade = parseFloat(maxGradeInput.value) || 0;
    const inputs = document.querySelectorAll('.quiz-item-points');

    inputs.forEach(input => {
        const pct = parseFloat(input.value) || 0;
        const abs = (pct / 100.0) * maxGrade;
        const label = document.querySelector(`.quiz-item-abs-points[data-idx="${input.dataset.idx}"]`);
        if (label) {
            label.innerText = `(${abs.toFixed(2)} pts)`;
        }
    });
}

/**
 * Step 7: Auto-adjust quiz weights to always sum up to 100%.
 * Respects manually modified inputs and prevents exceeding 100%.
 */
function initQuizWeightsAdjustment() {
    const inputs = Array.from(document.querySelectorAll('.quiz-item-points'));
    if (!inputs.length) return;

    // Initial calculation for absolute points
    updateQuizItemAbsPoints();

    inputs.forEach(input => {
        if (input.dataset.boundWeights) return;
        input.dataset.boundWeights = "1";
        input.dataset.locked = "0"; // 0 = auto-calculated, 1 = user manually set

        input.addEventListener('change', function () {
            let newVal = parseFloat(this.value);
            if (isNaN(newVal) || newVal < 0.1) newVal = 0.1;

            if (inputs.length <= 1) {
                if (newVal > 100) newVal = 100;
                this.value = newVal.toFixed(1);
                return;
            }

            const otherInputs = inputs.filter(i => i !== this);
            
            // Calculate sum of other LOCKED inputs
            let lockedSum = 0;
            let unlockedInputs = [];
            otherInputs.forEach(i => {
                if (i.dataset.locked === "1") {
                    lockedSum += parseFloat(i.value);
                } else {
                    unlockedInputs.push(i);
                }
            });

            // Prevent newVal from exceeding the available percentage
            let minRequiredForUnlocked = unlockedInputs.length * 0.1;
            let maxAllowed = 100.0 - lockedSum - minRequiredForUnlocked;
            
            if (newVal > maxAllowed) {
                newVal = maxAllowed;
                if (newVal < 0.1) newVal = 0.1;
                // Alerta suave para que entienda por qué se bajó su número
                console.warn(`Valor ajustado a ${newVal} para no superar el 100% total con los campos bloqueados.`);
            }

            this.value = newVal.toFixed(1);
            this.dataset.locked = "1";

            let remaining = 100.0 - newVal - lockedSum;
            if (remaining < 0) remaining = 0;

            // Si no quedan campos desbloqueados y el usuario BAJÓ el valor de este campo,
            // sobrará un porcentaje. En ese caso, debemos desbloquear el resto para que lo absorban.
            if (unlockedInputs.length === 0 && remaining > 0.05) {
                otherInputs.forEach(i => i.dataset.locked = "0");
                unlockedInputs = otherInputs;
            }

            const unlockedCount = unlockedInputs.length;
            if (unlockedCount > 0) {
                let distVal = Math.floor((remaining / unlockedCount) * 10) / 10;
                let sumDistributed = 0;

                unlockedInputs.forEach((unlocked, index) => {
                    if (index === unlockedCount - 1) {
                        // El último toma exactamente lo que falta
                        let lastVal = remaining - sumDistributed;
                        if (lastVal < 0.1) lastVal = 0.1;
                        unlocked.value = lastVal.toFixed(1);
                    } else {
                        unlocked.value = distVal.toFixed(1);
                        sumDistributed += distVal;
                    }
                });
            }
            
            // Re-calculate absolute points for all items
            updateQuizItemAbsPoints();
        });

        // Prevenir que la tecla Enter envíe el formulario por accidente
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.blur(); // Quita el foco para forzar el evento 'change'
            }
        });
    });

    // Also watch for max_grade changes to update absolute points
    const maxGradeInput = document.getElementById('max_grade_input');
    if (maxGradeInput && !maxGradeInput.dataset.bound) {
        maxGradeInput.dataset.bound = "1";
        maxGradeInput.addEventListener('input', updateQuizItemAbsPoints);
    }
}

/**
 * Step 5: Direct Question Editing UI
 */
function initItemDirectEditUI() {
    if (!document.datasetItemEditBound) {
        document.datasetItemEditBound = true;
        
        document.addEventListener("click", e => {
            const editBtn = e.target.closest(".item-edit-btn");
            if (editBtn) {
                const card = editBtn.closest(".item-card");
                if (card) {
                    card.classList.add("editing");
                }
                return;
            }

            const cancelBtn = e.target.closest(".item-cancel-btn");
            if (cancelBtn) {
                const card = cancelBtn.closest(".item-card");
                if (card) {
                    card.classList.remove("editing");
                    
                    const index = parseInt(cancelBtn.dataset.index);
                    const payloadInput = document.querySelector('input[name="src_data_payload"]');
                    if (payloadInput) {
                        try {
                            const data = JSON.parse(payloadInput.value);
                            const item = data.items[index];
                            if (item) {
                                resetEditFormValues(card, item, index);
                            }
                        } catch (err) {
                            console.error("Error parsing payload on cancel reset:", err);
                        }
                    }
                }
                return;
            }

            const saveBtn = e.target.closest(".item-save-btn");
            if (saveBtn) {
                const card = saveBtn.closest(".item-card");
                const index = parseInt(saveBtn.dataset.index);
                if (card && !isNaN(index)) {
                    saveItemEdits(card, index);
                }
                return;
            }
        });
    }
}

function resetEditFormValues(card, item, index) {
    const consignaTextarea = card.querySelector(".edit-item-consigna");
    const diffSelect = card.querySelector(".edit-item-difficulty");
    const ptsInput = card.querySelector(".edit-item-points");

    if (consignaTextarea) consignaTextarea.value = item.consigna || item.consiga || item.text || "";
    if (diffSelect) diffSelect.value = item.difficulty || "Media";
    if (ptsInput) ptsInput.value = item.points || 1.0;

    const type = (item.type || "").toLowerCase();

    if (type.includes("múltiple") || type.includes("selección") || type.includes("cerrada")) {
        const altInputs = card.querySelectorAll(".edit-item-alternativa");
        const altRadios = card.querySelectorAll(`input[name="edit_correct_index_${index}"]`);
        const alternativas = item.alternativas || [];
        const correctIdx = item.correct_index !== undefined ? parseInt(item.correct_index) : 0;

        altInputs.forEach((input, i) => {
            if (alternativas[i] !== undefined) input.value = alternativas[i];
        });
        altRadios.forEach((radio, i) => {
            radio.checked = (i === correctIdx);
        });
    } else if (type.includes("verdadero")) {
        const trueRadio = card.querySelector(`input[name="edit_correct_bool_${index}"][value="true"]`);
        const falseRadio = card.querySelector(`input[name="edit_correct_bool_${index}"][value="false"]`);
        const correctBool = item.correct_boolean !== undefined ? !!item.correct_boolean : true;

        if (trueRadio) trueRadio.checked = correctBool;
        if (falseRadio) falseRadio.checked = !correctBool;
    } else if (type.includes("match") || type.includes("emparejamiento") || type.includes("orden")) {
        const premiseInputs = card.querySelectorAll(".edit-item-pair-premise");
        const answerInputs = card.querySelectorAll(".edit-item-pair-answer");
        const pairs = item.pairs || [];

        premiseInputs.forEach((input, i) => {
            if (pairs[i]) input.value = pairs[i].premise || pairs[i].premisa || "";
        });
        answerInputs.forEach((input, i) => {
            if (pairs[i]) input.value = pairs[i].answer || pairs[i].respuesta || "";
        });
    } else {
        const answerInput = card.querySelector(".edit-item-correct-answer");
        if (answerInput) {
            answerInput.value = item.short_answer || item.cloze_answer || item.numerical_value || "";
        }
    }
}

function saveItemEdits(card, index) {
    const payloadInput = document.querySelector('input[name="src_data_payload"]');
    if (!payloadInput) return;

    try {
        const data = JSON.parse(payloadInput.value);
        const item = data.items[index];
        if (!item) return;

        const consignaTextarea = card.querySelector(".edit-item-consigna");
        const diffSelect = card.querySelector(".edit-item-difficulty");
        const ptsInput = card.querySelector(".edit-item-points");

        const newConsigna = consignaTextarea ? consignaTextarea.value.trim() : "";
        const newDifficulty = diffSelect ? diffSelect.value : "Media";
        const newPoints = ptsInput ? parseFloat(ptsInput.value) || 1.0 : 1.0;

        if (item.consigna !== undefined) item.consigna = newConsigna;
        if (item.consiga !== undefined) item.consiga = newConsigna;
        if (item.text !== undefined) item.text = newConsigna;
        
        item.difficulty = newDifficulty;
        item.points = newPoints;

        const type = (item.type || "").toLowerCase();

        if (type.includes("múltiple") || type.includes("selección") || type.includes("cerrada")) {
            const altInputs = Array.from(card.querySelectorAll(".edit-item-alternativa"));
            const correctRadio = card.querySelector(`input[name="edit_correct_index_${index}"]:checked`);
            
            item.alternativas = altInputs.map(input => input.value.trim());
            item.correct_index = correctRadio ? parseInt(correctRadio.value) : 0;
        } else if (type.includes("verdadero")) {
            const correctRadio = card.querySelector(`input[name="edit_correct_bool_${index}"]:checked`);
            item.correct_boolean = correctRadio ? (correctRadio.value === "true") : true;
        } else if (type.includes("match") || type.includes("emparejamiento") || type.includes("orden")) {
            const premiseInputs = Array.from(card.querySelectorAll(".edit-item-pair-premise"));
            const answerInputs = Array.from(card.querySelectorAll(".edit-item-pair-answer"));
            
            item.pairs = premiseInputs.map((input, i) => {
                return {
                    premise: input.value.trim(),
                    premisa: input.value.trim(),
                    answer: answerInputs[i] ? answerInputs[i].value.trim() : "",
                    respuesta: answerInputs[i] ? answerInputs[i].value.trim() : ""
                };
            });
        } else {
            const answerInput = card.querySelector(".edit-item-correct-answer");
            if (answerInput) {
                const val = answerInput.value.trim();
                if (item.short_answer !== undefined) item.short_answer = val;
                if (item.cloze_answer !== undefined) item.cloze_answer = val;
                if (item.numerical_value !== undefined) item.numerical_value = parseFloat(val) || 0.0;
            }
        }

        payloadInput.value = JSON.stringify(data);

        const bodyTextDiv = document.getElementById(`body-text-${index}`);
        if (bodyTextDiv) {
            if (type.includes("lacunar") || type.includes("cloze") || type === 'multianswer') {
                bodyTextDiv.innerHTML = renderLacunarPreviewClientSide(newConsigna, item.short_answer || item.cloze_answer || "");
            } else {
                bodyTextDiv.innerText = newConsigna;
            }
        }

        const diffBadge = document.getElementById(`badge-diff-${index}`);
        const ptsBadge = document.getElementById(`badge-pts-${index}`);
        if (diffBadge) diffBadge.innerText = newDifficulty;
        if (ptsBadge) ptsBadge.innerText = `${newPoints} pts`;

        const richContentDiv = document.getElementById(`rich-content-${index}`);
        if (richContentDiv) {
            if (type.includes("múltiple") || type.includes("selección") || type.includes("cerrada")) {
                let html = "";
                const correctIdx = item.correct_index !== undefined ? parseInt(item.correct_index) : -1;
                item.alternativas.forEach((opt, optIdx) => {
                    const isCorrect = (optIdx === correctIdx);
                    const optStyle = isCorrect ? 'background:#e6f4ea; border-color:#1e8e3e;' : '';
                    const icon = isCorrect
                        ? '<i style="font-style:normal; color:#1e8e3e; font-weight:bold;">✔</i>'
                        : '<i style="font-style:normal; opacity:0.5;">○</i>';
                    html += `
                        <div class="item-option" style="${optStyle}">
                            ${icon}
                            ${escapeHtml(opt)}
                        </div>
                    `;
                });
                richContentDiv.innerHTML = html;
            } else if (type.includes("match") || type.includes("emparejamiento") || type.includes("orden")) {
                let html = `
                    <table class="areteia-match-preview-table" style="width:100%; border-collapse:collapse; margin-top:10px;">
                        <thead>
                            <tr>
                                <th style="padding:8px; border:1px solid #ddd; background:#f5f7fb; text-align:left;">Premisa</th>
                                <th style="padding:8px; border:1px solid #ddd; background:#f5f7fb; text-align:left;">Respuesta</th>
                            </tr>
                        </thead>
                        <tbody>
                `;
                item.pairs.forEach((pair, pairIdx) => {
                    html += `
                        <tr>
                            <td style="padding:8px; border:1px solid #ddd; vertical-align:top;">${pairIdx + 1}. ${escapeHtml(pair.premise)}</td>
                            <td style="padding:8px; border:1px solid #ddd; vertical-align:top;">${String.fromCharCode(65 + pairIdx)}. ${escapeHtml(pair.answer)}</td>
                        </tr>
                    `;
                });
                html += `
                        </tbody>
                    </table>
                `;
                richContentDiv.innerHTML = html;
            } else if (type.includes("lacunar") || type.includes("cloze") || type === 'multianswer') {
                const realAnswer = item.short_answer || item.cloze_answer || "";
                if (shouldShowLacunarAnswerClientSide(realAnswer, newConsigna)) {
                    richContentDiv.innerHTML = `<div class="cloze-answer-preview-label" style="font-size:12px; color:#666; margin-top:8px;">Respuesta esperada: ${escapeHtml(realAnswer)}</div>`;
                } else {
                    richContentDiv.innerHTML = "";
                }
            } else if (type.includes("breve") || type.includes("clásica") || type === "shortanswer") {
                let html = "";
                if (item.short_answer) {
                    html += `<div class="shortanswer-answer-preview-label" style="font-size:12px; color:#1e8e3e; margin-top:8px; background:#e6f4ea; padding:8px 12px; border-radius:6px;">✅ Respuesta esperada: ${escapeHtml(item.short_answer)}</div>`;
                }
                richContentDiv.innerHTML = html;
            }
        }

        card.classList.remove("editing");

    } catch (err) {
        console.error("Error saving item edits:", err);
        alert("Ocurrió un error al guardar la edición.");
    }
}

function escapeHtml(str) {
    if (!str) return "";
    return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

function renderLacunarPreviewClientSide(text, answer) {
    const question = (text || "").trim();
    if (question === "") {
        return "<em>Texto lacunar sin consignas.</em>";
    }

    const blank = '<span style="display:inline-block; min-width:140px; border-bottom:1px solid #999; padding:0 4px; margin:0 2px; line-height:1.4;">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>';
    let escaped = escapeHtml(question);

    if (/_{3,}/u.test(question)) {
        return escaped.replace(/_{3,}/gu, blank);
    }
    if (/\[\s*(?:_+|\.{3,})\s*\]/u.test(question)) {
        return escaped.replace(/\[\s*(?:_+|\.{3,})\s*\]/gu, blank);
    }
    if (answer && question.toLowerCase().includes(answer.toLowerCase())) {
        const escapedAnswer = answer.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&');
        const pattern = new RegExp("(" + escapedAnswer + ")", "iu");
        return escaped.replace(pattern, blank);
    }
    return escaped + " " + blank;
}

function shouldShowLacunarAnswerClientSide(answer, question) {
    const ans = (answer || "").trim();
    const q = (question || "").trim();
    if (ans === "" || ans === q) return false;
    if (ans.length > q.length * 0.5) return false;
    if (ans.split(/\s+/).length > 8) return false;
    return true;
}

