const state = {
  view: "fmea",
  status: "",
  aktywne: "1",
  q: "",
  cpStatus: "",
  cpAktywne: "1",
  cpQ: "",
  currentId: null,
  reviewKind: "fmea",
  editingId: null,
  editingCp: null,
  editingUser: null,
  personKind: "cp",
  users: [],
  selectedFmea: new Set(),
  selectedCp: new Set(),
  purgeKind: "fmea",
  fmeaItems: [],
  cpItems: [],
  fmeaCols: {},
  cpCols: {},
  settings: {
    color_overdue: "#F8D0CB",
    color_warning: "#FFF2CC",
    color_verification: "#D4E4F7",
    warning_days: "30",
  },
};

const $ = (id) => document.getElementById(id);

function applyColors(settings) {
  state.settings = { ...state.settings, ...settings };
  const root = document.documentElement;
  root.style.setProperty("--row-overdue", state.settings.color_overdue);
  root.style.setProperty("--row-warning", state.settings.color_warning);
  root.style.setProperty("--row-verification", state.settings.color_verification);
}

function fmtDate(value) {
  if (!value) return "—";
  const [y, m, d] = String(value).slice(0, 10).split("-");
  if (!d) return value;
  return `${d}.${m}.${y}`;
}

function statusLabel(status) {
  return {
    przeterminowane: "Przeterminowane",
    wymaga_weryfikacji: "Wymaga weryfikacji",
    ostrzezenie: "Miesiąc do końca",
    aktualne: "Aktualne",
    brak_terminu: "Brak terminu",
  }[status] || status;
}

const APP_BASE = location.pathname === "/fmeareg" || location.pathname.startsWith("/fmeareg/") ? "/fmeareg" : "";

function appUrl(url) {
  const path = url.startsWith("/") ? url : `/${url}`;
  return `${APP_BASE}${path}`;
}

async function api(url, options) {
  const res = await fetch(appUrl(url), {
    headers: { "Content-Type": "application/json" },
    ...options,
  });
  const data = await res.json();
  if (!res.ok) throw new Error(data.error || "Błąd zapisu");
  return data;
}

function renderStats(summary) {
  $("stats").innerHTML = `
    <div class="stat"><b>${summary.total}</b><span>Pozycji na liście</span></div>
    <div class="stat bad"><b>${summary.przeterminowane}</b><span>Przeterminowane</span></div>
    <div class="stat warn"><b>${summary.ostrzezenie}</b><span>Miesiąc do końca</span></div>
    <div class="stat ok"><b>${summary.aktualne}</b><span>Aktualne</span></div>
    <div class="stat muted-stat"><b>${summary.nieaktywne ?? 0}</b><span>Nieaktywne (poza statystykami)</span></div>
  `;
}

function matchesCols(item, cols) {
  return Object.entries(cols).every(([key, value]) => {
    if (!value) return true;
    const raw = String(item[key] || "").toLowerCase();
    const shown = fmtDate(item[key]).toLowerCase();
    return raw.includes(value) || shown.includes(value);
  });
}

function changesHtml(changes) {
  const rows = (changes || [])
    .map(
      (change) => `
      <tr>
        <td>${fmtDate(change.created_at)}</td>
        <td>${change.field_label}</td>
        <td>${change.old_value || "—"}</td>
        <td>${change.new_value || "—"}</td>
      </tr>`
    )
    .join("");
  return `
    <section class="history">
      <h3>Historia zmian</h3>
      <table>
        <thead><tr><th>Kiedy</th><th>Pole</th><th>Było</th><th>Jest</th></tr></thead>
        <tbody>${rows || `<tr><td colspan="4" class="muted">Brak zmian opisów i statusów.</td></tr>`}</tbody>
      </table>
    </section>`;
}

function renderRows(items) {
  const body = $("rows");
  if (!items.length) {
    body.innerHTML = `<tr><td colspan="10" class="muted">Brak rekordów.</td></tr>`;
    return;
  }
  body.innerHTML = items
    .map(
      (item) => `
      <tr class="row ${item.aktywne ? `row-${item.status}` : "row-inactive"}" data-id="${item.id}">
        <td class="check"><input type="checkbox" data-fmea-check="${item.id}" ${state.selectedFmea.has(item.id) ? "checked" : ""} /></td>
        <td><strong>${item.nr_fmea}</strong></td>
        <td class="pn">${item.part_numbers || "—"}</td>
        <td>${item.projekt || "—"}</td>
        <td>${item.rodzaj_wyrobu || "—"}</td>
        <td>${item.osoba_wiodaca || "—"}</td>
        <td>${fmtDate(item.current_fmea_date)}</td>
        <td>${item.last_review_reason || "—"}</td>
        <td>${fmtDate(item.next_due_date)}<div class="muted">${item.aktywne ? statusLabel(item.status) : "Nieaktywne"}</div></td>
        <td><button type="button" class="btn small" data-review="${item.id}">Aktualizuj datę</button></td>
      </tr>`
    )
    .join("");
}

async function loadList() {
  const params = new URLSearchParams();
  if (state.q) params.set("q", state.q);
  if (state.status) params.set("status", state.status);
  if (state.aktywne) params.set("aktywne", state.aktywne);
  const data = await api(`/api/fmea?${params.toString()}`);
  state.fmeaItems = data.items;
  renderStats(data.summary);
  renderRows(state.fmeaItems.filter((item) => matchesCols(item, state.fmeaCols)));
  if (data.settings) applyColors(data.settings);
}

function typeLabel(type) {
  return type === "pelny" ? "Pełny" : "Zdarzeniowy";
}

async function openDrawer(id) {
  const data = await api(`/api/fmea/${id}`);
  state.currentId = id;
  const item = data.item;
  $("d-nr").textContent = `FMEA ${item.nr_fmea}`;
  $("d-title").textContent = item.projekt || item.rodzaj_wyrobu || "Szczegóły";
  const history = data.reviews
    .map(
      (r) => `
      <tr>
        <td>${fmtDate(r.review_date)}</td>
        <td class="type-${r.review_type}">${typeLabel(r.review_type)}</td>
        <td>${r.reason || "—"}</td>
        <td>${fmtDate(r.next_due_after)}</td>
      </tr>`
    )
    .join("");
  $("drawer-body").innerHTML = `
    <div class="kv">
      <div class="muted">Wyroby gotowe</div><div>${item.part_numbers || "—"}</div>
      <div class="muted">Projekt</div><div>${item.projekt || "—"}</div>
      <div class="muted">Rodzaj wyrobu</div><div>${item.rodzaj_wyrobu || "—"}</div>
      <div class="muted">Osoba wiodąca</div><div>${item.osoba_wiodaca || "—"}</div>
      <div class="muted">Data obecnego FMEA</div><div>${fmtDate(item.current_fmea_date)}</div>
      <div class="muted">Ostatni pełny FMEA</div><div>${fmtDate(item.last_full_date)}</div>
      <div class="muted">Termin kolejnej rewizji</div><div>${fmtDate(item.next_due_date)}</div>
      <div class="muted">Dni od pełnego FMEA</div><div>${item.days_since_full ?? "—"}</div>
      <div class="muted">Status</div><div><span class="pill ${item.aktywne ? item.status : "brak_terminu"}">${item.aktywne ? statusLabel(item.status) : "Nieaktywne"}</span></div>
      <div class="muted">Uwagi</div><div>${item.uwagi || "—"}</div>
    </div>
    <section class="history">
      <h3>Powiązane plany kontroli (${(data.control_plans || []).length})</h3>
      <table>
        <thead><tr><th>Nr planu</th><th>Symbol</th><th>Termin rewizji</th></tr></thead>
        <tbody>${
          (data.control_plans || []).map((plan) => `
            <tr>
              <td>${plan.nr_planu}</td>
              <td>${plan.symbol_wyrobu || "—"}</td>
              <td>${fmtDate(plan.next_due_date)}</td>
            </tr>`).join("") || `<tr><td colspan="3" class="muted">Brak powiązanych planów.</td></tr>`
        }</tbody>
      </table>
    </section>
    <div class="drawer-actions">
      <button type="button" class="btn" id="btn-add-review">Aktualizuj datę FMEA</button>
      <button type="button" class="btn ghost" id="btn-edit">Edytuj dane</button>
      <button type="button" class="btn ghost" id="btn-toggle-active">${item.aktywne ? "Dezaktywuj projekt" : "Aktywuj projekt"}</button>
      <button type="button" class="btn danger" id="btn-delete">Usuń bezpowrotnie</button>
    </div>
    <section class="history">
      <h3>Historia przeglądów</h3>
      <table>
        <thead>
          <tr>
            <th>Data</th>
            <th>Typ</th>
            <th>Powód</th>
            <th>Termin po zapisie</th>
          </tr>
        </thead>
        <tbody>${history || `<tr><td colspan="4" class="muted">Brak historii.</td></tr>`}</tbody>
      </table>
    </section>
    ${changesHtml(data.changes)}
  `;
  $("drawer").hidden = false;
  $("drawer-backdrop").hidden = false;
  $("btn-add-review").onclick = () => openReviewModal(id, "fmea");
  $("btn-edit").onclick = () => openEditModal(item);
  $("btn-toggle-active").onclick = async () => {
    await api(`/api/fmea/${id}/aktywne`, {
      method: "PUT",
      body: JSON.stringify({ aktywne: item.aktywne ? 0 : 1 }),
    });
    await loadList();
    await openDrawer(id);
  };
  $("btn-delete").onclick = () => openDeleteModal(item);
}

function openDeleteModal(item) {
  const nr = String(item.nr_fmea);
  if (!confirm(`Usunąć FMEA ${nr} wraz z całą historią przeglądów?\n\nTo działanie jest bezpowrotne.`)) {
    return;
  }
  state.currentId = item.id;
  const form = $("delete-form");
  form.reset();
  $("delete-hint").textContent = `Aby potwierdzić, wpisz numer FMEA: ${nr}`;
  form.dataset.nr = nr;
  $("delete-modal").hidden = false;
  form.confirm.focus();
}

function closeDeleteModal() {
  $("delete-modal").hidden = true;
}

function openPurgeModal(kind) {
  const phrase = kind === "fmea" ? "WYCZYSC FMEA" : "WYCZYSC PLANY";
  const label = kind === "fmea" ? "rejestr FMEA" : "rejestr planów kontroli";
  if (!confirm(`Wyczyścić ${label}?\n\nZnikną wszystkie pozycje i ich historia. Użytkownicy zostaną. Tego nie da się cofnąć.`)) {
    return;
  }
  state.purgeKind = kind;
  const form = $("purge-form");
  form.reset();
  form.dataset.phrase = phrase;
  $("purge-title").textContent = kind === "fmea" ? "Wyczyszczenie rejestru FMEA" : "Wyczyszczenie planów kontroli";
  $("purge-hint").textContent = `Aby potwierdzić, wpisz: ${phrase}`;
  $("purge-modal").hidden = false;
  form.confirm.focus();
}

function closeDrawer() {
  $("drawer").hidden = true;
  $("drawer-backdrop").hidden = true;
}

function openReviewModal(id, kind) {
  state.currentId = id;
  state.reviewKind = kind || "fmea";
  const form = $("review-form");
  form.querySelector("h3").textContent = state.reviewKind === "cp" ? "Aktualizacja daty planu kontroli" : "Aktualizacja daty FMEA";
  form.reset();
  form.review_date.value = new Date().toISOString().slice(0, 10);
  $("modal").hidden = false;
}

function closeReviewModal() {
  $("modal").hidden = true;
}

function addMonths(isoDate, months) {
  const [y, m, d] = isoDate.split("-").map(Number);
  const date = new Date(y, m - 1 + months, 1);
  const last = new Date(date.getFullYear(), date.getMonth() + 1, 0).getDate();
  date.setDate(Math.min(d, last));
  const mm = String(date.getMonth() + 1).padStart(2, "0");
  const dd = String(date.getDate()).padStart(2, "0");
  return `${date.getFullYear()}-${mm}-${dd}`;
}

function updateNextDuePreview() {
  const form = $("edit-form");
  const preview = $("next-due-preview");
  const value = form.fmea_date.value;
  if (!value) {
    preview.textContent = "Termin kolejnego przeglądu: —";
    return;
  }
  preview.textContent = `Termin kolejnego przeglądu: ${fmtDate(addMonths(value, 12))}`;
}

function openEditModal(item) {
  const form = $("edit-form");
  form.reset();
  const dateBlock = $("new-date-block");
  if (item) {
    state.editingId = item.id;
    $("edit-title").textContent = `Edycja FMEA ${item.nr_fmea}`;
    form.nr_fmea.value = item.nr_fmea || "";
    form.part_numbers.value = item.part_numbers || "";
    form.projekt.value = item.projekt || "";
    form.rodzaj_wyrobu.value = item.rodzaj_wyrobu || "";
    form.osoba_wiodaca.value = item.osoba_wiodaca || "";
    form.uwagi.value = item.uwagi || "";
    dateBlock.hidden = true;
    form.fmea_date.required = false;
  } else {
    state.editingId = null;
    $("edit-title").textContent = "Nowe FMEA";
    dateBlock.hidden = false;
    form.fmea_date.required = true;
    form.fmea_date.value = new Date().toISOString().slice(0, 10);
    updateNextDuePreview();
  }
  $("edit-modal").hidden = false;
}

function closeEditModal() {
  $("edit-modal").hidden = true;
}

$("search").addEventListener("input", (e) => {
  state.q = e.target.value.trim();
  loadList();
});

$("filters").addEventListener("click", (e) => {
  const btn = e.target.closest("button[data-aktywne], button[data-status]");
  if (!btn) return;
  state.status = btn.dataset.status || "";
  state.aktywne = btn.dataset.aktywne || "1";
  for (const chip of $("filters").querySelectorAll(".chip")) {
    chip.classList.toggle("active", chip === btn);
  }
  loadList();
});

$("rows").addEventListener("click", (e) => {
  const check = e.target.closest("[data-fmea-check]");
  if (check) {
    e.stopPropagation();
    const id = Number(check.dataset.fmeaCheck);
    if (check.checked) state.selectedFmea.add(id);
    else state.selectedFmea.delete(id);
    return;
  }
  const reviewBtn = e.target.closest("[data-review]");
  if (reviewBtn) {
    e.stopPropagation();
    openReviewModal(Number(reviewBtn.dataset.review), "fmea");
    return;
  }
  const row = e.target.closest("tr[data-id]");
  if (row) openDrawer(Number(row.dataset.id));
});

$("btn-close").onclick = closeDrawer;
$("drawer-backdrop").onclick = closeDrawer;
$("btn-cancel").onclick = closeReviewModal;
$("modal").addEventListener("click", (e) => {
  if (e.target.id === "modal") closeReviewModal();
});
$("btn-new").onclick = () => openEditModal(null);
$("btn-edit-cancel").onclick = closeEditModal;
$("edit-modal").addEventListener("click", (e) => {
  if (e.target.id === "edit-modal") closeEditModal();
});
$("edit-form").fmea_date.addEventListener("input", updateNextDuePreview);

$("review-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const form = e.target;
  try {
    if (!form.reason.value.trim()) {
      alert("Podaj powód rewizji.");
      return;
    }
    const kind = state.reviewKind;
    const data = await api(`/${kind === "cp" ? "api/cp" : "api/fmea"}/${state.currentId}/reviews`, {
      method: "POST",
      body: JSON.stringify({
        review_date: form.review_date.value,
        review_type: form.review_type.value,
        reason: form.reason.value,
        comment: form.comment.value,
      }),
    });
    closeReviewModal();
    if (kind === "fmea" && data.notifications) notifySummary(data.notifications);
    if (kind === "cp") {
      await loadCp();
      if (!$("drawer").hidden) await openCpDrawer(state.currentId);
    } else {
      await loadList();
      if (!$("drawer").hidden) await openDrawer(state.currentId);
    }
  } catch (err) {
    alert(err.message);
  }
});

$("edit-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const form = e.target;
  const payload = {
    nr_fmea: form.nr_fmea.value,
    part_numbers: form.part_numbers.value,
    projekt: form.projekt.value,
    rodzaj_wyrobu: form.rodzaj_wyrobu.value,
    osoba_wiodaca: form.osoba_wiodaca.value,
    uwagi: form.uwagi.value,
  };
  try {
    if (state.editingId) {
      await api(`/api/fmea/${state.editingId}`, {
        method: "PUT",
        body: JSON.stringify(payload),
      });
    } else {
      payload.fmea_date = form.fmea_date.value;
      payload.reason = form.reason.value;
      await api("/api/fmea", { method: "POST", body: JSON.stringify(payload) });
    }
    closeEditModal();
    await loadList();
    if (state.editingId && !$("drawer").hidden) await openDrawer(state.editingId);
  } catch (err) {
    alert(err.message);
  }
});

$("btn-export").onclick = () => {
  const params = new URLSearchParams();
  if (state.q) params.set("q", state.q);
  if (state.status) params.set("status", state.status);
  if (state.aktywne) params.set("aktywne", state.aktywne);
  window.location.href = appUrl(`/api/export?${params.toString()}`);
};

$("btn-import").onclick = () => $("fmea-file").click();
$("fmea-file").addEventListener("change", async () => {
  const file = $("fmea-file").files[0];
  $("fmea-file").value = "";
  if (!file) return;
  if (!confirm(`Nadpisać rejestr FMEA plikiem „${file.name}”?`)) return;
  const body = new FormData();
  body.append("file", file);
  try {
    const res = await fetch(appUrl("/api/import"), { method: "POST", body });
    const raw = await res.text();
    let result;
    try {
      result = JSON.parse(raw);
    } catch {
      throw new Error("Import nie powiódł się. Serwer zwrócił błąd zamiast danych.");
    }
    if (!res.ok) throw new Error(result.error || "Błąd importu");
    alert(`Zaimportowano ${result.fmea} FMEA i ${result.reviews} przeglądów. Nowi użytkownicy: ${result.users || 0}.`);
    closeDrawer();
    await loadList();
  } catch (err) {
    alert(err.message);
  }
});

function openSettingsModal() {
  const form = $("settings-form");
  form.color_overdue.value = state.settings.color_overdue.toLowerCase();
  form.color_warning.value = state.settings.color_warning.toLowerCase();
  form.color_verification.value = (state.settings.color_verification || "#D4E4F7").toLowerCase();
  form.warning_days.value = state.settings.warning_days;
  $("settings-modal").hidden = false;
}

$("btn-settings").onclick = openSettingsModal;
$("btn-settings-cancel").onclick = () => {
  $("settings-modal").hidden = true;
};
$("settings-modal").addEventListener("click", (e) => {
  if (e.target.id === "settings-modal") $("settings-modal").hidden = true;
});
$("settings-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const form = e.target;
  try {
    const saved = await api("/api/settings", {
      method: "PUT",
      body: JSON.stringify({
        color_overdue: form.color_overdue.value,
        color_warning: form.color_warning.value,
        color_verification: form.color_verification.value,
        warning_days: form.warning_days.value,
      }),
    });
    applyColors(saved);
    $("settings-modal").hidden = true;
    if (state.view === "cp") await loadCp();
    else await loadList();
  } catch (err) {
    alert(err.message);
  }
});

$("btn-fmea-purge").onclick = () => openPurgeModal("fmea");
$("btn-cp-purge").onclick = () => openPurgeModal("cp");
$("btn-purge-cancel").onclick = () => {
  $("purge-modal").hidden = true;
};
$("purge-modal").addEventListener("click", (e) => {
  if (e.target.id === "purge-modal") $("purge-modal").hidden = true;
});
$("purge-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const form = e.target;
  const phrase = form.dataset.phrase || "";
  const typed = form.confirm.value.trim();
  if (typed !== phrase) {
    alert(`Wpisz dokładnie: ${phrase}`);
    return;
  }
  try {
    await api(state.purgeKind === "fmea" ? "/api/fmea/wyczysc" : "/api/cp/wyczysc", {
      method: "POST",
      body: JSON.stringify({ confirm: typed }),
    });
    $("purge-modal").hidden = true;
    closeDrawer();
    if (state.purgeKind === "fmea") {
      state.selectedFmea.clear();
      $("fmea-check-all").checked = false;
      await loadList();
    } else {
      state.selectedCp.clear();
      $("cp-check-all").checked = false;
      await loadCp();
    }
  } catch (err) {
    alert(err.message);
  }
});

$("btn-delete-cancel").onclick = closeDeleteModal;
$("delete-modal").addEventListener("click", (e) => {
  if (e.target.id === "delete-modal") closeDeleteModal();
});
$("delete-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const form = e.target;
  const expected = form.dataset.nr || "";
  const typed = form.confirm.value.trim();
  if (typed !== expected) {
    alert(`Wpisz dokładnie numer FMEA: ${expected}`);
    return;
  }
  try {
    await api(`/api/fmea/${state.currentId}`, {
      method: "DELETE",
      body: JSON.stringify({ confirm: typed }),
    });
    closeDeleteModal();
    closeDrawer();
    await loadList();
  } catch (err) {
    alert(err.message);
  }
});

function renderCpStats(summary) {
  $("cp-stats").innerHTML = `
    <div class="stat"><b>${summary.total}</b><span>Aktywnych planów</span></div>
    <div class="stat bad"><b>${summary.przeterminowane}</b><span>Przeterminowane</span></div>
    <div class="stat verify"><b>${summary.wymaga_weryfikacji ?? 0}</b><span>Wymaga weryfikacji</span></div>
    <div class="stat warn"><b>${summary.ostrzezenie}</b><span>Miesiąc do końca</span></div>
    <div class="stat ok"><b>${summary.aktualne}</b><span>Aktualne</span></div>
    <div class="stat muted-stat"><b>${summary.nieaktywne ?? 0}</b><span>Serwis, poza statystykami</span></div>
  `;
}

function renderCpRows(items) {
  const body = $("cp-rows");
  if (!items.length) {
    body.innerHTML = `<tr><td colspan="11" class="muted">Brak planów kontroli.</td></tr>`;
    return;
  }
  body.innerHTML = items
    .map(
      (item) => `
      <tr class="row ${item.aktywne ? `row-${item.status}` : "row-inactive"}" data-cp="${item.id}">
        <td class="check"><input type="checkbox" data-cp-check="${item.id}" ${state.selectedCp.has(item.id) ? "checked" : ""} /></td>
        <td><strong>${item.nr_planu}</strong></td>
        <td>${item.nr_fmea ? `${item.nr_fmea}${item.fmea_linked ? "" : " <span class='muted'>(brak w rejestrze)</span>"}` : "—"}</td>
        <td>${item.osoba_wiodaca || "—"}</td>
        <td class="pn">${item.symbol_wyrobu || "—"}</td>
        <td>${item.model || "—"}</td>
        <td>${item.nr_rysunku || "—"}</td>
        <td>${item.linia_mes || "—"}</td>
        <td>${fmtDate(item.last_review_date)}</td>
        <td>${fmtDate(item.next_due_date)}<div class="muted">${item.aktywne ? statusLabel(item.status) : "Nieaktywny"}</div></td>
        <td><button type="button" class="btn small" data-cp-review="${item.id}">Aktualizuj datę</button></td>
      </tr>`
    )
    .join("");
}

async function loadCp() {
  const params = new URLSearchParams();
  if (state.cpQ) params.set("q", state.cpQ);
  if (state.cpStatus) params.set("status", state.cpStatus);
  if (state.cpAktywne) params.set("aktywne", state.cpAktywne);
  const data = await api(`/api/cp?${params.toString()}`);
  state.cpItems = data.items;
  renderCpStats(data.summary);
  renderCpRows(state.cpItems.filter((item) => matchesCols(item, state.cpCols)));
  if (data.settings) applyColors(data.settings);
}

async function openCpDrawer(id) {
  const data = await api(`/api/cp/${id}`);
  state.currentId = id;
  state.reviewKind = "cp";
  const item = data.item;
  $("d-nr").textContent = item.nr_planu;
  $("d-title").textContent = item.nazwa_wyrobu || item.symbol_wyrobu || "Plan kontroli";
  const history = data.reviews
    .map(
      (review) => `
      <tr>
        <td>${fmtDate(review.review_date)}</td>
        <td class="type-${review.review_type}">${typeLabel(review.review_type)}</td>
        <td>${review.reason || "—"}</td>
        <td>${fmtDate(review.next_due_after)}</td>
      </tr>`
    )
    .join("");
  $("drawer-body").innerHTML = `
    <div class="kv">
      <div class="muted">Plan kontroli</div><div>${item.nr_planu}</div>
      <div class="muted">Numer FMEA</div><div>${item.nr_fmea || "—"}${item.fmea_linked ? ` · ${item.fmea_projekt || ""}` : ""}</div>
      <div class="muted">Osoba wiodąca</div><div>${item.osoba_wiodaca || "—"}</div>
      <div class="muted">Symbol wyrobu</div><div>${item.symbol_wyrobu || "—"}</div>
      <div class="muted">Model samochodu</div><div>${item.model || "—"}</div>
      <div class="muted">Nazwa wyrobu</div><div>${item.nazwa_wyrobu || "—"}</div>
      <div class="muted">Data rewizji</div><div>${fmtDate(item.last_review_date)}</div>
      <div class="muted">Termin kolejnej rewizji</div><div>${fmtDate(item.next_due_date)}</div>
      <div class="muted">Status</div><div><span class="pill ${item.aktywne ? item.status : "brak_terminu"}">${item.aktywne ? statusLabel(item.status) : "Nieaktywny"}</span></div>
      <div class="muted">Status z pliku</div><div>${item.status_przegladu || "—"}</div>
      <div class="muted">Nazwa wyrobu</div><div>${item.nazwa_wyrobu || "—"}</div>
      <div class="muted">Nr zmiany</div><div>${item.nr_zmiany || "—"}</div>
      <div class="muted">Data zatwierdzenia</div><div>${fmtDate(item.data_zatwierdzenia)}</div>
      <div class="muted">Nr rewizji</div><div>${item.nr_rewizji || "—"}</div>
      <div class="muted">Uwagi</div><div>${item.uwagi || "—"}</div>
    </div>
    <div class="drawer-actions">
      ${item.wymaga_weryfikacji ? `<button type="button" class="btn" id="btn-cp-confirm">Potwierdź: dokument nie wymaga aktualizacji</button>` : ""}
      <button type="button" class="btn" id="btn-cp-review">Aktualizuj datę</button>
      <button type="button" class="btn ghost" id="btn-cp-edit">Edytuj dane</button>
    </div>
    <section class="history">
      <h3>Historia rewizji</h3>
      <table>
        <thead><tr><th>Data</th><th>Typ</th><th>Powód</th><th>Termin po zapisie</th></tr></thead>
        <tbody>${history || `<tr><td colspan="4" class="muted">Brak historii.</td></tr>`}</tbody>
      </table>
    </section>
    <section class="history">
      <h3>Data anulowania</h3>
      <p>${fmtDate(item.data_anulowania)}</p>
    </section>
    ${changesHtml(data.changes)}
  `;
  $("drawer").hidden = false;
  $("drawer-backdrop").hidden = false;
  $("btn-cp-review").onclick = () => openReviewModal(id, "cp");
  $("btn-cp-edit").onclick = () => openCpForm(item);
  const confirmBtn = $("btn-cp-confirm");
  if (confirmBtn) {
    confirmBtn.onclick = async () => {
      if (!confirm("Potwierdzasz, że plan kontroli nie wymaga aktualizacji po zmianie powiązanego FMEA?")) return;
      try {
        await api(`/api/cp/${id}/potwierdz-bez-zmian`, { method: "POST", body: "{}" });
        await openCpDrawer(id);
        await loadCp();
      } catch (err) {
        alert(err.message);
      }
    };
  }
}

function syncStickyHeaders() {
  for (const table of document.querySelectorAll(".table-wrap table")) {
    const head = table.querySelector("thead tr:first-child");
    if (head && head.offsetHeight) table.style.setProperty("--head-h", `${head.offsetHeight}px`);
  }
}
window.addEventListener("resize", syncStickyHeaders);

function showView(name) {
  state.view = name;
  $("view-fmea").hidden = name !== "fmea";
  $("view-cp").hidden = name !== "cp";
  $("view-users").hidden = name !== "users";
  for (const tab of document.querySelectorAll(".brandbar-tab")) {
    tab.classList.toggle("active", tab.dataset.view === name);
  }
  closeDrawer();
  syncStickyHeaders();
  if (name === "cp") loadCp().catch((err) => alert(err.message));
  if (name === "users") loadUsers().catch((err) => alert(err.message));
}

function notifySummary(items) {
  if (!items.length) return;
  const lines = items.map((item) => {
    if (item.status === "wyslane") return `${item.name}: wysłano na ${item.email}`;
    if (item.status === "brak_adresu") return `${item.name}: brak użytkownika z tym imieniem i nazwiskiem`;
    if (item.status === "brak_email") return `${item.name}: brak adresu e-mail`;
    return `${item.name}: zapisano do wysłania (${item.error || "oczekuje na serwer poczty"})`;
  });
  alert(`Powiadomienia o aktualizacji FMEA:\n${lines.join("\n")}`);
}

function userName(user) {
  return `${user.imie} ${user.nazwisko}`;
}

async function loadUsers() {
  const data = await api("/api/users");
  state.users = data.items;
  const hint = $("users-mail-hint");
  hint.textContent = data.mail.ready
    ? `Powiadomienia wychodzą z ${data.mail.from} przez ${data.mail.host}. E-mail i dział uzupełniasz przy użytkowniku.`
    : "Osoba z Excela (Imię Nazwisko albo M.Nazwisko) pojawia się tutaj automatycznie. E-mail i dział uzupełniasz ręcznie. Miesiąc przed terminem wychodzi jedna wiadomość, a po terminie przypomnienie co tydzień. Wysyłka ruszy po uzupełnieniu api/data/mail.json.";
  const body = $("user-rows");
  body.innerHTML = data.items.length
    ? data.items.map((user) => `
      <tr>
        <td>${user.imie}</td>
        <td>${user.nazwisko}</td>
        <td>${user.dzial || "—"}</td>
        <td>${user.email || "—"}</td>
        <td>
          <button type="button" class="btn small ghost" data-user-edit="${user.id}">Edytuj</button>
          <button type="button" class="btn small danger" data-user-delete="${user.id}">Usuń</button>
        </td>
      </tr>`).join("")
    : `<tr><td colspan="5" class="muted">Brak użytkowników. Pojawią się po wczytaniu Excela z osobą Imię Nazwisko albo M.Nazwisko.</td></tr>`;
}

function openUserForm(user) {
  const form = $("user-form");
  form.reset();
  state.editingUser = user ? user.id : null;
  $("user-form-title").textContent = user ? "Edycja użytkownika" : "Nowy użytkownik";
  if (user) {
    form.imie.value = user.imie;
    form.nazwisko.value = user.nazwisko;
    form.dzial.value = user.dzial || "";
    form.email.value = user.email || "";
  }
  $("user-modal").hidden = false;
}

async function openPersonModal(kind) {
  const selected = kind === "fmea" ? state.selectedFmea : state.selectedCp;
  if (!selected.size) {
    alert("Zaznacz pozycje, którym chcesz zmienić osobę odpowiedzialną.");
    return;
  }
  if (!state.users.length) {
    const data = await api("/api/users");
    state.users = data.items;
  }
  if (!state.users.length) {
    alert("Najpierw dodaj użytkowników na zakładce Użytkownicy.");
    return;
  }
  state.personKind = kind;
  const form = $("person-form");
  form.user_id.innerHTML = state.users.map((user) => `<option value="${user.id}">${userName(user)}${user.dzial ? ` · ${user.dzial}` : ""}${user.email ? ` · ${user.email}` : ""}</option>`).join("");
  $("person-title").textContent = kind === "fmea" ? "Osoba wiodąca FMEA" : "Odpowiedzialny QE planu kontroli";
  $("person-hint").textContent = `Zmiana obejmie zaznaczone pozycje: ${selected.size}.`;
  $("person-modal").hidden = false;
}

document.querySelector(".brandbar").addEventListener("click", (e) => {
  const tab = e.target.closest(".brandbar-tab");
  if (tab) showView(tab.dataset.view);
});

$("cp-search").addEventListener("input", (e) => {
  state.cpQ = e.target.value.trim();
  loadCp();
});

$("cp-filters").addEventListener("click", (e) => {
  const btn = e.target.closest("button[data-aktywne], button[data-status]");
  if (!btn) return;
  state.cpStatus = btn.dataset.status || "";
  state.cpAktywne = btn.dataset.aktywne || "1";
  for (const chip of $("cp-filters").querySelectorAll(".chip")) {
    chip.classList.toggle("active", chip === btn);
  }
  loadCp();
});

$("cp-rows").addEventListener("click", (e) => {
  const check = e.target.closest("[data-cp-check]");
  if (check) {
    e.stopPropagation();
    const id = Number(check.dataset.cpCheck);
    if (check.checked) state.selectedCp.add(id);
    else state.selectedCp.delete(id);
    return;
  }
  const reviewBtn = e.target.closest("[data-cp-review]");
  if (reviewBtn) {
    e.stopPropagation();
    openReviewModal(Number(reviewBtn.dataset.cpReview), "cp");
    return;
  }
  const row = e.target.closest("tr[data-cp]");
  if (row) openCpDrawer(Number(row.dataset.cp));
});

function bindColumnFilters(selector, attr, target) {
  for (const input of document.querySelectorAll(selector)) {
    input.addEventListener("input", () => {
      target[input.dataset[attr]] = input.value.trim().toLowerCase();
      if (attr === "fmeaCol") {
        renderRows(state.fmeaItems.filter((item) => matchesCols(item, state.fmeaCols)));
      } else {
        renderCpRows(state.cpItems.filter((item) => matchesCols(item, state.cpCols)));
      }
    });
  }
}
bindColumnFilters("[data-fmea-col]", "fmeaCol", state.fmeaCols);
bindColumnFilters("[data-cp-col]", "cpCol", state.cpCols);

function updateCpDuePreview() {
  const value = $("cp-form").data_rewizji.value;
  $("cp-due-preview").textContent = value
    ? `Termin kolejnej rewizji: ${fmtDate(addMonths(value, 12))}`
    : "Termin kolejnej rewizji: —";
}

function openCpForm(item) {
  const form = $("cp-form");
  form.reset();
  const creating = !item;
  state.editingCp = item ? item.id : null;
  $("cp-form-title").textContent = creating ? "Nowy plan kontroli" : `Edycja ${item.nr_planu}`;
  form.reason.required = creating;
  form.data_rewizji.required = creating;
  form.reason.closest("label").hidden = !creating;
  form.data_rewizji.closest("label").hidden = !creating;
  $("cp-due-preview").hidden = !creating;
  if (item) {
    form.nr_planu.value = item.nr_planu || "";
    form.nr_fmea.value = item.nr_fmea || "";
    form.qe.value = item.qe || item.osoba_wiodaca || "";
    form.symbol_wyrobu.value = item.symbol_wyrobu || "";
    form.model.value = item.model || "";
    form.nr_rysunku.value = item.nr_rysunku || "";
    form.linia_mes.value = item.linia_mes || "";
    form.status_przegladu.value = item.status_przegladu || "OK";
    form.nazwa_wyrobu.value = item.nazwa_wyrobu || "";
    form.nr_zmiany.value = item.nr_zmiany || "";
    form.data_zatwierdzenia.value = (item.data_zatwierdzenia || "").slice(0, 10);
    form.nr_rewizji.value = item.nr_rewizji || "";
    form.data_anulowania.value = (item.data_anulowania || "").slice(0, 10);
    form.uwagi.value = item.uwagi || "";
  } else {
    form.data_rewizji.value = new Date().toISOString().slice(0, 10);
    form.status_przegladu.value = "OK";
    updateCpDuePreview();
  }
  $("cp-modal").hidden = false;
}

$("btn-cp-new").onclick = () => openCpForm(null);
$("btn-cp-cancel").onclick = () => {
  $("cp-modal").hidden = true;
};
$("cp-modal").addEventListener("click", (e) => {
  if (e.target.id === "cp-modal") $("cp-modal").hidden = true;
});
$("cp-form").data_rewizji.addEventListener("input", updateCpDuePreview);
$("cp-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const form = e.target;
  const payload = {
    nr_planu: form.nr_planu.value,
    nr_fmea: form.nr_fmea.value,
    qe: form.qe.value,
    symbol_wyrobu: form.symbol_wyrobu.value,
    model: form.model.value,
    nr_rysunku: form.nr_rysunku.value,
    linia_mes: form.linia_mes.value,
    status_przegladu: form.status_przegladu.value,
    nazwa_wyrobu: form.nazwa_wyrobu.value,
    nr_zmiany: form.nr_zmiany.value,
    data_zatwierdzenia: form.data_zatwierdzenia.value,
    nr_rewizji: form.nr_rewizji.value,
    data_anulowania: form.data_anulowania.value,
    uwagi: form.uwagi.value,
  };
  try {
    if (state.editingCp) {
      await api(`/api/cp/${state.editingCp}`, { method: "PUT", body: JSON.stringify(payload) });
    } else {
      if (!form.reason.value.trim()) {
        alert("Podaj powód rewizji.");
        return;
      }
      payload.data_rewizji = form.data_rewizji.value;
      payload.reason = form.reason.value;
      await api("/api/cp", { method: "POST", body: JSON.stringify(payload) });
    }
    $("cp-modal").hidden = true;
    await loadCp();
    if (state.editingCp && !$("drawer").hidden) await openCpDrawer(state.editingCp);
  } catch (err) {
    alert(err.message);
  }
});

$("btn-cp-settings").onclick = openSettingsModal;
$("btn-cp-export").onclick = () => {
  const params = new URLSearchParams();
  if (state.cpQ) params.set("q", state.cpQ);
  if (state.cpStatus) params.set("status", state.cpStatus);
  if (state.cpAktywne) params.set("aktywne", state.cpAktywne);
  window.location.href = appUrl(`/api/cp/export?${params.toString()}`);
};

$("btn-cp-import").onclick = () => $("cp-file").click();
$("cp-file").addEventListener("change", async () => {
  const file = $("cp-file").files[0];
  $("cp-file").value = "";
  if (!file) return;
  if (!confirm(`Nadpisać rejestr planów kontroli plikiem „${file.name}”?`)) return;
  const body = new FormData();
  body.append("file", file);
  try {
    const res = await fetch(appUrl("/api/cp/import"), { method: "POST", body });
    const raw = await res.text();
    let result;
    try {
      result = JSON.parse(raw);
    } catch {
      throw new Error("Import nie powiódł się. Serwer zwrócił błąd zamiast danych.");
    }
    if (!res.ok) throw new Error(result.error || "Błąd importu");
    alert(`Zaimportowano ${result.plans} planów, powiązanych z FMEA: ${result.linked}, nieaktywnych: ${result.inactive}. Nowi użytkownicy: ${result.users || 0}.`);
    closeDrawer();
    await loadCp();
  } catch (err) {
    alert(err.message);
  }
});

function visibleIds(selector) {
  return [...document.querySelectorAll(selector)].map((input) => Number(input.dataset.fmeaCheck || input.dataset.cpCheck));
}

$("fmea-check-all").addEventListener("change", (e) => {
  for (const id of visibleIds("[data-fmea-check]")) {
    if (e.target.checked) state.selectedFmea.add(id);
    else state.selectedFmea.delete(id);
  }
  renderRows(state.fmeaItems.filter((item) => matchesCols(item, state.fmeaCols)));
});
$("cp-check-all").addEventListener("change", (e) => {
  for (const id of visibleIds("[data-cp-check]")) {
    if (e.target.checked) state.selectedCp.add(id);
    else state.selectedCp.delete(id);
  }
  renderCpRows(state.cpItems.filter((item) => matchesCols(item, state.cpCols)));
});
$("btn-fmea-person").onclick = () => openPersonModal("fmea").catch((err) => alert(err.message));
$("btn-cp-person").onclick = () => openPersonModal("cp").catch((err) => alert(err.message));
$("btn-person-cancel").onclick = () => {
  $("person-modal").hidden = true;
};
$("person-modal").addEventListener("click", (e) => {
  if (e.target.id === "person-modal") $("person-modal").hidden = true;
});
$("person-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const selected = state.personKind === "fmea" ? state.selectedFmea : state.selectedCp;
  try {
    const result = await api(state.personKind === "fmea" ? "/api/fmea/osoba" : "/api/cp/osoba", {
      method: "POST",
      body: JSON.stringify({ ids: [...selected], user_id: Number(e.target.user_id.value) }),
    });
    $("person-modal").hidden = true;
    selected.clear();
    if (state.personKind === "fmea") {
      $("fmea-check-all").checked = false;
      await loadList();
    } else {
      $("cp-check-all").checked = false;
      await loadCp();
    }
    alert(`Zmieniono osobę odpowiedzialną na ${result.name} w ${result.updated} pozycjach.`);
  } catch (err) {
    alert(err.message);
  }
});

$("btn-user-new").onclick = () => openUserForm(null);
$("btn-user-cancel").onclick = () => {
  $("user-modal").hidden = true;
};
$("user-modal").addEventListener("click", (e) => {
  if (e.target.id === "user-modal") $("user-modal").hidden = true;
});
$("user-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const form = e.target;
  const payload = { imie: form.imie.value, nazwisko: form.nazwisko.value, email: form.email.value, dzial: form.dzial.value };
  try {
    if (state.editingUser) {
      await api(`/api/users/${state.editingUser}`, { method: "PUT", body: JSON.stringify(payload) });
    } else {
      await api("/api/users", { method: "POST", body: JSON.stringify(payload) });
    }
    $("user-modal").hidden = true;
    await loadUsers();
  } catch (err) {
    alert(err.message);
  }
});
$("user-rows").addEventListener("click", async (e) => {
  const edit = e.target.closest("[data-user-edit]");
  const remove = e.target.closest("[data-user-delete]");
  if (edit) {
    openUserForm(state.users.find((user) => user.id === Number(edit.dataset.userEdit)));
  }
  if (remove) {
    const user = state.users.find((item) => item.id === Number(remove.dataset.userDelete));
    if (!user || !confirm(`Usunąć użytkownika ${userName(user)}?`)) return;
    try {
      await api(`/api/users/${user.id}`, { method: "DELETE" });
      await loadUsers();
    } catch (err) {
      alert(err.message);
    }
  }
});

loadList()
  .then(syncStickyHeaders)
  .catch((err) => {
    $("rows").innerHTML = `<tr><td colspan="10">${err.message}</td></tr>`;
  });
